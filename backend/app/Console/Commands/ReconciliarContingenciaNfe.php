<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Services\AlertaDispatchService;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\Fiscal\NfePhp\MotorNfe;
use App\Services\Fiscal\PrazoContingencia;
use App\Tenancy\TenancyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconciliarContingenciaNfe extends Command
{
    protected $signature   = 'nfe:reconciliar-contingencia';
    protected $description = 'Retransmite NF-e em contingência EPEC e alerta antes do prazo de 7 dias';

    public function __construct(
        private readonly MotorNfe $motor,
        private readonly AlertaDispatchService $alertaDispatch,
        private readonly FiscalProviderManager $providerManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $oficinas = Oficina::whereIn('status', ['ATIVA', 'TRIAL'])->get();
        $totalRetransmitidas = 0;
        $totalAlertadas = 0;
        $totalCanceladas = 0;

        foreach ($oficinas as $oficina) {
            TenancyContext::set($oficina->id, $oficina->slug);

            $notasEmContingencia = NotaFiscal::where('status', 'CONTINGENCIA')
                ->whereNotNull('contingencia_desde')
                ->get();

            $ambiente = $this->providerManager->ambienteDaOficina();

            foreach ($notasEmContingencia as $nota) {
                $resultado = $this->motor->retransmitir($nota, $ambiente);

                if ($resultado->status === 'AUTORIZADA') {
                    $nota->update([
                        'status'             => 'AUTORIZADA',
                        'chave_acesso'       => $resultado->chave ?: $nota->chave_acesso,
                        'protocolo'          => $resultado->protocolo ?: $nota->protocolo,
                        'xml_retorno'        => $resultado->xml ?: $nota->xml_retorno,
                        'contingencia_desde' => null,
                        'emitido_em'         => now(),
                    ]);
                    $totalRetransmitidas++;
                    continue;
                }

                // retransmitir() consulta a SEFAZ antes de reenviar, mas só
                // trata AUTORIZADA como caso especial (Task 5) — se a
                // consulta (ou o próprio reenvio) volta CANCELADA, a nota já
                // foi cancelada por fora (ex.: admin cancelou manualmente
                // enquanto estava em contingência) e insistir em reenviar
                // todo hour é inútil e só queima chamada à SEFAZ. Reconcilia
                // o status local e tira a nota da consulta `WHERE status =
                // CONTINGENCIA` da próxima execução, sem alertar prazo.
                if ($resultado->status === 'CANCELADA') {
                    $nota->update([
                        'status'             => 'CANCELADA',
                        'contingencia_desde' => null,
                    ]);
                    $totalCanceladas++;
                    continue;
                }

                // ERRO (falha ao consultar/reenviar) não é "só mais uma
                // contingência em andamento" — registra pra visibilidade,
                // mas o prazo de 7 dias continua correndo de qualquer forma,
                // então o alerta abaixo dispara normalmente.
                if ($resultado->status === 'ERRO') {
                    Log::warning('ReconciliarContingenciaNfe: falha ao retransmitir NF-e em contingência.', [
                        'nota_id' => $nota->id,
                        'numero'  => $nota->numero,
                        'erro'    => $resultado->mensagemErro,
                    ]);
                }

                $diasRestantes = PrazoContingencia::diasRestantes($nota->contingencia_desde, now());
                if (PrazoContingencia::precisaAlertar($nota->contingencia_desde, now())) {
                    $this->alertaDispatch->dispatch('NF_CONTINGENCIA_PRAZO', [
                        'nf_numero'          => $nota->numero,
                        'contingencia_desde' => $nota->contingencia_desde->format('d/m/Y H:i'),
                        'dias_restantes'     => $diasRestantes,
                    ]);
                    $totalAlertadas++;
                }
            }

            TenancyContext::clear();
        }

        $this->info(
            "Contingência reconciliada: {$totalRetransmitidas} retransmitida(s), "
            . "{$totalCanceladas} cancelada(s), {$totalAlertadas} alerta(s) disparado(s)."
        );
        return self::SUCCESS;
    }
}
