<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\NfePhp\MotorNfe;
use PHPUnit\Framework\TestCase;

/**
 * Testes de integração real com Tools (que fala com a rede/SEFAZ) não são
 * testáveis sem certificado e credenciais reais — mesma limitação que
 * MotorNfse tem hoje (seus testes cobrem só montarDps() e o mapeamento puro
 * de resultado, nunca emitir() fim-a-fim). Este arquivo cobre o parsing de
 * resposta (métodos privados, testados via reflection) e o novo named
 * constructor.
 *
 * Uma tentativa foi feita nesta sessão de também testar tentarEpec()/
 * Tools::sefazEPEC() fim-a-fim com um certificado de teste gerado em tempo
 * de execução (openssl_pkey_new() + openssl_pkcs12_export()) — funcionou
 * pra reproduzir manualmente o bug do vendor descrito no docblock de
 * MotorNfe::tentarEpec() (ver task-4-report.md), mas openssl_pkey_new()
 * falha neste ambiente de CI/dev ("system library::No such process" — falta
 * de um openssl.cnf configurado pro extension do PHP), então não foi
 * incluído no suite comitado — não é confiável em todo ambiente.
 *
 * processarRespostaAutorizacao() e extrairCStatEvento() (a divisão de
 * responsabilidade criada nesta task pra isolar o parsing puro, sem I/O)
 * cobrem o essencial: nunca classificar uma resposta ambígua/desconhecida
 * como sucesso.
 */
class MotorNfeEmitirTest extends TestCase
{
    public function test_emissao_resultado_contingencia(): void
    {
        // Assinatura atualizada pelo fix wave pós-revisão da Etapa C2
        // (Finding 2): contingencia() agora exige chave de acesso e número
        // como 1º/2º args, antes de $xml — sem eles, MotorNfe::retransmitir()
        // e o DANFE de contingência não têm chave pra trabalhar (ver
        // docblock de EmissaoResultado::contingencia()).
        $r = EmissaoResultado::contingencia('31260800000000000000550010000000011234567890', '11', '<xml/>', 'ref-1');

        $this->assertSame('CONTINGENCIA', $r->status);
        $this->assertSame('31260800000000000000550010000000011234567890', $r->chave);
        $this->assertSame('11', $r->numero);
        $this->assertSame('<xml/>', $r->xml);
        $this->assertSame('ref-1', $r->referenciaExterna);
    }

    public function test_processar_resposta_autorizacao_reconhece_cstat_100(): void
    {
        $respostaXml = <<<'XML'
<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe">
  <cStat>103</cStat>
  <protNFe>
    <infProt>
      <cStat>100</cStat>
      <xMotivo>Autorizado o uso da NF-e</xMotivo>
      <chNFe>31260800000000000000550010000000011234567890</chNFe>
      <nProt>135260000000000</nProt>
    </infProt>
  </protNFe>
</retEnviNFe>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaAutorizacao');
        $metodo->setAccessible(true);
        // 4º arg $numeroReal — Finding 3 do fix wave: agora obrigatório,
        // é o nNF alocado por emitir() antes de transmitir; threading
        // provado pela asserção de $resultado->numero abaixo.
        $resultado = $metodo->invoke($motor, $respostaXml, 'ref-1', '<xml-enviado/>', '42');

        $this->assertSame('AUTORIZADA', $resultado->status);
        $this->assertSame('31260800000000000000550010000000011234567890', $resultado->chave);
        $this->assertSame('135260000000000', $resultado->protocolo);
        $this->assertSame('42', $resultado->numero);
    }

