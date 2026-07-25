<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Normaliza e valida o formato dos campos fiscais.
 *
 * Devolve null para qualquer valor malformado, NUNCA o valor cru. Guardar
 * lixo num campo fiscal é pior que deixá-lo vazio: o vazio aparece na tela
 * de pendências, o lixo passa por preenchido.
 */
final class ValidadorCamposFiscais
{
    /** NCM/SH tem 8 dígitos. */
    public static function ncm(?string $valor): ?string
    {
        return self::apenasDigitos($valor, 8);
    }

    /** CEST tem 7 dígitos (Convênio ICMS 142/2018). */
    public static function cest(?string $valor): ?string
    {
        return self::apenasDigitos($valor, 7);
    }

    /** Origem da mercadoria: 0 a 8 (Tabela A do Anexo do Convênio SINIEF s/nº 1970). */
    public static function origem(int|string|null $valor): ?int
    {
        if ($valor === null || $valor === '') return null;
        if (!is_numeric($valor))              return null;

        $inteiro = (int) $valor;

        return ($inteiro >= 0 && $inteiro <= 8) ? $inteiro : null;
    }

    private static function apenasDigitos(?string $valor, int $tamanho): ?string
    {
        if ($valor === null) return null;
        $digitos = preg_replace('/\D/', '', $valor) ?? '';

        return strlen($digitos) === $tamanho ? $digitos : null;
    }
}
