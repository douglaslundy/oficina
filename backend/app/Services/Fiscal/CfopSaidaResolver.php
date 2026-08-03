<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * CFOP de saída é da OPERAÇÃO, não da mercadoria (mesma regra que a Etapa A
 * já aplicou pra entrada) — depende de UF origem/destino e se o item tem
 * ICMS já recolhido por substituição tributária.
 *
 * Fonte: Convênio s/nº de 15/12/1970 (Tabela CFOP). Combinação fora das 4
 * linhas cobertas lança exceção — nunca um CFOP chutado.
 */
final class CfopSaidaResolver
{
    private const UFS_VALIDAS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];

    public static function resolver(string $ufOrigem, string $ufDestino, bool $substituicaoTributaria): string
    {
        $ufOrigem  = strtoupper($ufOrigem);
        $ufDestino = strtoupper($ufDestino);

        if (!in_array($ufOrigem, self::UFS_VALIDAS, true) || !in_array($ufDestino, self::UFS_VALIDAS, true)) {
            throw new \InvalidArgumentException("UF inválida para cálculo de CFOP: origem={$ufOrigem} destino={$ufDestino}");
        }

        $dentroDoEstado = $ufOrigem === $ufDestino;

        return match (true) {
            $dentroDoEstado && !$substituicaoTributaria  => '5102',
            $dentroDoEstado && $substituicaoTributaria   => '5405',
            !$dentroDoEstado && !$substituicaoTributaria => '6102',
            !$dentroDoEstado && $substituicaoTributaria  => '6404',
        };
    }
}
