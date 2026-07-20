<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SuperAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lógica de confirmação de pagamento compartilhada entre o webhook dos
 * gateways (assíncrono) e o checkout transparente (síncrono, pagamento
 * aprovado na hora). Idempotente — pode ser chamada mais de uma vez para a
 * mesma cobrança sem duplicar efeitos.
 */
class PagamentoReconciliacaoService
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly AdminWhatsAppService $adminWhatsApp,
        private readonly MercadoPagoService $mercadoPago,
        private readonly AsaasService $asaas,
    ) {}

    /**
     * Consulta o gateway pra ver se uma cobrança PENDENTE/VENCIDA com
     * payment_id já foi paga de verdade, e concilia na hora se sim. Não
     * depende do webhook ter chegado — usado tanto no polling da tela de
     * pagamento quanto em qualquer lugar que precise garantir que o status
     * exibido reflete a realidade, mesmo que o webhook tenha atrasado,
     * falhado ou nunca tenha sido configurado corretamente.
     *
     * @return bool true se conciliou (estava pendente e na verdade já foi paga)
     */
    public function verificarEConciliar(Cobranca $cobranca): bool
    {
        if (in_array($cobranca->status, ['PAGA', 'CANCELADA', 'ESTORNADA'], true)) {
            return false;
        }

        try {
            if ($cobranca->gateway === 'MERCADOPAGO' && $cobranca->mp_payment_id) {
                $pagamento = $this->mercadoPago->buscarPagamento($cobranca->mp_payment_id);
                if (($pagamento['status'] ?? null) === 'approved') {
                    $this->confirmarPagamento($cobranca);
                    return true;
                }
            } elseif ($cobranca->gateway === 'ASAAS' && $cobranca->asaas_payment_id) {
                $pagamento = $this->asaas->buscarPagamento($cobranca->asaas_payment_id);
                if (in_array($pagamento['status'] ?? null, ['RECEIVED', 'CONFIRMED'], true)) {
                    $this->confirmarPagamento($cobranca);
                    return true;
                }
            }
        } catch (\Throwable) {
            // Silencioso — quem chamou tenta de novo na próxima oportunidade.
        }

        return false;
    }

    /**
     * Lê e escreve o status dentro de uma transação com lockForUpdate() —
     * sem isso, duas chamadas concorrentes (webhook + polling da tela, ou
     * duas abas abertas) podiam ambas ler PENDENTE antes de qualquer uma
     * escrever PAGA, executando avancarVencimento() duas vezes (pulando um
     * ciclo de cobrança inteiro). O e-mail de notificação fica FORA da
     * transação de propósito — não vale a pena segurar o lock da linha
     * durante uma chamada SMTP.
     */
    public function confirmarPagamento(Cobranca $cobranca): void
    {
        $locked = DB::transaction(function () use ($cobranca) {
            $row = Cobranca::whereKey($cobranca->getKey())->lockForUpdate()->first();
            if (!$row || in_array($row->status, ['PAGA', 'CANCELADA', 'ESTORNADA'], true)) {
                return null;
            }

            $row->update(['status' => 'PAGA', 'pago_em' => now()]);

            if ($row->tipo === 'ASSINATURA') {
                $oficina = Oficina::find($row->oficina_id);
                if ($oficina) {
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
            }

            return $row;
        });

        if (!$locked) return;

        // Mantém a instância que o chamador tem em mãos sincronizada.
        $cobranca->status  = $locked->status;
        $cobranca->pago_em = $locked->pago_em;

        $oficina = Oficina::find($locked->oficina_id);
        if ($oficina) {
            $this->notificarAdminPagamento($locked, $oficina);
        }
    }

    /**
     * Notifica por e-mail e WhatsApp todos os canais configurados quando uma
     * cobrança (assinatura ou avulsa) é confirmada como paga. Cada canal é
     * silencioso e independente — se um não estiver configurado ou falhar,
     * não afeta o outro nem a confirmação do pagamento em si.
     */
    private function notificarAdminPagamento(Cobranca $cobranca, Oficina $oficina): void
    {
        $tipoLabel    = $cobranca->tipo === 'ASSINATURA' ? 'Mensalidade/Anuidade' : 'Cobrança avulsa';
        $gatewayLabel = $cobranca->gateway === 'MERCADOPAGO' ? 'Mercado Pago' : 'Asaas';
        $valorFmt     = number_format((float) $cobranca->valor, 2, ',', '.');
        $vencimento   = $cobranca->vencimento?->format('d/m/Y') ?? '-';

        try {
            if ($this->emailService->configurado()) {
                $emails = SuperAdmin::query()->pluck('email')->filter()->values()->all();
                if (!empty($emails)) {
                    $corpo = "A oficina {$oficina->nome} pagou uma fatura.\n\n"
                        . "Tipo: {$tipoLabel}\n"
                        . "Valor: R$ {$valorFmt}\n"
                        . "Vencimento: {$vencimento}\n"
                        . 'Pago em: ' . now()->format('d/m/Y H:i') . "\n"
                        . "Gateway: {$gatewayLabel}";

                    $this->emailService->enviar($emails, "MecânicaPro — Pagamento recebido: {$oficina->nome}", $corpo);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao notificar admin por e-mail sobre pagamento: ' . $e->getMessage());
        }

        try {
            if ($this->adminWhatsApp->estaAtivo()) {
                $mensagem = "💰 *Pagamento recebido*\n\n"
                    . "Oficina: {$oficina->nome}\n"
                    . "Tipo: {$tipoLabel}\n"
                    . "Valor: R$ {$valorFmt}\n"
                    . "Vencimento: {$vencimento}\n"
                    . "Gateway: {$gatewayLabel}";

                $this->adminWhatsApp->enviarMensagem($mensagem);
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao notificar admin por WhatsApp sobre pagamento: ' . $e->getMessage());
        }
    }
}
