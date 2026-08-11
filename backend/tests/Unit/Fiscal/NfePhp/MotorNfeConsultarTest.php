<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Models\NotaFiscal;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\NfePhp\MotorNfe;
use PHPUnit\Framework\TestCase;

/**
 * consultar()/cancelar() fim-a-fim não são testáveis sem certificado real
 * (mesma limitação de emitir(), documentada em MotorNfeEmitirTest) — este
 * arquivo cobre o parsing puro (processarRespostaConsulta()/
 * processarRespostaCancelamento(), extraídos especificamente pra serem
 * testáveis via reflection, mesmo padrão de processarRespostaAutorizacao())
 * e os guard clauses de retransmitir() pedidos no brief da Task 5.
 */
class MotorNfeConsultarTest extends TestCase
{
    public function test_retransmitir_sem_chave_de_acesso_retorna_erro(): void
    {
        $nota = new NotaFiscal(['referencia_externa' => 'nfe-sem-chave']);
        $motor = new MotorNfe();

        $resultado = $motor->retransmitir($nota, 'HOMOLOGACAO');

        $this->assertSame('ERRO', $resultado->status);
        $this->assertStringContainsString('sem chave de acesso', (string) $resultado->mensagemErro);
    }

    public function test_retransmitir_sem_xml_salvo_retorna_erro_apos_consultar(): void
    {
        // Nota tem chave mas nunca vai conseguir consultar de verdade sem
        // certificado real neste ambiente de teste — o método vai falhar em
        // consultar() primeiro e cair no catch de erro técnico, que também é
        // um resultado seguro (nunca reenvia às cegas). Este teste confirma
        // que o caminho "sem xml_retorno" não é alcançado silenciosamente
        // quando não há certificado configurado (cenário real de CI sem
        // Configuracao completa).
        $nota = new NotaFiscal(['referencia_externa' => 'nfe-2', 'chave_acesso' => str_repeat('1', 44)]);
        $motor = new MotorNfe();

        $resultado = $motor->retransmitir($nota, 'HOMOLOGACAO');

        $this->assertContains($resultado->status, ['ERRO']);
    }

    /**
     * Task 8: se a consulta prévia mostra que a nota já foi cancelada na
     * SEFAZ (ex.: admin cancelou manualmente enquanto ela estava em
     * contingência), retransmitir() precisa parar aí — não pode cair no
     * sefazEnviaLote() de novo. Sem este guard, o comando de reconciliação
     * hourly reenviaria a mesma nota cancelada indefinidamente. Mocka só
     * consultar() (I/O real de SEFAZ) e mantém retransmitir() real.
     */
    public function test_retransmitir_com_consulta_cancelada_nao_reenvia(): void
    {
        $nota = new NotaFiscal([
            'referencia_externa' => 'nfe-cancelada',
            'chave_acesso'       => str_repeat('1', 44),
            'xml_retorno'        => '<xml>ja teria sido reenviado se chegasse aqui</xml>',
        ]);

        $motor = $this->getMockBuilder(MotorNfe::class)
            ->onlyMethods(['consultar'])
            ->getMock();
        $motor->method('consultar')->willReturn(EmissaoResultado::cancelada($nota->chave_acesso));

        $resultado = $motor->retransmitir($nota, 'HOMOLOGACAO');

        $this->assertSame('CANCELADA', $resultado->status);
    }

    public function test_processar_resposta_consulta_reconhece_cstat_100_autorizada(): void
    {
        $respostaXml = <<<'XML'
<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <tpAmb>2</tpAmb>
  <verAplic>SP_NFE_PL009_V4</verAplic>
  <cStat>100</cStat>
  <xMotivo>Autorizado o uso da NF-e</xMotivo>
  <cUF>31</cUF>
  <dhRecbto>2026-08-10T10:00:00-03:00</dhRecbto>
  <chNFe>31260800000000000000550010000000011234567890</chNFe>
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
</retConsSitNFe>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaConsulta');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, '31260800000000000000550010000000011234567890');

        $this->assertSame('AUTORIZADA', $resultado->status);
        $this->assertSame('135260000000001', $resultado->protocolo);
    }

    public function test_processar_resposta_consulta_reconhece_cstat_101_cancelada(): void
    {
        $respostaXml = <<<'XML'
<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <tpAmb>2</tpAmb>
  <verAplic>SP_NFE_PL009_V4</verAplic>
  <cStat>101</cStat>
  <xMotivo>Cancelamento de NF-e homologado</xMotivo>
  <cUF>31</cUF>
  <dhRecbto>2026-08-10T10:00:00-03:00</dhRecbto>
  <chNFe>31260800000000000000550010000000011234567890</chNFe>
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
</retConsSitNFe>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaConsulta');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, '31260800000000000000550010000000011234567890');

        $this->assertSame('CANCELADA', $resultado->status);
    }

    public function test_processar_resposta_consulta_cstat_nao_reconhecido_nunca_vira_autorizada(): void
    {
        // 217 = NF-e não consta na base de dados da SEFAZ (nunca autorizada).
        $respostaXml = <<<'XML'
<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <tpAmb>2</tpAmb>
  <verAplic>SP_NFE_PL009_V4</verAplic>
  <cStat>217</cStat>
  <xMotivo>NF-e não consta na base de dados da SEFAZ</xMotivo>
  <cUF>31</cUF>
  <dhRecbto>2026-08-10T10:00:00-03:00</dhRecbto>
  <chNFe>31260800000000000000550010000000011234567890</chNFe>
</retConsSitNFe>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaConsulta');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, '31260800000000000000550010000000011234567890');

        $this->assertSame('ERRO', $resultado->status);
        $this->assertStringContainsString('217', (string) $resultado->mensagemErro);
    }

