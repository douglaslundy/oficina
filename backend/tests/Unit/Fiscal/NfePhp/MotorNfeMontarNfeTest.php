<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Exceptions\EmissaoBloqueadaException;
use App\Models\Configuracao;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfe;
use Tests\TestCase;

/**
 * Testa MotorNfe::montarNfe() isoladamente — sem I/O, sem rede, sem
 * certificado (a Make do sped-nfe só monta o DOM em memória).
 *
 * Corrigido: o brief original usava PHPUnit\Framework\TestCase puro. Isso
 * quebra porque montarNfe() chama os helpers now() e config() do Laravel
 * (facades que exigem o container da aplicação de pé) — exatamente o mesmo
 * problema que MotorNfseMontarDpsTest.php já documentou e resolveu trocando
 * para Tests\TestCase (comprovadamente roda neste ambiente sem precisar de
 * Postgres).
 *
 * Corrigido: o brief não incluía 'cnpj' na Configuracao de teste. Sem CNPJ
 * (nem CPF) no emitente, Make::render() -> checkNFeKey() acessa
 * ->nodeValue num node inexistente (CPF/CNPJ). Achado inicialmente rodando
 * o teste sob PHPUnit, que converteu o E_WARNING resultante numa exceção
 * catchável — mas revisão posterior confirmou que em PHP puro (produção)
 * isso NÃO lança nada: a chave de acesso é montada em silêncio a partir de
 * um CNPJ vazio. MotorNfe::montarNfe() agora tem uma guarda explícita
 * (InvalidArgumentException) pra não depender desse comportamento
 * inconsistente entre ambientes — ver test_lanca_excecao_quando_cnpj_ausente()
 * abaixo e task-3-report.md ("Fix report").
 */
class MotorNfeMontarNfeTest extends TestCase
{
    private function notaVenda(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'uf' => 'MG', 'cidade' => 'Ilicínea', 'codigo_ibge' => '3132404',
                'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'cep' => '37275000',
            ],
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'nfe-1',
            modelo: 'NFE',
            itens: [[
                'produto_id' => 'prod-1', 'sku' => 'FLT-001', 'descricao' => 'Filtro de óleo',
                'unidade' => 'PC', 'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
        );
    }

    private function configuracaoSimplesNacional(): Configuracao
    {
        return new Configuracao([
            'razao_social' => 'Oficina Teste', 'regime_tributario' => 'Simples Nacional',
            'cnpj' => '11222333000181',
            'uf' => 'MG', 'codigo_ibge' => '3132404', 'cidade' => 'Ilicínea',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '37275000',
            'inscricao_estadual' => '1234567',
        ]);
    }

    private function configuracaoRegimeNormal(): Configuracao
    {
        return new Configuracao([
            'razao_social' => 'Oficina Regime Normal Teste', 'regime_tributario' => 'Lucro Presumido',
            'cnpj' => '11222333000181',
            'uf' => 'MG', 'codigo_ibge' => '3132404', 'cidade' => 'Ilicínea',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '37275000',
            'inscricao_estadual' => '1234567',
        ]);
    }

    private function notaComTomador(array $tomadorOverrides, array $itemOverrides = []): NotaFiscalData
    {
        $nota = $this->notaVenda();

        return new NotaFiscalData(
            tipo: $nota->tipo, tomador: array_merge($nota->tomador, $tomadorOverrides),
            descricao: $nota->descricao, valorServicos: $nota->valorServicos,
            aliquotaIss: $nota->aliquotaIss, issRetido: $nota->issRetido,
            codigoServicoFederal: $nota->codigoServicoFederal, codigoServicoMunicipal: $nota->codigoServicoMunicipal,
            naturezaOperacao: $nota->naturezaOperacao, referenciaExterna: $nota->referenciaExterna,
            modelo: $nota->modelo,
            itens: $itemOverrides === [] ? $nota->itens : [array_merge($nota->itens[0], $itemOverrides)],
        );
    }

