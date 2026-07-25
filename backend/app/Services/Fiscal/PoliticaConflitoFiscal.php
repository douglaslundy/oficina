<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Decide o que fazer quando o XML do fornecedor traz um valor fiscal para
 * um campo do produto.
 *
 * Divergência NUNCA sobrescreve: protege correção feita de propósito, sem
 * esconder que existe conflito. Fornecedores erram classificação com
 * frequência — e às vezes quem está certo é o fornecedor, por isso o
 * conflito vira registro para decisão humana em vez de ser descartado.
 */
final class PoliticaConflitoFiscal
{
    public const PREENCHER   = 'PREENCHER';
    public const NADA        = 'NADA';
    public const DIVERGENCIA = 'DIVERGENCIA';

    public static function decidir(mixed $atual, mixed $doXml): string
    {
        if (self::vazio($doXml)) return self::NADA;
        if (self::vazio($atual)) return self::PREENCHER;

        return ((string) $atual === (string) $doXml) ? self::NADA : self::DIVERGENCIA;
    }

    /**
     * Cuidado: `empty()` trataria 0 como vazio, e 0 é origem VÁLIDA
     * (mercadoria nacional) — o produto ficaria eternamente "sem origem".
     */
    private static function vazio(mixed $valor): bool
    {
        return $valor === null || $valor === '';
    }
}
