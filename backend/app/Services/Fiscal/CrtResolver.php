<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Deriva o Código de Regime Tributário (CRT, usado no leiaute da NF-e/NFS-e)
 * a partir do texto livre já gravado em Configuracao.regime_tributario —
 * mesmo padrão de mapeamento por string que TributacaoIcmsSaidaResolver já
 * usa. Não é um campo novo de banco: é calculado na hora de montar o
 * payload fiscal.
 */
final class CrtResolver
{
    public static function resolver(string $regimeTributario): int
    {
        if (trim($regimeTributario) === '') {
            throw new \InvalidArgumentException('Regime tributário não pode ser vazio para derivar o CRT.');
        }

        return str_contains(strtolower($regimeTributario), 'simples') ? 1 : 3;
    }
}
