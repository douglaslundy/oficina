<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Deriva o Código de Tributação Nacional (cTribNac, 6 dígitos, exigido pelo
 * Sistema Nacional NFS-e) a partir do código LC 116/2003 no formato
 * "item.subitem" (ex.: "14.01") já usado em NotaFiscalData::codigoServicoFederal.
 *
 * Bug real de produção (2026-09-14): MotorNfse mandava o próprio "14.01"
 * como cTribNac — a SEFAZ/ADN rejeitou com "E1235: Falha no esquema XML do
 * DF-e" porque o schema exige TSCodTribNac = 6 dígitos numéricos (2 item +
 * 2 subitem + 2 "desdobro nacional", uma subdivisão NOVA do Sistema
 * Nacional NFS-e que não existe no código LC116 clássico usado por
 * Spedy/Focus).
 *
 * Mapeamento confirmado na tabela oficial
 * (gov.br/nfse/pt-br/mei-e-demais-empresas/codigos-de-tributacao-nacional-nbs,
 * consultada em 2026-09-14) — não é um valor inventado. Só mapeia o(s)
 * código(s) que este sistema realmente usa (`codigoServicoFederal` é sempre
 * "14.01" aqui, nunca configurável por serviço — ver NfeService::emitir()).
 * Qualquer outro código lança exceção em vez de um default silencioso —
 * mesma regra já aplicada em CrtResolver.
 */
final class CodigoTributacaoNacionalResolver
{
    private const MAPA = [
        // "Lubrificação, limpeza, lustração, revisão, carga e recarga,
        // conserto, restauração, blindagem, manutenção e conservação de
        // máquinas, veículos, aparelhos, equipamentos, motores, elevadores
        // ou de qualquer objeto" — único item usado neste sistema (oficina
        // mecânica), desdobro nacional "01" (o único listado pra 14.01).
        '14.01' => '140101',
    ];

    public static function resolver(string $codigoServicoFederal): string
    {
        $codigo = trim($codigoServicoFederal);

        if (!isset(self::MAPA[$codigo])) {
            throw new \InvalidArgumentException(
                "Não existe mapeamento conhecido de cTribNac para o código de serviço '{$codigo}'. " .
                'Confirme o desdobro nacional correto em gov.br/nfse/pt-br/mei-e-demais-empresas/codigos-de-tributacao-nacional-nbs ' .
                'antes de adicionar ao CodigoTributacaoNacionalResolver::MAPA.',
            );
        }

        return self::MAPA[$codigo];
    }
}
