<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class ConsultaNotaTerceiroResultado
{
    private function __construct(
        public readonly string $status, // COMPLETA | AGUARDANDO_MANIFESTACAO | NAO_ENCONTRADA | ERRO
        public readonly ?array $dados = null,
        public readonly ?string $mensagemErro = null,
    ) {}

    public static function completa(array $dados): self
    {
        return new self('COMPLETA', $dados);
    }

    public static function aguardandoManifestacao(): self
    {
        return new self('AGUARDANDO_MANIFESTACAO');
    }

    public static function naoEncontrada(): self
    {
        return new self('NAO_ENCONTRADA');
    }

    public static function erro(string $mensagemErro): self
    {
        return new self('ERRO', null, $mensagemErro);
    }
}
