<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use App\Services\Fiscal\NfePhp\MotorNfe;
use Tests\TestCase;

/**
 * Testa MotorNfe::mapearListaDistDFe() isoladamente — parsing puro da
 * resposta de sefazDistDFe(0, 0, null) (varredura por NSU, sem chave).
 * Mesmo padrão de reflection dos demais testes deste diretório.
 *
 * Fixtures seguem o XSD confirmado no pacote instalado
 * (schemes/PL_010_V1.30/retDistDFeInt_v1.01.xsd e resNFe_v1.01.xsd): o
 * lote traz até 50 docZip base64+gzip, cada um com um atributo `schema`
 * que diz se o conteúdo é o XML completo (procNFe, com itens) ou só o
 * resumo (resNFe, campos direto na raiz: chNFe/CNPJ/xNome/dhEmi/vNF).
 */
class MotorNfeListarNotasRecebidasMappingTest extends TestCase
{
    /** @return list<ConsultaNotaTerceiroResumo> */
    private function invocar(string $xml): array
    {
        $motor = new MotorNfe();
        $m = new \ReflectionMethod(MotorNfe::class, 'mapearListaDistDFe');
        $m->setAccessible(true);

        return $m->invoke($motor, $xml);
    }

    private function envelope(string $docZips): string
    {
        return <<<XML
        <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>138</cStat><xMotivo>Documento localizado</xMotivo>
          <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>2</ultNSU><maxNSU>2</maxNSU>
          <loteDistDFeInt>{$docZips}</loteDistDFeInt>
        </retDistDFeInt>
        XML;
    }

    private function zip(string $conteudo): string
    {
        return base64_encode((string) gzencode($conteudo));
    }

    public function test_docZip_com_procnfe_vira_resumo_completo(): void
    {
        $procNfe = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
          <NFe><infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
            <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-07-01T09:15:32-03:00</dhEmi></ide>
            <emit><CNPJ>12345678000199</CNPJ><xNome>Fornecedor Completo</xNome></emit>
            <total><ICMSTot><vNF>250.75</vNF></ICMSTot></total>
          </infNFe></NFe>
        </nfeProc>
        XML;

        $xml = $this->envelope('<docZip NSU="1" schema="procNFe_v4.00.xsd">' . $this->zip($procNfe) . '</docZip>');

        $resumos = $this->invocar($xml);

        $this->assertCount(1, $resumos);
        $this->assertInstanceOf(ConsultaNotaTerceiroResumo::class, $resumos[0]);
        $this->assertTrue($resumos[0]->completa);
        // O prefixo "NFe" do atributo Id não faz parte da chave de acesso.
        $this->assertSame('35260712345678000199550010000012340000000001', $resumos[0]->chaveAcesso);
        $this->assertSame('Fornecedor Completo', $resumos[0]->fornecedorNome);
        $this->assertSame('12345678000199', $resumos[0]->fornecedorCnpj);
        $this->assertSame('2026-07-01', $resumos[0]->dataEmissao);
        $this->assertSame(250.75, $resumos[0]->valorTotal);
    }

    public function test_docZip_com_resnfe_vira_resumo_incompleto(): void
    {
        // resNFe: campos direto na raiz, sem <det> nenhum — por isso
        // completa=false (a tela precisa saber que ainda falta manifestar
        // pra conseguir os itens).
        $resNfe = <<<XML
        <resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <chNFe>35260712345678000199550010000099990000000009</chNFe>
          <CNPJ>99887766000155</CNPJ>
          <xNome>Fornecedor Resumido</xNome>
          <dhEmi>2026-08-15T14:02:00-03:00</dhEmi>
          <tpNF>1</tpNF>
          <vNF>99.90</vNF>
        </resNFe>
        XML;

        $xml = $this->envelope('<docZip NSU="2" schema="resNFe_v1.01.xsd">' . $this->zip($resNfe) . '</docZip>');

        $resumos = $this->invocar($xml);

        $this->assertCount(1, $resumos);
        $this->assertFalse($resumos[0]->completa);
        $this->assertSame('35260712345678000199550010000099990000000009', $resumos[0]->chaveAcesso);
        $this->assertSame('Fornecedor Resumido', $resumos[0]->fornecedorNome);
        $this->assertSame('99887766000155', $resumos[0]->fornecedorCnpj);
        $this->assertSame('2026-08-15', $resumos[0]->dataEmissao);
        $this->assertSame(99.90, $resumos[0]->valorTotal);
    }

    public function test_docZip_de_evento_e_ignorado(): void
    {
        // resEvento/procEventoNFe também chegam no mesmo lote e não são
        // notas — precisam ser descartados, não viram linha na listagem.
        $evento = '<resEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><cOrgao>31</cOrgao></resEvento>';

        $xml = $this->envelope('<docZip NSU="3" schema="resEvento_v1.01.xsd">' . $this->zip($evento) . '</docZip>');

        $this->assertSame([], $this->invocar($xml));
    }

    public function test_resposta_sem_lote_devolve_lista_vazia(): void
    {
        $xml = <<<XML
        <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>137</cStat><xMotivo>Nenhum documento localizado</xMotivo>
          <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>0</ultNSU><maxNSU>0</maxNSU>
        </retDistDFeInt>
        XML;

        $this->assertSame([], $this->invocar($xml));
    }

