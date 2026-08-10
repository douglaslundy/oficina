<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * CFOP de venda a consumidor final (NFC-e) é mais simples que o CfopSaidaResolver
 * B2B: por definição, o destinatário de uma NFC-e nunca é contribuinte de ICMS,
 * então não há distinção de substituição tributária aqui — só dentro/fora do
 * estado. Fonte: Convênio s/nº de 15/12/1970 (Tabela CFOP), mesmas fontes já
 * usadas pelo CfopSaidaResolver da Etapa B.
 */
final class CfopConsumidorResolver
{
    private const UFS_VALIDAS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];

    public static function resolver(string $ufOrigem, string $ufDestino): string
    {
        $ufOrigem  = strtoupper($ufOrigem);
        $ufDestino = strtoupper($ufDestino);

        if (!in_array($ufOrigem, self::UFS_VALIDAS, true) || !in_array($ufDestino, self::UFS_VALIDAS, true)) {
            throw new \InvalidArgumentException("UF inválida para cálculo de CFOP de consumidor final: origem={$ufOrigem} destino={$ufDestino}");
        }

        return $ufOrigem === $ufDestino ? '5102' : '6108';
    }
}
