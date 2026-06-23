<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Contracts;

use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;

interface FiscalProvider
{
    /** Registra a empresa emissora no provedor e retorna o token por-oficina. */
    public function registrarEmissor(EmissorData $e): RegistroResultado;

    /** Sobe/vincula o certificado A1 (.pfx) ao emissor já registrado. */
    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void;

    /** Emite uma nota (NFS-e na Fase 1). Pode retornar PROCESSANDO (assíncrono). */
    public function emitir(NotaFiscalData $nota): EmissaoResultado;

    /** Consulta o status atual de uma nota pela referência. */
    public function consultar(string $referencia): EmissaoResultado;

    /** Cancela uma nota autorizada. */
    public function cancelar(string $referencia, string $motivo): EmissaoResultado;
}
