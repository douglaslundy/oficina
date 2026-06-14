<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Cobranca;
use App\Models\Oficina;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