    public function test_processar_resposta_consulta_xml_invalido_retorna_erro(): void
    {
        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaConsulta');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, 'isto nao e xml', 'chave-x');

        $this->assertSame('ERRO', $resultado->status);
    }

    /**
     * Prova a correção vs. o brief: a resposta de sefazCancela() (que é
     * sefazEvento() por baixo) tem um cStat de LOTE (128, "Lote de Evento
     * Processado") ANTES de retEvento/infEvento/cStat (135, o cStat real
     * do registro do evento) em ordem de documento — mesma armadilha já
     * coberta para EPEC em
     * MotorNfeEmitirTest::test_extrair_cstat_evento_ignora_cstat_de_lote_usa_cstat_do_evento.
     * Um xpath ingênuo pegaria 128 (nunca bate com 135/136/155) e
     * reportaria "não confirmado" mesmo com o cancelamento registrado com
     * sucesso. Este teste teria falhado com o parsing plano do brief.
     */
    public function test_processar_resposta_cancelamento_ignora_cstat_de_lote_usa_cstat_do_evento(): void
    {
        $respostaXml = <<<'XML'
<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">
  <idLote>1</idLote>
  <tpAmb>2</tpAmb>
  <verAplic>SP_NFE_PL009_V4</verAplic>
  <cOrgao>31</cOrgao>
  <cStat>128</cStat>
  <xMotivo>Lote de Evento Processado</xMotivo>
  <retEvento versao="1.00">
    <infEvento Id="ID1101111234567890123456789012345678901234567890000001">
      <tpAmb>2</tpAmb>
      <verAplic>SP_NFE_PL009_V4</verAplic>
      <cOrgao>31</cOrgao>
      <cStat>135</cStat>
      <xMotivo>Evento registrado e vinculado a NF-e</xMotivo>
      <chNFe>31260800000000000000550010000000011234567890</chNFe>
      <tpEvento>110111</tpEvento>
      <dhRegEvento>2026-08-10T10:01:00-03:00</dhRegEvento>
      <nProt>135260000000002</nProt>
    </infEvento>
  </retEvento>
</retEnvEvento>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaCancelamento');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, '31260800000000000000550010000000011234567890');

        $this->assertSame('CANCELADA', $resultado->status);
    }

    public function test_processar_resposta_cancelamento_reconhece_cstat_155(): void
    {
        $respostaXml = <<<'XML'
<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">
  <idLote>1</idLote>
  <tpAmb>2</tpAmb>
  <verAplic>SP_NFE_PL009_V4</verAplic>
  <cOrgao>31</cOrgao>
  <cStat>128</cStat>
  <xMotivo>Lote de Evento Processado</xMotivo>
  <retEvento versao="1.00">
    <infEvento Id="ID1101111234567890123456789012345678901234567890000001">
      <tpAmb>2</tpAmb>
      <verAplic>SP_NFE_PL009_V4</verAplic>
      <cOrgao>31</cOrgao>
      <cStat>155</cStat>
      <xMotivo>Cancelamento registrado</xMotivo>
      <chNFe>31260800000000000000550010000000011234567890</chNFe>
      <tpEvento>110111</tpEvento>
      <dhRegEvento>2026-08-10T10:01:00-03:00</dhRegEvento>
      <nProt>135260000000002</nProt>
    </infEvento>
  </retEvento>
</retEnvEvento>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaCancelamento');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, '31260800000000000000550010000000011234567890');

        $this->assertSame('CANCELADA', $resultado->status);
    }

    public function test_processar_resposta_cancelamento_cstat_nao_reconhecido_retorna_erro(): void
    {
        $respostaXml = <<<'XML'
<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">
  <idLote>1</idLote>
  <tpAmb>2</tpAmb>
  <verAplic>SP_NFE_PL009_V4</verAplic>
  <cOrgao>31</cOrgao>
  <cStat>128</cStat>
  <xMotivo>Lote de Evento Processado</xMotivo>
  <retEvento versao="1.00">
    <infEvento Id="ID1101111234567890123456789012345678901234567890000001">
      <tpAmb>2</tpAmb>
      <verAplic>SP_NFE_PL009_V4</verAplic>
      <cOrgao>31</cOrgao>
      <cStat>573</cStat>
      <xMotivo>Rejeicao: Protocolo nao localizado</xMotivo>
      <chNFe>31260800000000000000550010000000011234567890</chNFe>
      <tpEvento>110111</tpEvento>
      <dhRegEvento>2026-08-10T10:01:00-03:00</dhRegEvento>
    </infEvento>
  </retEvento>
</retEnvEvento>
XML;

        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaCancelamento');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, '31260800000000000000550010000000011234567890');

        $this->assertSame('ERRO', $resultado->status);
        $this->assertStringContainsString('573', (string) $resultado->mensagemErro);
    }

    public function test_processar_resposta_cancelamento_xml_invalido_retorna_erro(): void
    {
        $motor  = new MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaCancelamento');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, 'isto nao e xml', 'chave-x');

        $this->assertSame('ERRO', $resultado->status);
    }
}
