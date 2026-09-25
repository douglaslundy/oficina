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

        if ($incluirObservacoes && !empty($nota->observacoes)) {
            $partes[] = trim((string) $nota->observacoes);
        }

        if ($partes === []) {
            return null;
        }

        return mb_substr(implode(' ', $partes), 0, $limite);
    }
}
