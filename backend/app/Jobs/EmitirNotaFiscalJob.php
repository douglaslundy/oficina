<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotaFiscal;
use App\Services\Fiscal\AplicarResultadoNotaService;
use App\Services\NfeService;
use App\Tenancy\TenancyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Emissão fiscal fora do request HTTP. O controller já alocou o número,
 * marcou a nota PROCESSANDO e resolveu provedor/ambiente — este job só faz
 * a chamada ao provedor (que pra NFePHP + contingência EPEC pode levar
 * dezenas de segundos) e aplica o resultado. O frontend acompanha via
 * GET /notas-fiscais/{id}/status (polling que já existe desde a NFC-e).
 *
 * Em ambiente de teste/local (QUEUE_CONNECTION=sync) roda inline no
 * dispatch — o comportamento visto pelos testes de feature é idêntico ao
 * síncrono anterior.
 */
class EmitirNotaFiscalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // NFePHP faz SOAP com a SEFAZ + eventual EPEC — folga sobre o timeout
    // padrão de 120s do worker de produção.
    public int $timeout = 180;
    public int $tries   = 1;

    public function __construct(
        private readonly string $notaFiscalId,
        private readonly string $oficinaId,
        private readonly string $oficinaSlug,
        private readonly string $ambiente,
    ) {}

    public function handle(NfeService $nfeService, AplicarResultadoNotaService $aplicarResultado): void
    {
        TenancyContext::set($this->oficinaId, $this->oficinaSlug);

        try {
            $nota = NotaFiscal::with(['cliente', 'itens'])->find($this->notaFiscalId);
            if (! $nota || $nota->status !== 'PROCESSANDO') {
                // Nota sumiu ou já foi resolvida (retry, corrida com o
                // comando de reconciliação, etc.) — nada a fazer.
                return;
            }

            $resultado = $nfeService->emitir($nota);
            $aplicarResultado->aplicar($nota, $resultado, $this->ambiente);
        } catch (\Throwable $e) {
            Log::error('EmitirNotaFiscalJob falhou: ' . $e->getMessage(), [
                'nota_fiscal_id' => $this->notaFiscalId,
            ]);
            NotaFiscal::where('id', $this->notaFiscalId)
                ->where('status', 'PROCESSANDO')
                ->update([
                    'status'       => 'REJEITADA',
                    'mensagem_erro' => 'Falha técnica ao emitir a nota: ' . $e->getMessage(),
                ]);
        } finally {
            TenancyContext::clear();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('EmitirNotaFiscalJob esgotou as tentativas: ' . $e->getMessage(), [
            'nota_fiscal_id' => $this->notaFiscalId,
        ]);
    }
}
