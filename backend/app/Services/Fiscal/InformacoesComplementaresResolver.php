<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Configuracao;
use App\Models\NotaFiscal;

/**
 * Monta o texto de "dados adicionais" (NF-e infCpl / NFS-e xInfComp) que o
 * cliente corporativo (Correios) exige em toda nota de oficina:
 *   - menção a "optante pelo Simples Nacional" (só quando o regime é Simples/MEI);
 *   - Placa, Modelo e KM do veículo, vindos da OS de origem.
 *
 * Fonte única pros 3 motores (NFEPHP, Spedy, Focus) — cada um só decide ONDE
 * põe o texto no seu payload, nunca COMO ele é montado.
 *
 * No NFS-e, `observacoes` já é usado como descrição do serviço (xDescServ),
 * então só entra no texto de NF-e ($incluirObservacoes = true), onde antes era
 * ignorado pelos motores.
 */
final class InformacoesComplementaresResolver
{
    public const LIMITE_NFE  = 5000; // infCpl (NT / MOC)
    public const LIMITE_NFSE = 255;  // xInfComp na DPS nacional (DTO da lib)

    public static function montar(NotaFiscal $nota, ?Configuracao $config, bool $incluirObservacoes, int $limite): ?string
    {
        $partes = [];

        $regime = trim((string) ($config?->regime_tributario ?? ''));
        if ($regime !== '' && CrtResolver::resolver($regime) === 1) {
            $partes[] = 'Empresa optante pelo Simples Nacional.';
        }

        $os = $nota->os_id ? $nota->ordemServico : null;
        if ($os !== null) {
            $veiculo = [];
            if (!empty($os->veiculo_placa)) {
                $veiculo[] = 'Placa: ' . $os->veiculo_placa;
            }
            $modelo = $os->veiculo_descricao ?: $os->veiculo?->modelo;
            if (!empty($modelo)) {
                $veiculo[] = 'Modelo: ' . $modelo;
            }
            if ($os->km_atual !== null) {
                $veiculo[] = 'KM: ' . $os->km_atual;
            }
            if ($veiculo !== []) {
                $partes[] = implode(' | ', $veiculo);
            }
        }

        if ($os !== null && !empty($os->informacoes_complementares)) {
            $partes[] = trim((string) $os->informacoes_complementares);
        }

        if ($incluirObservacoes && !empty($nota->observacoes)) {
            $partes[] = trim((string) $nota->observacoes);
        }

        if ($partes === []) {
            return null;
        }

        $texto = self::sanitizar(implode(' ', $partes));

        return $texto === '' ? null : mb_substr($texto, 0, $limite);
    }

    /**
     * O schema fiscal (TSString da NFS-e nacional; TString da NF-e) só aceita
     * caracteres de ! a ÿ (U+0021–U+00FF), sem espaço nas pontas. Bug real de
     * produção (2026-09-25, E1235 na ADN): o modelo do veículo digitado na OS
     * tinha travessão ("HONDA CG 160 CARGO C — 2019") e a nota inteira foi
     * recusada por 'Pattern constraint failed'. Texto livre de OS/observação
     * é digitado por humano — nunca confiar nele cru num campo de schema.
     */
    public static function sanitizar(string $texto): string
    {
        $texto = strtr($texto, [
            '–' => '-', '—' => '-', '―' => '-', '−' => '-',
            '‘' => "'", '’' => "'", '“' => '"', '”' => '"',
            '…' => '...',
        ]);
        // Quebras de linha/tabs viram espaço; o que sobrar fora de
        // U+0020–U+007E e U+00A0–U+00FF (emoji, CJK, controles C1 etc.) é
        // descartado. NBSP (U+00A0) é aceito pelo schema, mas vira espaço comum.
        $texto = preg_replace('/[\r\n\t\x{00A0}]+/u', ' ', $texto) ?? '';
        $texto = preg_replace('/[^\x{0020}-\x{007E}\x{00A1}-\x{00FF}]/u', '', $texto) ?? '';
        $texto = preg_replace('/ {2,}/', ' ', $texto) ?? '';

        return trim($texto);
    }
}
