<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Deriva o código CST (Regime Normal) ou CSOSN (Simples Nacional) de saída
 * a partir da classificação simplificada NORMAL/ST já gravada no produto
 * (Etapa A) + o regime tributário da oficina. Base legal confirmada:
 * CSOSN 102/500 (Ajuste SINIEF 03/2010, Anexo Único, Tabela B), CST 00/60
 * (Tabela B do Anexo do Convênio SINIEF s/nº de 15/12/1970) — mesmas fontes
 * já usadas pela Etapa A.
 */
final class TributacaoIcmsSaidaResolver
{
    public static function resolver(string $regimeTributario, string $tributacaoIcms): string
    {
        if (!in_array($tributacaoIcms, ['NORMAL', 'ST'], true)) {
            throw new \InvalidArgumentException("tributacao_icms inválida: {$tributacaoIcms}");
        }

        $simplesNacional = str_contains(strtolower($regimeTributario), 'simples');
        $st              = $tributacaoIcms === 'ST';

        return match (true) {
            $simplesNacional && !$st  => '102',
            $simplesNacional && $st   => '500',
            !$simplesNacional && !$st => '00',
            !$simplesNacional && $st  => '60',
        };
    }
}
