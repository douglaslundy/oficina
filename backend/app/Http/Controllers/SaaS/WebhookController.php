<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;
use App\Services\PagamentoReconciliacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class WebhookController extends Controller
{
    public function __construct(private readonly PagamentoReconciliacaoService $reconciliacao) {}

    public function asaas(Request $request): JsonResponse
    {
        // Falha fechado: se o token não estiver configurado, NUNCA autentica —
        // antes, token vazio no .env + header vazio/ausente resultavam em
        // string vazia === string vazia, aceitando qualquer requisição sem
        // autenticação nenhuma. hash_equals() evita também comparação
        // vulnerável a timing attack.
        $configurado = (string) config('services.asaas.webhook_token', '');
        $recebido    = (string) $request->header('asaas-access-token', '');

        if ($configurado === '' || !hash_equals($configurado, $recebido)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event   = $request->input('event');
        $payment = $request->input('payment', []);

        match ($event) {
            'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED' => $this->reconciliarPagamento('asaas_payment_id', $payment['id'] ?? null),
            'PAYMENT_OVERDUE'                       => $this->handlePaymentOverdue($payment),
            default                                  => null,
        };

        return response()->json(['received' => true]);
    }

    private function handlePaymentOverdue(array $payment): void
    {
        $cobranca = Cobranca::where('asaas_payment_id', $payment['id'] ?? null)->first();
        if (!$cobranca) return;

        $cobranca->update(['status' => 'VENCIDA']);

        $oficina = Oficina::find($cobranca->oficina_id);
        if (!$oficina) return;

        $oficina->update(['status' => 'INADIMPLENTE']);

        try {
            Mail::raw(
                "A oficina {$oficina->nome} está inadimplente. Pagamento vencido.",
                fn($m) => $m->to($oficina->admin_email ?? config('mail.from.address'))
                             ->subject("MecânicaPro — Pagamento vencido: {$oficina->nome}")
            );
        } catch (\Throwable) {}
    }

    // ─── Mercado Pago ─────────────────────────────────────────────────────────

    public function mercadopago(Request $request): JsonResponse
    {
        $cfg    = SaasConfig::get();
        $secret = $cfg->getRawOriginal('mp_webhook_secret') ?? '';

        $xSignature = $request->header('x-signature', '');
        $xRequestId = $request->header('x-request-id', '');
        $dataId     = $request->query('data_id', '');

        // Falha fechado: sem segredo configurado OU sem header de assinatura,
        // rejeita — antes, a checagem só rodava se AMBOS estivessem presentes,
        // então bastava omitir o header x-signature pra pular a verificação
        // inteira mesmo com o segredo configurado.
        if ($secret === '' || $xSignature === '') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:" . $this->extractTs($xSignature) . ';';
        $expected = hash_hmac('sha256', $manifest, $secret);
        $received = $this->extractV1($xSignature);

        if (!hash_equals($expected, $received)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $type = $request->input('type', '');
        if ($type !== 'payment') {
            return response()->json(['received' => true]);
        }

        $paymentId = $request->input('data.id');
        if (!$paymentId) return response()->json(['received' => true]);

        $mpToken  = $cfg->getRawOriginal('mp_access_token') ?? '';
        $mpData   = Http::withToken($mpToken)->get("https://api.mercadopago.com/v1/payments/{$paymentId}")->json();
        $mpStatus = $mpData['status'] ?? null;

        if ($mpStatus === 'approved') {
            $cobrancaId = $mpData['external_reference'] ?? null;
            $cobranca   = $cobrancaId ? Cobranca::find($cobrancaId) : null;

            if ($cobranca) {
                $cobranca->update(['mp_payment_id' => $paymentId]);
                $this->reconciliarPagamento('mp_payment_id', $paymentId);
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * Marca a Cobranca (localizada pelo id de pagamento do gateway) como PAGA.
     * Ignora cobrancas ja PAGA ou CANCELADA (esta ultima pode ter sido cancelada
     * localmente em mudarCiclo() mesmo com o boleto remoto ainda em aberto).
     * So avanca o vencimento e reativa a oficina quando a cobranca for do tipo
     * ASSINATURA — uma cobranca AVULSA nao deve conceder tempo de assinatura
     * nem reativar uma oficina suspensa por outro motivo.
     */
    private function reconciliarPagamento(string $campoPaymentId, ?string $paymentId): void
    {
        if (!$paymentId) return;

        $cobranca = Cobranca::where($campoPaymentId, $paymentId)->first();
        if (!$cobranca) return;

        $this->reconciliacao->confirmarPagamento($cobranca);
    }

    private function extractTs(string $signature): string
    {
        preg_match('/ts=(\d+)/', $signature, $m);
        return $m[1] ?? '';
    }

    private function extractV1(string $signature): string
    {
        preg_match('/v1=([a-f0-9]+)/', $signature, $m);
        return $m[1] ?? '';
    }
}
