<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

/** PDF já renderizado, com a mesma interface mínima (`output()`) que o PDF do DomPDF devolve em montarPdfArquivo(). */
final class PdfPronto
{
    public function __construct(private readonly string $conteudo) {}

    public function output(): string
    {
        return $this->conteudo;
    }
}
