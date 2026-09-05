<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Jobs\EmitirNotaFiscalJob;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Services\NfeService;
use App\Tenancy\TenancyContext;

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
     */
    public function iniciar(NotaFiscal $nota): bool
    {
        if (in_array($nota->status, ['AUTORIZADA', 'PROCESSANDO'], true)) {
            return false;
        }

        $provedor = app(FiscalProviderManager::class)->provedorDaOficina(TenancyContext::get() ?? '');
        $ambiente = Configuracao::first()?->ambiente_fiscal ?? 'HOMOLOGACAO';
        $ref      = $nota->referencia_externa ?: ('nf-' . $nota->id);

        // NF-e via NFEPHP tem numeração PRÓPRIA (Configuracao::proximo_numero_nfe),
        // independente do contador Spedy/Focus e do de NFC-e. Pra NFEPHP + NF-e
        // preservamos $nota->numero existente (retry reusa número reservado).
        // A checagem de "mesmo provedor" usa $nota->provedor (o de QUANDO o
        // número foi reservado), lido ANTES do update() abaixo.
        if ($provedor === 'NFEPHP' && $nota->modelo === 'NF-e') {
            $numeroInicial = ($nota->provedor === 'NFEPHP') ? $nota->numero : null;
        } elseif ($nota->modelo === 'NFC-e') {
            $numeroInicial = $this->nfeService->proximoNumeroNfce();
        } else {
            $numeroInicial = $this->nfeService->proximoNumeroNf();
        }

        $nota->update([
            'status'             => 'PROCESSANDO',
            'numero'             => $numeroInicial,
            'provedor'           => $provedor,
            'ambiente'           => $ambiente,
            'referencia_externa' => $ref,
        ]);

        EmitirNotaFiscalJob::dispatch(
            $nota->id,
            (string) TenancyContext::get(),
            Oficina::find(TenancyContext::get())?->slug ?? '',
            $ambiente,
        );

        return true;
    }
}
