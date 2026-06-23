<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class EmissaoResultado
{
    public function __construct(
        public readonly string $status,            // AUTORIZADA | PROCESSANDO | REJEITADA | CANCELADA
        public readonly ?string $chave = null,
        public readonly ?string $protocolo = null,
        public readonly ?string $numero = null,
        public readonly ?string $xml = null,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $mensagemErro = null,
        public readonly ?string $referenciaExterna = null,
    ) {}

    public static function autorizada(?string $chave, ?string $protocolo, ?string $numero, ?string $xml, ?string $pdfUrl, ?string $ref = null): self
    {
        return new self('AUTORIZADA', $chave, $protocolo, $numero, $xml, $pdfUrl, null, $ref);
    }

    public static function processando(?string $ref = null): self
    {
        return new self('PROCESSANDO', null, null, null, null, null, null, $ref);
    }

    public static function rejeitada(string $mensagemErro, ?string $ref = null): self
    {
        return new self('REJEITADA', null, null, null, null, null, $mensagemErro, $ref);
    }

    public static function cancelada(?string $ref = null): self
    {
        return new self('CANCELADA', null, null, null, null, null, null, $ref);
    }
}
