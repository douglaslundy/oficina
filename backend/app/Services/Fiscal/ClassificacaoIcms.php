<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Deriva a situação tributária de ICMS a partir do CST ou CSOSN declarado
 * no XML do fornecedor.
 *
 * Base legal:
 * - CST:   Tabela B do Anexo do Convênio SINIEF s/nº de 15/12/1970.
 * - CSOSN: Ajuste SINIEF 03/2010, Anexo Único, Tabela B.
 *
 * A derivação tem TRÊS estados de propósito. O Ajuste SINIEF 39/2023 criou
 * os CST 12/13/52/72/74 (todos de ST) e o Ajuste SINIEF 20/2024 revogou a
 * criação — a tabela é instável. Um `else` que assumisse NORMAL
 * classificaria silenciosamente peça de ST como tributação normal, que é o
 * erro caro. Código não reconhecido devolve null e vira pendência.
 */
final class ClassificacaoIcms
{
    public const ST     = 'ST';
    public const NORMAL = 'NORMAL';

    private const CST_ST     = ['10', '30', '60', '70'];
    private const CST_NORMAL = ['00', '20', '40', '41', '50', '51', '90'];

    private const CSOSN_ST     = ['201', '202', '203', '500'];
    private const CSOSN_NORMAL = ['101', '102', '103', '300', '400', '900'];

    /** @return self::ST|self::NORMAL|null  null = código desconhecido */
    public static function derivar(?string $cst, ?string $csosn): ?string
    {
        $csosnLimpo = self::normalizar($csosn, 3);
        if ($csosnLimpo !== null) {
            if (in_array($csosnLimpo, self::CSOSN_ST, true))     return self::ST;
            if (in_array($csosnLimpo, self::CSOSN_NORMAL, true)) return self::NORMAL;
            return null;
        }

        $cstLimpo = self::normalizar($cst, 2);
        if ($cstLimpo !== null) {
            if (in_array($cstLimpo, self::CST_ST, true))     return self::ST;
            if (in_array($cstLimpo, self::CST_NORMAL, true)) return self::NORMAL;
        }

        return null;
    }

    /** Remove não-dígitos e reaplica o zero à esquerda que o XML às vezes omite. */
    private static function normalizar(?string $valor, int $tamanho): ?string
    {
        if ($valor === null) return null;
        $digitos = preg_replace('/\D/', '', $valor) ?? '';
        if ($digitos === '') return null;
        return str_pad($digitos, $tamanho, '0', STR_PAD_LEFT);
    }
}
