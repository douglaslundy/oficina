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
}
