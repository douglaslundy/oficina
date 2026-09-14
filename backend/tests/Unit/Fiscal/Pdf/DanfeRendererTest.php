<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\Pdf;

use App\Models\NotaFiscal;
use App\Services\Fiscal\Pdf\DanfeRenderer;
use PHPUnit\Framework\TestCase;

class DanfeRendererTest extends TestCase
{
    public function test_dados_para_template_sem_xml_retorna_itens_vazios(): void
    {
        $nota = new NotaFiscal(['numero' => 1, 'valor_total' => 100]);
        $nota->setRelation('itens', collect());

        $renderer = new DanfeRenderer();
        $dados = $renderer->dadosParaTemplate($nota);

        $this->assertSame([], $dados['itens']);
        $this->assertSame($nota, $dados['nota']);
        // Sem conexão de banco disponível neste teste (PHPUnit\Framework\TestCase
        // puro), Configuracao::first() não pode ser chamado de verdade —
        // degrada pra array vazio em vez de lançar \Error fatal.
        $this->assertSame([], $dados['empresa']);
    }

    /**
     * Pedido explícito do usuário (2026-09-14, análise visual comparando com
     * os modelos oficiais): a tabela de itens do DANFE de referência tem
     * colunas (código do produto, CST/CSOSN, unidade) que a extração do XML
     * não capturava — só descrição/NCM/CFOP/qtde/valores. Sem elas o item
     * ficava incompleto na tabela expandida.
     */
    public function test_dados_para_template_extrai_codigo_csosn_e_unidade_do_xml(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe">
  <NFe><infNFe Id="NFe31260950388509000121550010000000014082390387" versao="4.00">
    <det nItem="1">
      <prod>
        <cProd>FLT-001</cProd><xProd>Filtro de óleo</xProd><NCM>84212300</NCM>
        <CFOP>5102</CFOP><uCom>PC</uCom><qCom>2.0000</qCom><vUnCom>35.50</vUnCom><vProd>71.00</vProd>
      </prod>
      <imposto><ICMS><ICMSSN102><orig>0</orig><CSOSN>102</CSOSN></ICMSSN102></ICMS></imposto>
    </det>
  </infNFe></NFe>
</nfeProc>
XML;
        $nota = new NotaFiscal(['numero' => 1, 'valor_total' => 71, 'xml_retorno' => $xml]);
        $nota->setRelation('itens', collect());

        $renderer = new DanfeRenderer();
        $dados = $renderer->dadosParaTemplate($nota);

        $this->assertCount(1, $dados['itens']);
        $this->assertSame('FLT-001', $dados['itens'][0]['codigo']);
        $this->assertSame('102', $dados['itens'][0]['cst_csosn']);
        $this->assertSame('PC', $dados['itens'][0]['unidade']);
    }

    public function test_dados_para_template_com_xml_malformado_cai_no_fallback_sem_lancar(): void
    {
        $nota = new NotaFiscal(['numero' => 1, 'valor_total' => 100, 'xml_retorno' => '<<<not valid xml']);
        $nota->setRelation('itens', collect());

        $renderer = new DanfeRenderer();
        $dados = $renderer->dadosParaTemplate($nota);

        $this->assertSame([], $dados['itens']);
        $this->assertSame($nota, $dados['nota']);
    }
}
