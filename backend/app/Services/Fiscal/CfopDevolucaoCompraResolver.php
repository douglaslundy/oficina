<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * CFOP da NF-e de DEVOLUÇÃO DE COMPRA (a oficina devolve ao fornecedor
 * mercadoria que comprou pra revenda). Fonte: Convênio s/nº de 15/12/1970
 * (Tabela CFOP):
 *   5.202 / 6.202 — Devolução de compra para comercialização
 *   5.411 / 6.411 — Devolução de compra para comercialização em operação com
 *                   mercadoria sujeita ao regime de substituição tributária
 * 5 = dentro do estado do emitente, 6 = fora do estado. Combinação inválida
 * lança exceção — nunca um CFOP chutado.
 */
final class CfopDevolucaoCompraResolver
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
            throw new \InvalidArgumentException("UF inválida para CFOP de devolução: origem={$ufOrigem} destino={$ufDestino}");
        }

        $dentro = $ufOrigem === $ufDestino;

        return match (true) {
            $dentro && !$substituicaoTributaria  => '5202',
            $dentro && $substituicaoTributaria   => '5411',
            !$dentro && !$substituicaoTributaria => '6202',
            default                              => '6411',
        };
    }
}
