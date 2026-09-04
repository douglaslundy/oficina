<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class ConsultaNotaTerceiroResumo
{
    public function __construct(
        public readonly string $chaveAcesso,
        public readonly ?string $fornecedorNome,
        public readonly ?string $fornecedorCnpj,
        public readonly ?string $dataEmissao,
        public readonly float $valorTotal,
        public readonly bool $completa,
    ) {}
}
