<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;
use App\Services\MercadoPagoService;
use App\Services\PagamentoReconciliacaoService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PagamentoController extends Controller
{
    public function __construct(
        private readonly MercadoPagoService $mercadoPago,
        private readonly PagamentoReconciliacaoService $reconciliacao,
    ) {}

    /**
     * Chave pública do Mercado Pago — segura para expor ao frontend (usada
     * para tokenizar o pagamento no navegador) — e o CPF já cadastrado do
     * admin da oficina, pra pré-preencher o formulário em vez de pedir
     * digitado de novo.
     */
    public function chavePublicaMercadoPago(): JsonResponse
    {
        $chave = SaasConfig::get()->getRawOriginal('mp_public_key');

        if (!$chave) {
            return response()->json(['message' => 'Mercado Pago não está configurado nesta plataforma.'], 422);
        }

        $oficina = Oficina::find(TenancyContext::get());

        return response()->json([
            'public_key'  => $chave,
            'cpf_titular' => $oficina?->admin_cpf,
        ]);
    }

    /**
     * Recebe o formData do Payment Brick (cartão tokenizado ou PIX) e processa
     * o pagamento direto via API do Mercado Pago — sem redirecionar o usuário
     * para uma página externa. Valor, descrição e referência externa vêm
     * sempre do servidor.
     */
    public function pagarMercadoPago(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cobranca_id'          => 'required|uuid',
            'token'                => 'nullable|string',
            'issuer_id'            => 'nullable|string',
            'payment_method_id'    => 'required|string',
            'installments'         => 'nullable|integer|min:1',
            'payer'                => 'required|array',
            'payer.email'          => 'required|email',
        ]);

        $oficinaId = TenancyContext::get();

        $cobranca = Cobranca::where('id', $validated['cobranca_id'])
            ->where('oficina_id', $oficinaId)
            ->first();

        if (!$cobranca) {
            return response()->json(['message' => 'Fatura não encontrada.'], 404);
        }

        if (!in_array($cobranca->status, ['PENDENTE', 'VENCIDA'], true)) {
            return response()->json(['message' => 'Esta fatura não está mais em aberto.'], 422);
        }

        if ($cobranca->gateway !== 'MERCADOPAGO') {
            return response()->json(['message' => 'Esta fatura não é do Mercado Pago.'], 422);
        }

        $descricao = $cobranca->descricao ?: ($cobranca->tipo === 'ASSINATURA' ? 'Mensalidade MecânicaPro' : 'Cobrança avulsa MecânicaPro');

        try {
            $pagamento = $this->mercadoPago->criarPagamento(
                $validated,
                (float) $cobranca->valor,
                $descricao,
                $cobranca->id,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Falha ao processar pagamento: ' . $e->getMessage()], 502);
        }

        $status = $pagamento['status'] ?? 'unknown';

        if (!empty($pagamento['id'])) {
            $cobranca->update(['mp_payment_id' => (string) $pagamento['id']]);
        }

        if ($status === 'approved') {
            $this->reconciliacao->confirmarPagamento($cobranca);
        }

        $resposta = [
            'status'        => $status,
            'status_detail' => $pagamento['status_detail'] ?? null,
        ];

        if (($validated['payment_method_id'] ?? null) === 'pix') {
            $poi = $pagamento['point_of_interaction']['transaction_data'] ?? [];
            $resposta['qr_code']        = $poi['qr_code'] ?? null;
            $resposta['qr_code_base64'] = $poi['qr_code_base64'] ?? null;
        }

        return response()->json($resposta);
    }

    /**
     * Consulta o status atual da fatura — usado pelo frontend (polling) pra
     * checar se um PIX pendente já foi pago. Não depende só do webhook ter
     * chegado: se ainda não está PAGA localmente e há um payment_id, consulta
     * a API do gateway direto e concilia na hora — o webhook pode atrasar,
     * falhar ou nunca ter sido configurado corretamente, mas essa checagem
     * ativa garante que o status não fique preso em PENDENTE indefinidamente
     * enquanto o usuário está com a tela aberta esperando.
     */
    public function statusFatura(string $id): JsonResponse
    {
        $oficinaId = TenancyContext::get();
        $cobranca = Cobranca::where('id', $id)->where('oficina_id', $oficinaId)->first();

        if (!$cobranca) {
            return response()->json(['message' => 'Fatura não encontrada.'], 404);
        }

        if ($cobranca->status !== 'PAGA' && $cobranca->mp_payment_id) {
            try {
                $pagamento = $this->mercadoPago->buscarPagamento($cobranca->mp_payment_id);
                if (($pagamento['status'] ?? null) === 'approved') {
                    $this->reconciliacao->confirmarPagamento($cobranca);
                    $cobranca->refresh();
                }
            } catch (\Throwable) {
                // Silencioso — o polling tenta de novo no próximo tick.
            }
        }

        return response()->json([
            'status'  => $cobranca->status,
            'pago_em' => $cobranca->pago_em?->toIso8601String(),
        ]);
    }
}