    /**
     * Pedido explícito do usuário (2026-09-14): "o XML deve ser nos moldes
     * fiscais que o mercado exige com todas as informações necessárias".
     * Achado real ao investigar: `xml_retorno` salvava só a NF-e ASSINADA
     * que ENVIAMOS pra SEFAZ — nunca o `nfeProc` (NFe + protNFe), que é o
     * documento oficial completo que qualquer ERP/contador exige (prova a
     * autorização; um XML sem `protNFe` não prova nada). Corrigido via
     * `NFePHP\NFe\Complements::toAuthorize()` — helper do próprio vendor,
     * nunca "inventado" à mão. Este teste usa um NFe MINIMAMENTE assinado
     * (com `Signature/DigestValue`, exigido pelo helper pra cross-checar
     * contra o `digVal` do protocolo) pra provar a junção de verdade —
     * os outros testes desta classe usam `<xml-enviado/>` (sem assinatura),
     * que cai no fallback (catch) e continua devolvendo o XML como veio,
     * sem quebrar.
     */
    public function test_processar_resposta_autorizacao_monta_nfeproc_com_protocolo_anexado(): void
    {
        $xmlEnviado = <<<'XML'
<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe31260800000000000000550010000000011234567890" versao="4.00"><ide><nNF>42</nNF></ide></infNFe><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo><DigestValue>DIGESTABC123</DigestValue></SignedInfo></Signature></NFe>
XML;
        $respostaXml = <<<'XML'
<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe">
  <cStat>103</cStat>
  <protNFe versao="4.00">
    <infProt>
      <cStat>100</cStat>
      <xMotivo>Autorizado o uso da NF-e</xMotivo>
      <chNFe>31260800000000000000550010000000011234567890</chNFe>
      <nProt>135260000000000</nProt>
      <digVal>DIGESTABC123</digVal>
    </infProt>
  </protNFe>
</retEnviNFe>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaAutorizacao');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, 'ref-1', $xmlEnviado, '42');

        $this->assertSame('AUTORIZADA', $resultado->status);
        $this->assertStringContainsString('<nfeProc', $resultado->xml);
        $this->assertStringContainsString('<NFe', $resultado->xml);
        $this->assertStringContainsString('<protNFe', $resultado->xml);
        $this->assertStringContainsString('<nProt>135260000000000</nProt>', $resultado->xml);
    }

    public function test_processar_resposta_autorizacao_rejeitada_nao_vira_autorizada(): void
    {
        $respostaXml = <<<'XML'
<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe">
  <cStat>103</cStat>
  <protNFe>
    <infProt>
      <cStat>204</cStat>
      <xMotivo>Rejeição: Duplicidade de NF-e</xMotivo>
    </infProt>
  </protNFe>
</retEnviNFe>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaAutorizacao');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, 'ref-1', '<xml-enviado/>', '43');

        $this->assertSame('REJEITADA', $resultado->status);
        $this->assertStringContainsString('Duplicidade', $resultado->mensagemErro);
        // Finding 4 do fix wave: número já alocado/queimado antes da
        // transmissão precisa sobreviver mesmo quando a SEFAZ rejeita, senão
        // a retentativa (NfeService::montarNotaData() -> numeroReservado)
        // nunca o encontra e queima outro.
        $this->assertSame('43', $resultado->numero);
    }

    /**
     * Tools::sefazEnviaLote() retorna o corpo HTTP cru da resposta SOAP
     * (confirmado em sped-common/src/Soap/SoapCurl.php — sendRequest()
     * devolve $this->responseBody, o envelope completo, sem descascar
     * soap:Envelope/soap:Body) — não a string "nua" do retEnviNFe como os
     * dois testes acima assumem por simplicidade. Este teste prova que
     * processarRespostaAutorizacao() funciona igual mesmo com o envelope
     * SOAP em volta, porque o xpath usa // (busca em qualquer profundidade)
     * e o namespace do nfe: é resolvido corretamente independente da árvore
     * de ancestrais SOAP.
     */
    public function test_processar_resposta_autorizacao_funciona_dentro_de_envelope_soap(): void
    {
        $respostaXml = <<<'XML'
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
  <soap:Body>
    <nfeResultMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeAutorizacao4">
      <retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
        <tpAmb>2</tpAmb>
        <verAplic>SP_NFE_PL009_V4</verAplic>
        <cStat>104</cStat>
        <xMotivo>Lote processado</xMotivo>
        <cUF>31</cUF>
        <dhRecbto>2026-08-10T10:00:00-03:00</dhRecbto>
        <protNFe versao="4.00">
          <infProt Id="ID1234567890">
            <tpAmb>2</tpAmb>
            <verAplic>SP_NFE_PL009_V4</verAplic>
            <chNFe>31260800000000000000550010000000011234567890</chNFe>
            <dhRecbto>2026-08-10T10:00:00-03:00</dhRecbto>
            <nProt>135260000000001</nProt>
            <cStat>100</cStat>
            <xMotivo>Autorizado o uso da NF-e</xMotivo>
          </infProt>
        </protNFe>
      </retEnviNFe>
    </nfeResultMsg>
  </soap:Body>
</soap:Envelope>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaAutorizacao');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, 'ref-2', '<xml-enviado/>', '44');

        $this->assertSame('AUTORIZADA', $resultado->status);
        $this->assertSame('31260800000000000000550010000000011234567890', $resultado->chave);
        $this->assertSame('135260000000001', $resultado->protocolo);
        $this->assertSame('44', $resultado->numero);
    }

