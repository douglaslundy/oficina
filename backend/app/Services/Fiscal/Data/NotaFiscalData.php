<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class NotaFiscalData
{
    public function __construct(
        public readonly string $tipo,                  // NFSE (Fase 1)
        public readonly array $tomador,
        public readonly string $descricao,
        public readonly float $valorServicos,
        public readonly float $aliquotaIss,
        public readonly bool $issRetido,
        public readonly string $codigoServicoFederal,
        public readonly string $codigoServicoMunicipal,
        public readonly string $naturezaOperacao,
        public readonly string $referenciaExterna,
    ) {}
}
