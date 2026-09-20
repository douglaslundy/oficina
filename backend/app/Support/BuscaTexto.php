<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Normalização de texto para busca parcial que ignora acento e caixa
 * ("filtro oleo" acha "Filtro de Óleo"), sem depender da extensão `unaccent`
 * do Postgres (exigiria CREATE EXTENSION em produção).
 *
 * O SQL usa `translate(coluna, ACENTOS, SEM_ACENTOS)` e o PHP aplica o mesmo
 * mapa ao texto digitado — os dois lados precisam concordar caractere a
 * caractere (garantido em BuscaTextoTest). A caixa fica com o ILIKE.
 */
final class BuscaTexto
{
    public const ACENTOS     = 'áàâãäéèêëíìîïóòôõöúùûüçñÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇÑ';
    public const SEM_ACENTOS = 'aaaaaeeeeiiiiooooouuuucnaaaaaeeeeiiiiooooouuuucn';

    /** Minúsculo, sem acento e com espaços colapsados. */
    public static function normalizar(string $texto): string
    {
        $mapa = array_combine(mb_str_split(self::ACENTOS), mb_str_split(self::SEM_ACENTOS));
        $semAcento = strtr($texto, $mapa);

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($semAcento)));
    }

    /**
     * Palavras do texto já normalizadas. Cada palavra precisa aparecer no
     * alvo, em qualquer ordem ("oleo filtro" acha "Filtro de Óleo").
     *
     * @return list<string>
     */
    public static function tokens(string $texto): array
    {
        $normalizado = self::normalizar($texto);

        return $normalizado === '' ? [] : explode(' ', $normalizado);
    }

    /** Escapa os curingas do LIKE (% e _) e a própria barra. */
    public static function escaparLike(string $texto): string
    {
        return addcslashes($texto, '\\%_');
    }
}
