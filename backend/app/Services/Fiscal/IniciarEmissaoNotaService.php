<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Jobs\EmitirNotaFiscalJob;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Services\NfeService;
use App\Tenancy\TenancyContext;
use Illuminate\Support\Facades\DB;

/**
 * Dispara a emissão de uma NotaFiscal: resolve provedor/ambiente, aloca o
 * número (síncrono, transacional — nunca dentro do job) e enfileira o
 * EmitirNotaFiscalJob.
 *
 * Extraído de NotaFiscalController::emitir() (2026-09-05) pra ser
 * reaproveitado pelo EmissaoOrquestradorService (OS mista → 2 notas
 * emitidas de uma vez).
 */
class IniciarEmissaoNotaService
{
    public function __construct(
        private readonly NfeService $nfeService,
    ) {}

    /**
     * Nada acontece se a nota já está AUTORIZADA ou PROCESSANDO — devolve
     * `false` nesses casos, `true` quando de fato iniciou.
     *
     * A checagem de status + alocação de número + `update()` roda inteira
     * dentro de uma transação com a linha travada (`lockForUpdate`) — sem
     * isso, um duplo clique ou um retry do frontend por timeout pode fazer
     * duas chamadas concorrentes passarem as duas pela checagem de status
     * ANTES de qualquer uma persistir `PROCESSANDO`, cada uma alocar um
     * número (os contadores em si já são protegidos) e cada uma disparar um
     * job de emissão real pro provedor fiscal — duas notas fiscais de
     * verdade pro mesmo documento comercial. O dispatch do job fica FORA da
     * transação de propósito: `queue.after_commit` é `false` neste projeto,
     * então despachar antes do commit arrisca o worker pegar o job antes da
     * linha estar de fato PROCESSANDO no banco.
     */
    public function iniciar(NotaFiscal $nota): bool
    {
        if (in_array($nota->status, ['AUTORIZADA', 'PROCESSANDO'], true)) {
            return false;
        }

        $ambiente = DB::transaction(function () use ($nota) {
            $notaTravada = NotaFiscal::lockForUpdate()->find($nota->id);

            if (! $notaTravada || in_array($notaTravada->status, ['AUTORIZADA', 'PROCESSANDO'], true)) {
                return null;
            }

            $provedor = app(FiscalProviderManager::class)->provedorDaOficina(TenancyContext::get() ?? '');
            $ambiente = Configuracao::first()?->ambiente_fiscal ?? 'HOMOLOGACAO';
            $ref      = $notaTravada->referencia_externa ?: ('nf-' . $notaTravada->id);

            // NF-e/NFC-e via NFEPHP têm numeração PRÓPRIA (Configuracao::
            // proximo_numero_nfe/proximo_numero_nfce_nfephp), independente dos
            // contadores de Spedy/Focus. Pra NFEPHP preservamos o número já
            // reservado (retry reusa número reservado) — MotorNfe/MotorNfce
            // alocam um número novo internamente só quando ainda não há um.
            // A checagem de "mesmo provedor" usa o provedor de QUANDO o
            // número foi reservado, lido da linha travada ANTES do update().
            if ($provedor === 'NFEPHP' && in_array($notaTravada->modelo, ['NF-e', 'NFC-e'], true)) {
                $numeroInicial = ($notaTravada->provedor === 'NFEPHP') ? $notaTravada->numero : null;
            } elseif ($notaTravada->modelo === 'NFC-e') {
                $numeroInicial = $this->nfeService->proximoNumeroNfce();
            } else {
                $numeroInicial = $this->nfeService->proximoNumeroNf();
            }

            $notaTravada->update([
                'status'             => 'PROCESSANDO',
                'numero'             => $numeroInicial,
                'provedor'           => $provedor,
                'ambiente'           => $ambiente,
                'referencia_externa' => $ref,
            ]);

            return $ambiente;
        });

        if ($ambiente === null) {
            return false;
        }

        EmitirNotaFiscalJob::dispatch(
            $nota->id,
            (string) TenancyContext::get(),
            Oficina::find(TenancyContext::get())?->slug ?? '',
            $ambiente,
        );

        return true;
    }
}
