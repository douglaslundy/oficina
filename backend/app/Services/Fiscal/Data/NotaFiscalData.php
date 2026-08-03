<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class NotaFiscalData
{
    /**
     * @param array<int, array{
     *   produto_id: string, descricao: string, ncm: string, cfop: string,
     *   origem: int, tributacao_icms: string, cst_csosn: string,
     *   quantidade: float, valor_unitario: float,
     * }> $itens Só populado quando $modelo === 'NFE'.
     */
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
        public readonly string $modelo = 'NFSE',
        public readonly array $itens = [],
    ) {}
}