    public function test_monta_xml_para_simples_nacional_sem_bloco_ibscbs(): void
    {
        $cfg = $this->configuracaoSimplesNacional();

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<CSOSN>102</CSOSN>', $xml);
        // Corrigido (Task 4): a asserção original era `assertStringNotContainsString('<CST>', $xml)`
        // — testava (corretamente, na época) que o grupo ICMS usa CSOSN em vez de
        // CST pra CRT=1. Ficou imprecisa demais depois que esta task adicionou os
        // grupos PIS/COFINS obrigatórios por item (exigidos pelo XSD independente
        // do CRT — ver test_monta_xml_com_grupos_pis_e_cofins_por_item abaixo),
        // que legitimamente usam <CST> (PIS/COFINS sempre usam CST, mesmo no
        // Simples Nacional — CSOSN é específico de ICMS). Restrita ao grupo ICMS
        // pra preservar a intenção original da asserção.
        preg_match('/<ICMS>.*?<\/ICMS>/', $xml, $blocoIcms);
        $this->assertStringNotContainsString('<CST>', $blocoIcms[0] ?? '');
        $this->assertStringContainsString('<CRT>1</CRT>', $xml);
    }

    public function test_monta_xml_usa_sku_e_unidade_do_item(): void
    {
        $motor = new MotorNfe();
        $xml = $motor->montarNfe($this->notaVenda(), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<cProd>FLT-001</cProd>', $xml);
        $this->assertStringContainsString('<uCom>PC</uCom>', $xml);
        $this->assertStringContainsString('<uTrib>PC</uTrib>', $xml);
    }

    /**
     * Bug real de produção (2026-09-15, "cStat=441: Rejeicao: Descricao do
     * pagamento obrigatoria para meio de pagamento 99-outros"): `tPag`
     * ficava hardcoded '99' pra TODA NF-e, mesmo com forma de pagamento
     * mapeável — e sem `xPag` (exigido pela SEFAZ quando tPag=99).
     */
    public function test_forma_pagamento_mapeia_para_tpag_correto(): void
    {
        $motor = new MotorNfe();
        $nota  = $this->notaVenda();
        $notaComPagamento = new NotaFiscalData(
            tipo: $nota->tipo, tomador: $nota->tomador, descricao: $nota->descricao,
            valorServicos: $nota->valorServicos, aliquotaIss: $nota->aliquotaIss, issRetido: $nota->issRetido,
            codigoServicoFederal: $nota->codigoServicoFederal, codigoServicoMunicipal: $nota->codigoServicoMunicipal,
            naturezaOperacao: $nota->naturezaOperacao, referenciaExterna: $nota->referenciaExterna,
            modelo: $nota->modelo, itens: $nota->itens, formaPagamento: 'PIX',
        );
        $xml = $motor->montarNfe($notaComPagamento, $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<tPag>17</tPag>', $xml);
        $this->assertStringNotContainsString('<xPag>', $xml);
    }

    public function test_forma_pagamento_nao_reconhecida_manda_xpag_com_descricao(): void
    {
        $motor = new MotorNfe();
        $nota  = $this->notaVenda();
        $notaComPagamento = new NotaFiscalData(
            tipo: $nota->tipo, tomador: $nota->tomador, descricao: $nota->descricao,
            valorServicos: $nota->valorServicos, aliquotaIss: $nota->aliquotaIss, issRetido: $nota->issRetido,
            codigoServicoFederal: $nota->codigoServicoFederal, codigoServicoMunicipal: $nota->codigoServicoMunicipal,
            naturezaOperacao: $nota->naturezaOperacao, referenciaExterna: $nota->referenciaExterna,
            modelo: $nota->modelo, itens: $nota->itens, formaPagamento: 'Cheque',
        );
        $xml = $motor->montarNfe($notaComPagamento, $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<tPag>99</tPag>', $xml);
        $this->assertStringContainsString('<xPag>Cheque</xPag>', $xml);
    }

    /**
     * Bug real de produção (2026-09-14, "cStat=883: GTIN (cEAN) sem
     * informação"): cEAN/cEANTrib nunca eram mandados — SEFAZ rejeita TODA
     * nota sem esse campo desde 12/09/2022. Sem código de barras
     * cadastrado, o literal "SEM GTIN" tem que ser mandado (nunca vazio).
     */
    public function test_monta_xml_usa_sem_gtin_quando_produto_nao_tem_codigo_de_barras(): void
    {
        $motor = new MotorNfe();
        $xml = $motor->montarNfe($this->notaVenda(), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<cEAN>SEM GTIN</cEAN>', $xml);
        $this->assertStringContainsString('<cEANTrib>SEM GTIN</cEANTrib>', $xml);
    }

    public function test_monta_xml_usa_o_codigo_de_barras_do_produto_quando_existe(): void
    {
        $nota = $this->notaVenda();
        $notaComGtin = new NotaFiscalData(
            tipo: $nota->tipo, tomador: $nota->tomador, descricao: $nota->descricao,
            valorServicos: $nota->valorServicos, aliquotaIss: $nota->aliquotaIss, issRetido: $nota->issRetido,
            codigoServicoFederal: $nota->codigoServicoFederal, codigoServicoMunicipal: $nota->codigoServicoMunicipal,
            naturezaOperacao: $nota->naturezaOperacao, referenciaExterna: $nota->referenciaExterna,
            modelo: $nota->modelo,
            itens: [array_merge($nota->itens[0], ['codigo_barras' => '7891234567890'])],
        );

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($notaComGtin, $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<cEAN>7891234567890</cEAN>', $xml);
        $this->assertStringContainsString('<cEANTrib>7891234567890</cEANTrib>', $xml);
    }

    /**
     * Bug real de produção (2026-09-15, "cStat=806: Operação com ICMS-ST
     * sem informação do CEST"): CEST nunca era lido/mandado em tagprod(),
     * mesmo quando o produto já tinha o dado cadastrado — confirmado contra
     * um caso real rejeitado com `produtos.cest='2600100'` já preenchido.
     */
    public function test_monta_xml_inclui_cest_do_item_quando_presente(): void
    {
        $nota = $this->notaVenda();
        $notaComCest = new NotaFiscalData(
            tipo: $nota->tipo, tomador: $nota->tomador, descricao: $nota->descricao,
            valorServicos: $nota->valorServicos, aliquotaIss: $nota->aliquotaIss, issRetido: $nota->issRetido,
            codigoServicoFederal: $nota->codigoServicoFederal, codigoServicoMunicipal: $nota->codigoServicoMunicipal,
            naturezaOperacao: $nota->naturezaOperacao, referenciaExterna: $nota->referenciaExterna,
            modelo: $nota->modelo,
            itens: [array_merge($nota->itens[0], ['cest' => '2600100'])],
        );

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($notaComCest, $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<CEST>2600100</CEST>', $xml);
    }

    public function test_monta_xml_sem_cest_nao_inclui_a_tag(): void
    {
        $motor = new MotorNfe();
        $xml = $motor->montarNfe($this->notaVenda(), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringNotContainsString('<CEST>', $xml);
    }

    /**
     * Task 4 — achado da revisão da Task 3: Make::addTagDet() só inclui
     * <PIS>/<COFINS> quando aPIS[item]/aCOFINS[item] são populados por
     * tagPIS()/tagCOFINS() explícitos (confirmado em Make.php ~743-755); o
     * XSD da NF-e v4.00 exige os dois grupos por item, independente do CRT.
     * CST 49 com base/valor zerados é o padrão pra Simples Nacional (CRT=1,
     * regime real da oficina) — PIS/COFINS é pago via DAS, não calculado
     * por operação.
     */
    public function test_monta_xml_com_grupos_pis_e_cofins_por_item(): void
    {
        $cfg = $this->configuracaoSimplesNacional();

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<PIS>', $xml);
        $this->assertStringContainsString('<PISOutr>', $xml);
        $this->assertStringContainsString('<COFINS>', $xml);
        $this->assertStringContainsString('<COFINSOutr>', $xml);
        // CST 49 aparece duas vezes (PIS e COFINS) além do CSOSN — checa
        // via contagem pra não depender de qual aparece primeiro no XML.
        $this->assertSame(2, substr_count($xml, '<CST>49</CST>'));
        $this->assertStringContainsString('<vPIS>0.00</vPIS>', $xml);
        $this->assertStringContainsString('<vCOFINS>0.00</vCOFINS>', $xml);
    }

    /**
     * Achado do review da Task 4: nenhum teste validava o XML gerado contra
     * o XSD real do vendor — exatamente por isso o defeito de PIS/COFINS
     * (vBC/pPIS e vBC/pCOFINS ausentes, exigidos pelo <xs:choice> obrigatório
     * de PISOutr/COFINSOutr) passou pelos testes de string-matching sem ser
     * detectado. Este teste roda schemaValidate() de verdade contra
     * schemes/PL_009_V4/nfe_v4.00.xsd e garante que o ÚNICO erro restante é
     * a assinatura ausente — montarNfe() nunca assina (isso é
     * responsabilidade de emitir(), que chama Tools::signNFe() depois).
     * Qualquer outro erro de schema (PIS/COFINS, ICMS, etc.) faz este teste
     * falhar, protegendo também as Tasks 5/6 que dependem deste XML.
     */
    public function test_xml_gerado_e_valido_contra_xsd_oficial_exceto_assinatura_ausente(): void
    {
        $cfg = $this->configuracaoSimplesNacional();

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);

        $schema = base_path('vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/nfe_v4.00.xsd');
        $this->assertFileExists($schema);

        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xml);
        $valido = $dom->schemaValidate($schema);

        if (!$valido) {
            $erros = array_map(static fn ($e) => trim($e->message), libxml_get_errors());
            // Único erro esperado: falta de <Signature> (montarNfe() não
            // assina). Qualquer outro erro de schema deve reprovar o teste.
            $this->assertCount(1, $erros, 'Erros de schema inesperados: ' . implode(' | ', $erros));
            $this->assertStringContainsString('Signature', $erros[0]);
        } else {
            $this->fail('XML validou 100% sem assinatura — inesperado, montarNfe() não deveria produzir um XML já assinável pelo schema completo sem Signature.');
        }
        libxml_clear_errors();
    }

    public function test_uf_diferente_gera_iddest_interestadual(): void
    {
        $cfg = $this->configuracaoSimplesNacional();
        $nota = $this->notaVenda();
        // tomador em SP em vez de MG
        $notaInterestadual = new NotaFiscalData(
            tipo: $nota->tipo, tomador: array_merge($nota->tomador, ['uf' => 'SP']),
            descricao: $nota->descricao, valorServicos: $nota->valorServicos,
            aliquotaIss: $nota->aliquotaIss, issRetido: $nota->issRetido,
            codigoServicoFederal: $nota->codigoServicoFederal, codigoServicoMunicipal: $nota->codigoServicoMunicipal,
            naturezaOperacao: $nota->naturezaOperacao, referenciaExterna: $nota->referenciaExterna,
            modelo: $nota->modelo, itens: $nota->itens,
        );

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($notaInterestadual, $cfg, 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<idDest>2</idDest>', $xml);
    }

    /**
     * Achado de auditoria 2026-09-23: venda interestadual (idDest=2) para
     * consumidor final (indFinal=1) não contribuinte (indIEDest=9, o
     * default quando o tomador não tem indicador_ie resolvido) exige o
     * grupo <ICMSUFDest> (DIFAL) quando o emitente é CRT=3 (Regime Normal).
     * Este projeto não tem tabela real de alíquota interna por UF — bloqueia
     * a emissão em vez de chutar o valor e deixar a SEFAZ rejeitar (cStat
     * 694) do outro lado.
     */
    public function test_bloqueia_emissao_regime_normal_venda_interestadual_para_nao_contribuinte(): void
    {
        $motor = new MotorNfe();
        $nota  = $this->notaComTomador(['uf' => 'SP']); // MG -> SP, sem indicador_ie (default 9)

        $this->expectException(EmissaoBloqueadaException::class);
        $this->expectExceptionMessage('DIFAL');

        $motor->montarNfe($nota, $this->configuracaoRegimeNormal(), 'HOMOLOGACAO', 1, 1);
    }

    /**
     * CRT=1 (Simples Nacional/MEI) é dispensado de DIFAL por decisão do STF
     * (ADI 5464) — a mesma venda interestadual para não contribuinte NÃO
     * deve ser bloqueada quando o emitente é Simples Nacional. Já coberto
     * implicitamente por test_uf_diferente_gera_iddest_interestadual (usa
     * configuracaoSimplesNacional() e não lança), mas deixado explícito
     * aqui como documentação da regra.
     */
    public function test_nao_bloqueia_simples_nacional_venda_interestadual_para_nao_contribuinte(): void
    {
        $motor = new MotorNfe();
        $nota  = $this->notaComTomador(['uf' => 'SP']);

        $xml = $motor->montarNfe($nota, $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<idDest>2</idDest>', $xml);
    }

    public function test_nao_bloqueia_regime_normal_venda_intraestadual(): void
    {
        $motor = new MotorNfe();
        // tomador em MG, mesma UF do emitente (configuracaoRegimeNormal()) -> idDest=1, nunca dispara a guarda de DIFAL.
        // cst_csosn precisa ser um CST real (não CSOSN) pra CRT=3 — tagICMS() do vendor
        // só monta o grupo <ICMS> pra CSTs conhecidos (00, 02, 10, 20...); CSOSN '102'
        // (usado pelo fixture padrão, pensado pra CRT=1/tagICMSSN) faz tagICMS() retornar null.
        $nota  = $this->notaComTomador(['uf' => 'MG'], ['cst_csosn' => '00']);

        $xml = $motor->montarNfe($nota, $this->configuracaoRegimeNormal(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<idDest>1</idDest>', $xml);
    }

    public function test_nao_bloqueia_regime_normal_venda_interestadual_para_contribuinte(): void
    {
        $motor = new MotorNfe();
        $nota  = $this->notaComTomador(
            ['uf' => 'SP', 'indicador_ie' => 1, 'inscricao_estadual' => '110042490114'],
            ['cst_csosn' => '00'],
        );

        $xml = $motor->montarNfe($nota, $this->configuracaoRegimeNormal(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<idDest>2</idDest>', $xml);
        $this->assertStringContainsString('<indIEDest>1</indIEDest>', $xml);
    }

    /**
     * Guarda contra "chutar" a chave de acesso da NF-e com um CNPJ vazio.
     * Sem essa guarda explícita, em PHP puro (fora do conversor de
     * E_WARNING->exceção do PHPUnit) a Make/sped-nfe monta a chave em
     * silêncio a partir de um CNPJ zero-padded, sem lançar nada e sem
     * popular getErrors() — corrompendo um valor fiscal legalmente
     * significativo sem avisar ninguém.
     */
    public function test_lanca_excecao_quando_cnpj_ausente(): void
    {
        $cfg = new Configuracao([
            'razao_social' => 'Oficina Teste', 'regime_tributario' => 'Simples Nacional',
            // 'cnpj' deliberadamente ausente
            'uf' => 'MG', 'codigo_ibge' => '3132404', 'cidade' => 'Ilicínea',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '37275000',
            'inscricao_estadual' => '1234567',
        ]);

        $motor = new MotorNfe();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CNPJ da empresa não configurado');

        $motor->montarNfe($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);
    }
}
