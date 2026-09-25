<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\OrdemServico;
use App\Services\Fiscal\InformacoesComplementaresResolver as R;
use Tests\TestCase;

class InformacoesComplementaresResolverTest extends TestCase
{
    private function cfg(string $regime): Configuracao
    {
        $c = new Configuracao();
        $c->regime_tributario = $regime;
        return $c;
    }

    private function nota(?OrdemServico $os = null, ?string $obs = null): NotaFiscal
    {
        $n = new NotaFiscal();
        $n->observacoes = $obs;
        if ($os) {
            $n->os_id = 'os-1';
            $n->setRelation('ordemServico', $os);
        }
        return $n;
    }

    private function os(): OrdemServico
    {
        $os = new OrdemServico();
        $os->veiculo_placa = 'ABC1D23';
        $os->veiculo_descricao = 'Fiat Fiorino 2019';
        $os->km_atual = 84500;
        $os->setRelation('veiculo', null);
        return $os;
    }

    public function test_simples_com_os_traz_optante_placa_modelo_e_km(): void
    {
        $t = R::montar($this->nota($this->os()), $this->cfg('Simples Nacional'), false, R::LIMITE_NFE);

        $this->assertStringContainsString('optante pelo Simples Nacional', $t);
        $this->assertStringContainsString('Placa: ABC1D23', $t);
        $this->assertStringContainsString('Modelo: Fiat Fiorino 2019', $t);
        $this->assertStringContainsString('KM: 84500', $t);
    }

    public function test_regime_normal_nao_menciona_simples(): void
    {
        $t = R::montar($this->nota($this->os()), $this->cfg('Lucro Presumido'), false, R::LIMITE_NFE);

        $this->assertStringNotContainsString('Simples', $t);
        $this->assertStringContainsString('Placa: ABC1D23', $t);
    }

    public function test_observacoes_so_entram_quando_solicitado(): void
    {
        $n = $this->nota(null, 'Pagamento em 30 dias');

        $this->assertStringContainsString('Pagamento em 30 dias', R::montar($n, $this->cfg('Simples Nacional'), true, R::LIMITE_NFE));
        $this->assertStringNotContainsString('Pagamento', R::montar($n, $this->cfg('Simples Nacional'), false, R::LIMITE_NFE));
    }

    public function test_sem_nada_a_dizer_retorna_null(): void
    {
        $this->assertNull(R::montar($this->nota(), $this->cfg('Lucro Presumido'), false, R::LIMITE_NFE));
    }

    public function test_respeita_o_limite(): void
    {
        $t = R::montar($this->nota($this->os()), $this->cfg('Simples Nacional'), false, 40);

        $this->assertSame(40, mb_strlen($t));
    }

    /** Mesmo padrão de TSString no XSD da NFS-e nacional (E1235 real de 2026-09-25). */
    private const PADRAO_XSD = '/^(?:[!-\x{00FF}][ -\x{00FF}]*[!-\x{00FF}]|[!-\x{00FF}])$/u';

    public function test_travessao_do_modelo_do_veiculo_nao_quebra_o_padrao_do_schema(): void
    {
        $os = new OrdemServico();
        $os->veiculo_placa = 'ABC1D23';
        $os->veiculo_descricao = 'HONDA CG 160 CARGO C — 2019 — ABC1D23';
        $os->km_atual = 40332;
        $os->setRelation('veiculo', null);

        $t = R::montar($this->nota($os), $this->cfg('Simples Nacional'), false, R::LIMITE_NFSE);

        $this->assertStringContainsString('HONDA CG 160 CARGO C - 2019 - ABC1D23', $t);
        $this->assertMatchesRegularExpression(self::PADRAO_XSD, $t);
    }

    public function test_sanitizar_remove_emoji_quebra_de_linha_e_espacos_nas_pontas(): void
    {
        $t = R::sanitizar("  Troca\r\nde óleo 🔧 “ok” – ção\t ");

        $this->assertSame('Troca de óleo "ok" - ção', $t);
        $this->assertMatchesRegularExpression(self::PADRAO_XSD, $t);
    }

    public function test_texto_so_com_caracteres_invalidos_vira_null(): void
    {
        $this->assertNull(R::montar($this->nota(null, '🔧🔧'), $this->cfg('Lucro Presumido'), true, R::LIMITE_NFE));
    }

    public function test_informacoes_complementares_da_os_entram_depois_do_veiculo(): void
    {
        $os = $this->os();
        $os->informacoes_complementares = 'Contrato 123/2026 - Centro de custo BH';

        $t = R::montar($this->nota($os), $this->cfg('Simples Nacional'), false, R::LIMITE_NFE);

        $this->assertStringContainsString('KM: 84500 Contrato 123/2026 - Centro de custo BH', $t);
    }

    public function test_pdf_le_o_texto_que_foi_no_xml_da_nota(): void
    {
        $nfe = new NotaFiscal();
        $nfe->xml_retorno = '<NFe><infAdic><infCpl>Placa: A &amp; B | KM: 1</infCpl></infAdic></NFe>';
        $nfse = new NotaFiscal();
        $nfse->xml_retorno = '<DPS><serv><infoCompl><xInfComp>Simples. Placa: X</xInfComp></infoCompl></serv></DPS>';

        $this->assertSame('Placa: A & B | KM: 1', $nfe->informacoes_complementares_xml);
        $this->assertSame('Simples. Placa: X', $nfse->informacoes_complementares_xml);
        $this->assertNull((new NotaFiscal())->informacoes_complementares_xml);
    }

    public function test_pdf_cai_no_snapshot_quando_o_xml_do_provedor_nao_traz_o_campo(): void
    {
        $n = new NotaFiscal();
        $n->xml_retorno = '<NFe><infNFe/></NFe>';
        $n->informacoes_complementares = 'Empresa optante pelo Simples Nacional. Placa: X';

        $this->assertSame('Empresa optante pelo Simples Nacional. Placa: X', $n->informacoes_complementares_xml);

        // O que está no XML tem prioridade sobre o snapshot.
        $n->xml_retorno = '<NFe><infAdic><infCpl>do XML</infCpl></infAdic></NFe>';
        $this->assertSame('do XML', $n->informacoes_complementares_xml);
    }
}