    public function test_processar_resposta_autorizacao_sem_protnfe_retorna_rejeitada_com_cstat_de_lote(): void
    {
        $respostaXml = <<<'XML'
<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe">
  <cStat>225</cStat>
  <xMotivo>Falha no Schema XML</xMotivo>
</retEnviNFe>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaAutorizacao');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, 'ref-3', '<xml-enviado/>', '45');

        $this->assertSame('REJEITADA', $resultado->status);
        $this->assertStringContainsString('225', $resultado->mensagemErro);
        $this->assertSame('45', $resultado->numero);
    }

    /**
     * A resposta de sefazEvento() (retEnvEvento) tem DOIS cStat: um no
     * nível do LOTE de eventos (aqui, 128 = "Lote de Evento Processado") e
     * um aninhado em retEvento/infEvento (aqui, 135 = evento efetivamente
     * registrado). Achado desta sessão: um xpath ingênuo //nfe:cStat pegaria
     * o do lote primeiro (ordem do documento) e classificaria errado —
     * extrairCStatEvento() precisa restringir a busca a dentro de
     * retEvento. Este teste prova que o cStat certo (135, do evento) é
     * extraído, não o de lote (128).
     */
    public function test_extrair_cstat_evento_ignora_cstat_de_lote_usa_cstat_do_evento(): void
    {
        $respostaXml = <<<'XML'
<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">
  <idLote>1</idLote>
  <tpAmb>2</tpAmb>
  <verAplic>SP_EPEC_PL009</verAplic>
  <cOrgao>31</cOrgao>
  <cStat>128</cStat>
  <xMotivo>Lote de Evento Processado</xMotivo>
  <retEvento versao="1.00">
    <infEvento Id="ID1101401234567890123456789012345678901234567890000001">
      <tpAmb>2</tpAmb>
      <verAplic>SP_EPEC_PL009</verAplic>
      <cOrgao>31</cOrgao>
      <cStat>135</cStat>
      <xMotivo>Evento registrado e vinculado a NF-e</xMotivo>
      <chNFe>31260800000000000000550010000000011234567890</chNFe>
      <tpEvento>110140</tpEvento>
      <dhRegEvento>2026-08-10T10:01:00-03:00</dhRegEvento>
      <nProt>135260000000002</nProt>
    </infEvento>
  </retEvento>
</retEnvEvento>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'extrairCStatEvento');
        $metodo->setAccessible(true);
        $cStat = $metodo->invoke($motor, $respostaXml);

        $this->assertSame('135', $cStat);
    }

    public function test_extrair_cstat_evento_retorna_null_para_xml_invalido(): void
    {
        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'extrairCStatEvento');
        $metodo->setAccessible(true);
        $cStat = $metodo->invoke($motor, 'isto nao e xml');

        $this->assertNull($cStat);
    }

    public function test_extrair_cstat_evento_retorna_string_vazia_quando_nao_ha_retevento(): void
    {
        $respostaXml = <<<'XML'
<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">
  <idLote>1</idLote>
  <tpAmb>2</tpAmb>
  <verAplic>SP_EPEC_PL009</verAplic>
  <cOrgao>31</cOrgao>
  <cStat>218</cStat>
  <xMotivo>Rejeicao: Lote de evento invalido</xMotivo>
</retEnvEvento>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'extrairCStatEvento');
        $metodo->setAccessible(true);
        $cStat = $metodo->invoke($motor, $respostaXml);

        $this->assertSame('', $cStat);
    }
}
