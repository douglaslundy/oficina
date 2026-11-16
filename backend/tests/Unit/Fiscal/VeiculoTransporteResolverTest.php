<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\OrdemServico;
use App\Models\Veiculo;
use App\Services\Fiscal\Pdf\DanfeRenderer;
use App\Services\Fiscal\VeiculoTransporteResolver as R;
use Tests\TestCase;

/**
 * Placa do veículo da OS no grupo de transporte da NF-e (transp/veicTransp,
 * o bloco "Placa do veículo" do DANFE). 2026-11-16: só ia no infCpl.
 */
class VeiculoTransporteResolverTest extends TestCase
{
    private function cfg(string $uf = 'MG'): Configuracao
    {
        $c = new Configuracao();
        $c->uf = $uf;
        return $c;
    }

    private function nota(?string $placa, ?string $ufCliente = 'MG', ?Veiculo $veiculo = null): NotaFiscal
    {
        $os = new OrdemServico();
        $os->veiculo_placa = $placa;
        $os->setRelation('veiculo', $veiculo);

        $cliente = new Cliente();
        $cliente->uf = $ufCliente;

        $n = new NotaFiscal();
        $n->os_id = 'os-1';
        $n->setRelation('ordemServico', $os);
        $n->setRelation('cliente', $cliente);
        return $n;
    }

    public function test_placa_da_os_vira_veiculo_com_uf_do_emitente(): void
    {
        $this->assertSame(['placa' => 'ABC1D23', 'uf' => 'MG'], R::resolver($this->nota('abc-1d23'), $this->cfg()));
    }

    public function test_cai_na_placa_do_veiculo_cadastrado(): void
    {
        $v = new Veiculo();
        $v->placa = 'BRA2E19';

        $this->assertSame('BRA2E19', R::resolver($this->nota(null, 'MG', $v), $this->cfg())['placa']);
    }

    public function test_sem_os_ou_sem_placa_retorna_null(): void
    {
        $this->assertNull(R::resolver(new NotaFiscal(), $this->cfg()));
        $this->assertNull(R::resolver($this->nota(null), $this->cfg()));
    }

    public function test_placa_fora_do_formato_nao_vai_pro_xml(): void
    {
        $this->assertNull(R::resolver($this->nota('SEM PLACA'), $this->cfg()));
        $this->assertNull(R::resolver($this->nota('12345'), $this->cfg()));
    }

    public function test_operacao_interestadual_nao_leva_veiculo(): void
    {
        // Rejeição 868 (veicTransp em operação interestadual).
        $this->assertNull(R::resolver($this->nota('ABC1D23', 'SP'), $this->cfg('MG')));
    }

    public function test_sem_uf_do_emitente_nao_leva_veiculo(): void
    {
        $this->assertNull(R::resolver($this->nota('ABC1D23', 'MG'), $this->cfg('')));
    }

    public function test_danfe_le_placa_e_uf_do_xml(): void
    {
        $xml = '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe><transp><modFrete>9</modFrete>'
            . '<veicTransp><placa>ABC1D23</placa><UF>MG</UF></veicTransp></transp></infNFe></NFe>';

        $this->assertSame(['placa' => 'ABC1D23', 'uf' => 'MG'], DanfeRenderer::veiculoTransporteDoXml($xml));
        $this->assertNull(DanfeRenderer::veiculoTransporteDoXml('<NFe/>'));
        $this->assertNull(DanfeRenderer::veiculoTransporteDoXml(null));
        $this->assertNull(DanfeRenderer::veiculoTransporteDoXml('lixo'));
    }
}
