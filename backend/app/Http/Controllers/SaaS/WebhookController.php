<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class WebhookController extends Controller
{
    public function asaas(Request $request): JsonResponse
    {
        // Validate webhook token
        $token = $request->header('asaas-access-token');
        if ($token !== config('services.asaas.webhook_token')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event   = $request->input('event');
        $payment = $request->input('payment', []);

        match ($event) {
            'PAYMENT_CONFIRMED'                          => $this->handlePaymentConfirmed($payment),
            'PAYMENT_OVERDUE'                            => $this->handlePaymentOverdue($payment),
            'PAYMENT_DELETED', 'SUBSCRIPTION_DELETED'   => $this->handleCanceled($payment),
            default                                      => null,
        };

        return response()->json(['received' => true]);
    }

    private function handlePaymentConfirmed(array $payment): void
    {
        $oficina = $this->findOficinaBySubscription($payment['subscription'] ?? null);
        if (!$oficina) return;

        $oficina->update(['status' => 'ATIVA']);

        Cobranca::create([
            'oficina_id'       => $oficina->id,
            'mes_referencia'   => now()->startOfMonth(),
            'valor'            => $payment['value'] ?? 0,
            'status'           => 'PAGA',
            'asaas_payment_id' => $payment['id'] ?? null,
            'vencimento'       => $payment['dueDate'] ?? null,
            'pago_em'          => now(),
        ]);
    }

    private function handlePaymentOverdue(array $payment): void
    {
        $oficina = $this->findOficinaBySubscription($payment['subscription'] ?? null);
        if (!$oficina) return;

        $oficina->update(['status' => 'INADIMPLENTE']);

        // Alert email
        try {
            Mail::raw(
                "A oficina {$oficina->nome} está inadimplente. Pagamento vencido.",
                fn($m) => $m->to($oficina->admin_email ?? config('mail.from.address'))
                             ->subject("MecânicaPro — Pagamento vencido: {$oficina->nome}")
            );
        } catch (\Throwable) {}
    }

    private function handleCanceled(array $payment): void
    {
        $oficina = $this->findOficinaBySubscription($payment['subscription'] ?? null);
        if ($oficina) {
            $oficina->update(['status' => 'CANCELADA']);
        }
    }

    private function findOficinaBySubscription(?string $subscriptionId): ?Oficina
    {
        if (!$subscriptionId) return null;
        return Oficina::where('asaas_subscription_id', $subscriptionId)->first();
    }

    // ─── Mercado Pago ─────────────────────────────────────────────────────────

    public function mercadopago(Request $request): JsonResponse
    {
        $cfg    = SaasConfig::get();
        $secret = $cfg->getRawOriginal('mp_webhook_secret') ?? '';

        // Validar assinatura HMAC-SHA256
        $xSignature = $request->header('x-signature', '');
        $xRequestId = $request->header('x-request-id', '');
        $dataId     = $request->query('data_id', '');

        if ($secret && $xSignature) {
            $manifest = "id:{$dataId};request-id:{$xRequestId};ts:" . $this->extractTs($xSignature) . ';';
            $expected = hash_hmac('sha256', $manifest, $secret);
            $received = $this->extractV1($xSignature);

            if (!hash_equals($expected, $received)) {
                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        $type   = $request->input('type', '');
        $action = $request->input('action', '');

        if ($type !== 'subscription_preapproval') {
            return response()->json(['received' => true]);
        }

        $subscriptionId = $request->input('data.id');
        if (!$subscriptionId) return response()->json(['received' => true]);

        // Buscar dados atualizados no MP
        $cfg = SaasConfig::get();
        $mpToken = $cfg->getRawOriginal('mp_access_token') ?? '';
        $mpData  = Http::withToken($mpToken)
            ->get("https://api.mercadopago.com/preapproval/{$subscriptionId}")
            ->json();

        $mpStatus = $mpData['status'] ?? null;
        $oficina  = Oficina::where('mp_subscription_id', $subscriptionId)->first();

        if (!$oficina || !$mpStatus) return response()->json(['received' => true]);

        match ($mpStatus) {
            'authorized' => $this->mpHandleAuthorized($oficina, $mpData),
            'paused'     => $oficina->update(['status' => 'INADIMPLENTE']),
            'cancelled'  => $oficina->update(['status' => 'CANCELADA']),
            default      => null,
        };

        return response()->json(['received' => true]);
    }

    private function mpHandleAuthorized(Oficina $oficina, array $data): void
    {
        $oficina->update(['status' => 'ATIVA']);

        Cobranca::create([
            'oficina_id'    => $oficina->id,
            'mes_referencia'=> now()->startOfMonth(),
            'valor'         => $data['auto_recurring']['transaction_amount'] ?? 0,
            'status'        => 'PAGA',
            'gateway'       => 'MERCADOPAGO',
            'mp_payment_id' => $data['id'] ?? null,
            'vencimento'    => now()->toDateString(),
            'pago_em'       => now(),
        ]);
    }

    private function extractTs(string $signature): string
    {
        // Format: ts=<timestamp>,v1=<hash>
        preg_match('/ts=(\d+)/', $signature, $m);
        return $m[1] ?? '';
    }

    private function extractV1(string $signature): string
    {
        preg_match('/v1=([a-f0-9]+)/', $signature, $m);
        return $m[1] ?? '';
    }
}