    public function test_docZip_corrompido_e_pulado_sem_derrubar_os_demais(): void
    {
        $resNfe = '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>CHAVE-OK</chNFe><vNF>10.00</vNF></resNFe>';

        $xml = $this->envelope(
            '<docZip NSU="4" schema="resNFe_v1.01.xsd">nao-e-base64-gzip-valido!!</docZip>'
            . '<docZip NSU="5" schema="resNFe_v1.01.xsd">' . $this->zip($resNfe) . '</docZip>'
        );

        $resumos = $this->invocar($xml);

        $this->assertCount(1, $resumos);
        $this->assertSame('CHAVE-OK', $resumos[0]->chaveAcesso);
    }

    public function test_resposta_nao_xml_lanca_excecao_em_vez_de_lista_vazia(): void
    {
        // Achado da autorrevisão: uma resposta ilegível é FALHA, não
        // "nenhuma nota". Devolver [] aqui reintroduziria pela porta dos
        // fundos exatamente o que o contrato de
        // ConsultaNotaTerceiroProvider proíbe — a tela diria "nenhuma nota
        // recebida" pra uma SEFAZ devolvendo lixo/HTML de erro. `[]` fica
        // reservado pra "a SEFAZ respondeu direito e não há documentos"
        // (test_resposta_sem_lote_devolve_lista_vazia, acima).
        //
        // A exceção sobe pelo try/catch de listarNotasRecebidas(), que já
        // a converte na RuntimeException que o controller espera.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('não pôde ser interpretada');

        $this->invocar('isto nao e xml <<<');
    }

    // ── Paginação por NSU (paginarDistDFe / nsuDaResposta) ─────────────────

    private function invocarPaginar(callable $buscarPagina): array
    {
        $motor = new MotorNfe();
        $m = new \ReflectionMethod(MotorNfe::class, 'paginarDistDFe');
        $m->setAccessible(true);
        return $m->invoke($motor, $buscarPagina);
    }

    private function paginaComResumo(string $chave, int $ultNSU, int $maxNSU): string
    {
        $resNfe = "<resNFe xmlns=\"http://www.portalfiscal.inf.br/nfe\"><chNFe>{$chave}</chNFe><vNF>10.00</vNF></resNFe>";
        return <<<XML
        <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>138</cStat><xMotivo>ok</xMotivo>
          <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>{$ultNSU}</ultNSU><maxNSU>{$maxNSU}</maxNSU>
          <loteDistDFeInt><docZip NSU="{$ultNSU}" schema="resNFe_v1.01.xsd">{$this->zip($resNfe)}</docZip></loteDistDFeInt>
        </retDistDFeInt>
        XML;
    }

    public function test_paginacao_continua_ate_ultNSU_alcancar_maxNSU(): void
    {
        $chamadas = [];
        $resumos = $this->invocarPaginar(function (int $ultNSU) use (&$chamadas) {
            $chamadas[] = $ultNSU;
            // 3 documentos no total (maxNSU=3), 1 por página.
            return $this->paginaComResumo('CHAVE-' . ($ultNSU + 1), $ultNSU + 1, 3);
        });

        $this->assertSame([0, 1, 2], $chamadas, 'Deve pedir a próxima página a partir do ultNSU anterior até chegar em maxNSU.');
        $this->assertCount(3, $resumos);
        $this->assertSame('CHAVE-1', $resumos[0]->chaveAcesso);
        $this->assertSame('CHAVE-3', $resumos[2]->chaveAcesso);
    }

    public function test_paginacao_para_no_teto_de_paginas_mesmo_com_mais_documentos(): void
    {
        $chamadas = 0;
        $resumos = $this->invocarPaginar(function (int $ultNSU) use (&$chamadas) {
            $chamadas++;
            // maxNSU sempre bem acima do ultNSU → nunca "acaba" sozinho;
            // só o teto de páginas (MAX_PAGINAS_DIST_DFE = 3) segura.
            return $this->paginaComResumo('CHAVE-' . ($ultNSU + 1), $ultNSU + 1, 9999);
        });

        $this->assertSame(3, $chamadas, 'Não pode passar de MAX_PAGINAS_DIST_DFE páginas (risco de cStat 656 da SEFAZ).');
        $this->assertCount(3, $resumos);
    }

    public function test_paginacao_uma_pagina_so_quando_ja_veio_tudo(): void
    {
        $chamadas = 0;
        $this->invocarPaginar(function (int $ultNSU) use (&$chamadas) {
            $chamadas++;
            return $this->paginaComResumo('CHAVE-1', 1, 1); // ultNSU == maxNSU já na 1ª
        });

        $this->assertSame(1, $chamadas);
    }

    public function test_nsu_da_resposta_le_ultNSU_e_maxNSU(): void
    {
        $motor = new MotorNfe();
        $m = new \ReflectionMethod(MotorNfe::class, 'nsuDaResposta');
        $m->setAccessible(true);

        [$ult, $max] = $m->invoke($motor, $this->paginaComResumo('X', 7, 42));

        $this->assertSame(7, $ult);
        $this->assertSame(42, $max);
    }

    public function test_nsu_da_resposta_xml_invalido_devolve_zeros(): void
    {
        $motor = new MotorNfe();
        $m = new \ReflectionMethod(MotorNfe::class, 'nsuDaResposta');
        $m->setAccessible(true);

        $this->assertSame([0, 0], $m->invoke($motor, 'nao e xml <<<'));
    }
}
