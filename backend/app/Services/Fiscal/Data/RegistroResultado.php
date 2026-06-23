<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class RegistroResultado
{
    public function __construct(
        public readonly string $status,             // REGISTRADO | ERRO
        public readonly ?string $emissorExternoId = null,
        public readonly ?string $token = null,
        public readonly ?string $mensagemErro = null,
    ) {}

    public static function ok(string $emissorExternoId, string $token): self
    {
        return new self('REGISTRADO', $emissorExternoId, $token, null);
    }

    public static function erro(string $mensagemErro): self
    {
        return new self('ERRO', null, null, $mensagemErro);
    }
}
