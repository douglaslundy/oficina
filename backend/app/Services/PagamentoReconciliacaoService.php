<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SuperAdmin;
use Illuminate\Support\Facades\Log;

/**
 * Lógica de confirmação de pagamento compartilhada entre o webhook dos
 * gateways (assíncrono) e o checkout transparente (síncrono, pagamento
 * aprovado na hora). Idempotente — pode ser chamada mais de uma vez para a
 * mesma cobrança sem duplicar efeitos.
 */
class PagamentoReconciliacaoService
{
    public function __construct(private readonly EmailService $emailService) {}

    public function confirmarPagamento(Cobranca $cobranca): void
    {
        if (in_array($cobranca->status, ['PAGA', 'CANCELADA'], true)) return;

        $cobranca->update(['status' => 'PAGA', 'pago_em' => now()]);

        $oficina = Oficina::find($cobranca->oficina_id);
        if ($oficina) {
            $this->notificarAdminPagamento($cobranca, $oficina);
        }

        if ($cobranca->tipo !== 'ASSINATURA') return;
        if (!$oficina) return;

        if ($oficina->proximo_vencimento) {
            $oficina->avancarVencimento();
        }

        $updates = [];
        if ($oficina->status !== 'ATIVA') {
            $updates['status'] = 'ATIVA';
        }
        if ($oficina->voto_confianca_ate !== null) {
            $updates['voto_confianca_ate'] = null;
        }
        if (!empty($updates)) {
            $oficina->update($updates);
        }
    }

    /**
     * Notifica por e-mail todos os super_admins quando uma cobrança
     * (assinatura ou avulsa) é confirmada como paga. Silencioso se SMTP não
     * estiver configurado ou se o envio falhar.
     */
    private function notificarAdminPagamento(Cobranca $cobranca, Oficina $oficina): void
    {
        try {
            if (!$this->emailService->configurado()) return;

            $emails = SuperAdmin::query()->pluck('email')->filter()->values()->all();
            if (empty($emails)) return;

            $tipoLabel = $cobranca->tipo === 'ASSINATURA' ? 'Mensalidade/Anuidade' : 'Cobrança avulsa';
            $gatewayLabel = $cobranca->gateway === 'MERCADOPAGO' ? 'Mercado Pago' : 'Asaas';

            $corpo = "A oficina {$oficina->nome} pagou uma fatura.\n\n"
                . "Tipo: {$tipoLabel}\n"
                . "Valor: R$ " . number_format((float) $cobranca->valor, 2, ',', '.') . "\n"
                . "Vencimento: " . ($cobranca->vencimento?->format('d/m/Y') ?? '-') . "\n"
                . 'Pago em: ' . now()->format('d/m/Y H:i') . "\n"
                . "Gateway: {$gatewayLabel}";

            $this->emailService->enviar($emails, "MecânicaPro — Pagamento recebido: {$oficina->nome}", $corpo);
        } catch (\Throwable $e) {
            Log::warning('Falha ao notificar admin sobre pagamento: ' . $e->getMessage());
        }
    }
}
