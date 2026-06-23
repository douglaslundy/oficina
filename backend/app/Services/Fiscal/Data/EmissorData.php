<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class EmissorData
{
    public function __construct(
        public readonly string $cnpj,
        public readonly string $razaoSocial,
        public readonly ?string $nomeFantasia,
        public readonly ?string $inscricaoEstadual,
        public readonly ?string $inscricaoMunicipal,
        public readonly string $regimeTributario,
        public readonly string $email,
        public readonly ?string $telefone,
        public readonly string $cep,
        public readonly string $logradouro,
        public readonly string $numero,
        public readonly ?string $complemento,
        public readonly string $bairro,
        public readonly string $cidade,
        public readonly string $uf,
        public readonly string $codigoIbge,
        public readonly string $cnae,
    ) {}

    public function cnpjLimpo(): string
    {
        return preg_replace('/\D/', '', $this->cnpj) ?? '';
    }
}
