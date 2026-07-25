<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NotaEntradaXmlParser;
use PHPUnit\Framework\TestCase;

class NotaEntradaXmlParserTest extends TestCase
{
    private function xmlValido(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
      <ide>
        <nNF>1234</nNF>
        <serie>1</serie>
        <dhEmi>2026-07-01T09:15:32-03:00</dhEmi>
      </ide>
      <emit>
        <CNPJ>12345678000199</CNPJ>
        <xNome>Auto Pecas Distribuidora LTDA</xNome>
      </emit>
      <det nItem="1">
        <prod>
          <cProd>FORN-001</cProd>
          <cEAN>7891234567890</cEAN>
          <xProd>FILTRO DE OLEO XPTO</xProd>
          <qCom>10.0000</qCom>
          <vUnCom>15.5000</vUnCom>
        </prod>
      </det>
      <det nItem="2">
        <prod>
          <cProd>FORN-002</cProd>
          <cEAN>SEM GTIN</cEAN>
          <xProd>PASTILHA DE FREIO GENERICA</xProd>
          <qCom>4.0000</qCom>
          <vUnCom>42.0000</vUnCom>
        </prod>
      </det>
      <total>
        <ICMSTot>
          <vNF>323.00</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }

    public function test_extrai_dados_da_nota(): void
    {
        $parser    = new NotaEntradaXmlParser();
        $resultado = $parser->parse($this->xmlValido());

        $this->assertSame('35260712345678000199550010000012340000000001', $resultado['chave_acesso']);
        $this->assertSame('1234', $resultado['numero_nf']);
        $this->assertSame('1', $resultado['serie']);
        $this->assertSame('2026-07-01', $resultado['data_emissao']);
        $this->assertSame('Auto Pecas Distribuidora LTDA', $resultado['fornecedor_nome']);
        $this->assertSame('12345678000199', $resultado['fornecedor_cnpj']);
        $this->assertSame(323.00, $resultado['valor_total']);
        $this->assertCount(2, $resultado['itens']);
    }

    public function test_item_com_codigo_de_barras(): void
    {
        $parser    = new NotaEntradaXmlParser();
        $resultado = $parser->parse($this->xmlValido());
        $item      = $resultado['itens'][0];

        $this->assertSame('7891234567890', $item['codigo_barras']);
        $this->assertSame('FILTRO DE OLEO XPTO', $item['descricao']);
        $this->assertSame(10.0, $item['quantidade']);
        $this->assertSame(15.5, $item['valor_unitario']);
    }

    public function test_item_sem_gtin_vira_codigo_de_barras_nulo(): void
    {
        $parser    = new NotaEntradaXmlParser();
        $resultado = $parser->parse($this->xmlValido());
        $item      = $resultado['itens'][1];

        $this->assertNull($item['codigo_barras']);
        $this->assertSame('PASTILHA DE FREIO GENERICA', $item['descricao']);
    }

    public function test_xml_sem_infnfe_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $parser = new NotaEntradaXmlParser();
        $parser->parse('<xml><foo>bar</foo></xml>');
    }

    public function test_xml_malformado_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $parser = new NotaEntradaXmlParser();
        $parser->parse('<not-valid-xml');
    }

    private function xmlComDadosFiscais(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
      <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-07-01T09:15:32-03:00</dhEmi></ide>
      <emit><CNPJ>12345678000199</CNPJ><xNome>Auto Pecas LTDA</xNome></emit>
      <det nItem="1">
        <prod>
          <cEAN>7891234567890</cEAN><xProd>FILTRO DE OLEO</xProd>
          <NCM>84212300</NCM><CFOP>5405</CFOP><uCom>PC</uCom>
          <qCom>10.0000</qCom><vUnCom>15.5000</vUnCom>
        </prod>
        <imposto><ICMS><ICMS60><orig>0</orig><CST>60</CST></ICMS60></ICMS></imposto>
      </det>
      <det nItem="2">
        <prod>
          <cEAN>7899999999999</cEAN><xProd>PASTILHA DE FREIO</xProd>
          <NCM>8708.30.90</NCM><CFOP>6404</CFOP><CEST>0100100</CEST><uCom>PAR</uCom>
          <qCom>4.0000</qCom><vUnCom>42.0000</vUnCom>
        </prod>
        <imposto><ICMS><ICMSSN500><orig>1</orig><CSOSN>500</CSOSN></ICMSSN500></ICMS></imposto>
      </det>
      <det nItem="3">
        <prod>
          <cEAN>7888888888888</cEAN><xProd>OLEO MOTOR 5W30</xProd>
          <NCM>27101932</NCM><CFOP>5102</CFOP><uCom>L</uCom>
          <qCom>20.0000</qCom><vUnCom>28.0000</vUnCom>
        </prod>
        <imposto><ICMS><ICMS00><orig>0</orig><CST>00</CST></ICMS00></ICMS></imposto>
      </det>
      <det nItem="4">
        <prod>
          <cEAN>7877777777777</cEAN><xProd>ITEM COM CST REVOGADO</xProd>
          <NCM>1234567</NCM><CFOP>5102</CFOP><uCom>UN</uCom>
          <qCom>1.0000</qCom><vUnCom>10.0000</vUnCom>
        </prod>
        <imposto><ICMS><ICMS12><orig>99</orig><CST>12</CST></ICMS12></ICMS></imposto>
      </det>
      <total><ICMSTot><vNF>1000.00</vNF></ICMSTot></total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }

    public function test_extrai_ncm_cfop_e_unidade(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('84212300', $itens[0]['ncm']);
        $this->assertSame('5405', $itens[0]['cfop']);
        $this->assertSame('PC', $itens[0]['unidade']);
    }

    public function test_ncm_com_pontuacao_e_normalizado(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('87083090', $itens[1]['ncm']);
        $this->assertSame('0100100', $itens[1]['cest']);
    }

    public function test_deriva_st_de_cst_60(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('ST', $itens[0]['tributacao_icms']);
        $this->assertSame(0, $itens[0]['origem']);
        $this->assertSame('60', $itens[0]['cst_csosn']);
    }

    public function test_deriva_st_de_csosn_500(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('ST', $itens[1]['tributacao_icms']);
        $this->assertSame(1, $itens[1]['origem']);
        $this->assertSame('500', $itens[1]['cst_csosn']);
    }

    public function test_deriva_normal_de_cst_00(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('NORMAL', $itens[2]['tributacao_icms']);
    }

    public function test_cst_revogado_e_dados_invalidos_viram_null(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertNull($itens[3]['tributacao_icms'], 'CST 12 não pode ser assumido como NORMAL');
        $this->assertNull($itens[3]['ncm'],    'NCM de 7 dígitos não pode ser gravado');
        $this->assertNull($itens[3]['origem'], 'origem 99 não pode ser gravada');
    }

    public function test_item_sem_bloco_de_imposto_nao_quebra(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlValido())['itens'];

        $this->assertNull($itens[0]['ncm']);
        $this->assertNull($itens[0]['tributacao_icms']);
        $this->assertSame('FILTRO DE OLEO XPTO', $itens[0]['descricao']);
    }
}
