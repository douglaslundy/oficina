<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Services\Fiscal\AplicarResultadoNotaService;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\NfeService;
use App\Tenancy\TenancyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Varre notas presas em PROCESSANDO e consulta o status real no provedor.
 *
 * O polling do frontend (GET /notas-fiscais/{id}/status) só reconcilia
 * enquanto alguém está com a tela de emissão aberta. Se o usuário fecha a
 * aba antes de a Spedy/Focus terminar o processamento assíncrono, a nota
 * fica PROCESSANDO pra sempre. Este comando (agendado) fecha essa lacuna.
 */
class ReconciliarNotasProcessando extends Command
{
    protected $signature   = 'nfe:reconciliar-processando';
    protected $description = 'Consulta no provedor as notas presas em PROCESSANDO e reconcilia o status';

    /** Janela de carência: uma nota emitida há poucos minutos ainda pode estar legitimamente processando. */
    private const IDADE_MINIMA_MINUTOS = 10;

    public function __construct(
        private readonly NfeService $nfeService,
        private readonly FiscalProviderManager $providerManager,
        private readonly AplicarResultadoNotaService $aplicarResultado,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $reconciliadas = 0;
        $aindaProcessando = 0;
        $falhas = 0;

        foreach (Oficina::whereIn('status', ['ATIVA', 'TRIAL'])->get() as $oficina) {
            TenancyContext::set($oficina->id, $oficina->slug);
            $ambiente = $this->providerManager->ambienteDaOficina();

            $notas = NotaFiscal::where('status', 'PROCESSANDO')
                ->where('criado_em', '<', now()->subMinutes(self::IDADE_MINIMA_MINUTOS))
                ->get();

            foreach ($notas as $nota) {
                try {
                    $resultado = $this->nfeService->consultarStatus($nota->loadMissing('itens'));
                } catch (\Throwable $e) {
                    $falhas++;
                    Log::warning('nfe:reconciliar-processando: falha ao consultar', [
                        'nota_id' => $nota->id,
                        'numero'  => $nota->numero,
                        'erro'    => $e->getMessage(),
                    ]);
                    continue;
                }

                $this->aplicarResultado->aplicar($nota, $resultado, $ambiente);

                if (($resultado['status'] ?? 'PROCESSANDO') === 'PROCESSANDO') {
                    $aindaProcessando++;
                } else {
                    $reconciliadas++;
                }
            }

            TenancyContext::clear();
        }

        $msg = "PROCESSANDO reconciliadas: {$reconciliadas}; ainda em processamento: {$aindaProcessando}; falhas de consulta: {$falhas}.";
        Log::info('nfe:reconciliar-processando — ' . $msg);
        $this->info($msg);

        return self::SUCCESS;
    }
}
