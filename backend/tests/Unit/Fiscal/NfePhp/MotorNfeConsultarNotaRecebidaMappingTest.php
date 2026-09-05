<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\NfePhp\MotorNfe;
use Mockery;
use NFePHP\NFe\Tools;
use Tests\TestCase;

/**
 * Testa MotorNfe::interpretarRespostaDistDFe() isoladamente — o método puro
 * (sem rede nem certificado) extraído de consultarNotaRecebida(), mesmo
 * padrão já usado em MotorNfseConsultarMappingTest /
 * MotorNfeConsultarTest (private + ReflectionMethod).
 *
 * Os XMLs de fixture seguem o schema oficial confirmado em
 * vendor/nfephp-org/sped-nfe/schemes/PL_010_V1.30/retDistDFeInt_v1.01.xsd:
 * raiz `retDistDFeInt` (tpAmb/verAplic/cStat/xMotivo/dhResp/ultNSU/maxNSU e
 * um `loteDistDFeInt` OPCIONAL, minOccurs=0, com até 50 `docZip`), cada
 * `docZip` sendo base64 de um conteúdo gzip com o atributo `schema`
 * identificando o documento (resNFe_v1.xx.xsd, procNFe_v4.00.xsd, ...).
 *
 * O XSD NÃO enumera valores de cStat (o tipo é TStat genérico), então os
 * códigos que aparecem abaixo são só ruído realista de fixture — nenhuma
 * asserção depende deles, e a implementação usa a AUSÊNCIA de docZip como
 * sinal estrutural de "não encontrado".
 *
 * Usa Tests\TestCase (e não PHPUnit\Framework\TestCase puro) porque o
 * método sob teste usa a facade Log, que precisa do container do Laravel
 * bootado. Nenhum teste aqui toca o banco.
 */
class MotorNfeConsultarNotaRecebidaMappingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function invocar(string $xml, string $chave, Tools $tools)
    {
        $motor = new MotorNfe();
        $m = new \ReflectionMethod(MotorNfe::class, 'interpretarRespostaDistDFe');
        $m->setAccessible(true);

        return $m->invoke($motor, $xml, $chave, $tools);
    }

    private function docZipComProcNfe(): string
    {
        $procNfeXml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
          <NFe><infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
            <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-07-01T09:15:32-03:00</dhEmi></ide>
            <emit><CNPJ>12345678000199</CNPJ><xNome>Fornecedor Terceiro</xNome></emit>
            <det nItem="1"><prod><cEAN>7891234567890</cEAN><xProd>PECA X</xProd><qCom>2.0000</qCom><vUnCom>50.0000</vUnCom><NCM>84212300</NCM></prod></det>
          </infNFe></NFe>
        </nfeProc>
        XML;

        return base64_encode((string) gzencode($procNfeXml));
    }

    public function test_resposta_com_procnfe_retorna_completa_com_itens(): void
    {
        $docZip = $this->docZipComProcNfe();
        $xml = <<<XML
        <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>138</cStat><xMotivo>Documento localizado</xMotivo>
          <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>1</ultNSU><maxNSU>1</maxNSU>
          <loteDistDFeInt>
            <docZip NSU="1" schema="procNFe_v4.00.xsd">{$docZip}</docZip>
          </loteDistDFeInt>
        </retDistDFeInt>
        XML;

        $tools = Mockery::mock(Tools::class)->shouldIgnoreMissing();

        $resultado = $this->invocar($xml, '35260712345678000199550010000012340000000001', $tools);

        $this->assertSame('COMPLETA', $resultado->status);
        $this->assertSame('Fornecedor Terceiro', $resultado->dados['fornecedor_nome']);
        $this->assertCount(1, $resultado->dados['itens']);
        $this->assertSame('84212300', $resultado->dados['itens'][0]['ncm']);
    }

    public function test_resposta_sem_lote_retorna_nao_encontrada(): void
    {
        // loteDistDFeInt é minOccurs="0" no XSD — a resposta sem ele é
        // válida e significa "nenhum documento". Nenhum cStat específico é
        // consultado pela implementação; só a ausência estrutural do lote.
        $xml = <<<XML
        <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>137</cStat><xMotivo>Nenhum documento localizado</xMotivo>
          <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>0</ultNSU><maxNSU>0</maxNSU>
        </retDistDFeInt>
        XML;

        $tools = Mockery::mock(Tools::class)->shouldIgnoreMissing();

        $resultado = $this->invocar($xml, 'CHAVE-INEXISTENTE', $tools);

        $this->assertSame('NAO_ENCONTRADA', $resultado->status);
    }

    public function test_resposta_so_com_resnfe_manifesta_ciencia_e_retorna_aguardando(): void
    {
        // resNFe é o RESUMO (sem itens) — nunca dá pra montar um lançamento
        // de entrada a partir dele. Mesmo caminho seguro de Spedy/Focus:
        // manifesta ciência e devolve AGUARDANDO_MANIFESTACAO, nunca
        // inventa itens.
        $resNfeFake = base64_encode((string) gzencode('<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"></resNFe>'));
        $xml = <<<XML
        <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>138</cStat><xMotivo>Documento localizado</xMotivo>
          <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>1</ultNSU><maxNSU>1</maxNSU>
          <loteDistDFeInt>
            <docZip NSU="1" schema="resNFe_v1.01.xsd">{$resNfeFake}</docZip>
          </loteDistDFeInt>
        </retDistDFeInt>
        XML;

        $tools = Mockery::mock(Tools::class);
        $tools->shouldReceive('sefazManifesta')->once()->with('CHAVE-X', Tools::EVT_CIENCIA);

        $resultado = $this->invocar($xml, 'CHAVE-X', $tools);

        $this->assertSame('AGUARDANDO_MANIFESTACAO', $resultado->status);
    }

    public function test_falha_ao_manifestar_ciencia_nao_derruba_a_consulta(): void
    {
        // sefazManifesta() é best-effort: se a SEFAZ recusar o evento de
        // ciência, o usuário ainda precisa receber AGUARDANDO_MANIFESTACAO
        // (e não um ERRO técnico), porque o estado real da nota continua
        // sendo "resumo disponível, XML completo ainda não".
        $resNfeFake = base64_encode((string) gzencode('<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"></resNFe>'));
        $xml = <<<XML
        <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>138</cStat><xMotivo>Documento localizado</xMotivo>
          <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>1</ultNSU><maxNSU>1</maxNSU>
          <loteDistDFeInt>
            <docZip NSU="1" schema="resNFe_v1.01.xsd">{$resNfeFake}</docZip>
          </loteDistDFeInt>
        </retDistDFeInt>
        XML;

        $tools = Mockery::mock(Tools::class);
        $tools->shouldReceive('sefazManifesta')->once()->andThrow(new \RuntimeException('SEFAZ fora do ar'));

        $resultado = $this->invocar($xml, 'CHAVE-Y', $tools);

        $this->assertSame('AGUARDANDO_MANIFESTACAO', $resultado->status);
    }

    public function test_resposta_nao_xml_retorna_erro(): void
    {
        $tools = Mockery::mock(Tools::class)->shouldIgnoreMissing();

        $resultado = $this->invocar('isto nao e xml <<<', 'CHAVE-Z', $tools);

        $this->assertSame('ERRO', $resultado->status);
        $this->assertStringContainsString('Distribuição DFe', (string) $resultado->mensagemErro);
    }
}
