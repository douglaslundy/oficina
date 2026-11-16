<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Configuracao;
use App\Models\NotaFiscal;

/**
 * Veículo da OS de origem para o grupo de transporte da NF-e
 * (`transp/veicTransp`: placa + UF) — o bloco "Placa do veículo" do DANFE.
 * Fonte única pros motores (NFEPHP, Spedy); a Focus não tem campo confirmado
 * na documentação e segue só com o infCpl.
 *
 * Só devolve algo quando é seguro pro XML:
 *  - placa no padrão antigo (AAA9999) ou Mercosul (AAA9A99): o XSD só aceita isso;
 *  - operação dentro da UF do emitente: veicTransp em operação interestadual
 *    é rejeitado pela SEFAZ (rej. 868);
 *  - UF do emitente conhecida: o XSD exige a UF do veículo e o cadastro do
 *    veículo não guarda UF — usa-se a do emitente (limite conhecido).
 */
final class VeiculoTransporteResolver
{
    /** @return array{placa: string, uf: string}|null */
    public static function resolver(NotaFiscal $nota, ?Configuracao $config): ?array
    {
        $os = $nota->os_id ? $nota->ordemServico : null;
        if ($os === null) {
            return null;
        }

        $placa = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($os->veiculo_placa ?: $os->veiculo?->placa)));
        if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placa)) {
            return null;
        }

        $uf = strtoupper(trim((string) ($config?->uf ?? '')));
        if (strlen($uf) !== 2) {
            return null;
        }

        $ufCliente = strtoupper(trim((string) ($nota->cliente?->uf ?? '')));
        if ($ufCliente !== '' && $ufCliente !== $uf) {
            return null;
        }

        return ['placa' => $placa, 'uf' => $uf];
    }
}
