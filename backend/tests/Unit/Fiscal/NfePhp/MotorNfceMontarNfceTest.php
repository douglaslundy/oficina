<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfce;
use Tests\TestCase;

/**
 * Testa MotorNfce::montarNfce() isoladamente — sem I/O, sem rede, sem
 * certificado (mesmo padrão de MotorNfeMontarNfeTest, que serviu de
 * referência direta pra esta classe: NF-e/NFC-e usam a mesma Make/schema
 * nacional, só o `mod`/`idDest`/`tpImp`/QRCode mudam).
 */
class MotorNfceMontarNfceTest extends TestCase
{
    private function notaVenda(array $tomadorOverrides = []): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: array_merge([
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678900',
                'uf' => 'MG', 'cidade' => 'Ilicínea', 'codigo_ibge' => '3132404',
                'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'cep' => '37275000',
            ], $tomadorOverrides),
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'nfce-1',
            modelo: 'NFCE',
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

    public function test_monta_xml_com_modelo_65_e_campos_especificos_de_nfce(): void
    {
        $motor = new MotorNfce();
        $xml = $motor->montarNfce($this->notaVenda(), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<mod>65</mod>', $xml);
        // NFC-e é sempre operação interna — nunca 2 (interestadual), mesmo
        // que o tomador more em outro estado (o cliente PODE morar fora,
        // só a operação em si tem que ser presencial/interna).
        $this->assertStringContainsString('<idDest>1</idDest>', $xml);
        $this->assertStringContainsString('<tpImp>4</tpImp>', $xml);
        $this->assertStringContainsString('<indFinal>1</indFinal>', $xml);
    }

    public function test_iddest_continua_1_mesmo_com_tomador_de_outro_estado(): void
    {
        // Contraprova de MotorNfeMontarNfeTest::test_uf_diferente_gera_
        // iddest_interestadual() — pra NF-e isso vira idDest=2, pra NFC-e
        // NUNCA (a operação de balcão em si é sempre interna).
        $motor = new MotorNfce();
        $xml = $motor->montarNfce($this->notaVenda(['uf' => 'SP']), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<idDest>1</idDest>', $xml);
    }

    public function test_tpemis_default_e_normal_mas_aceita_contingencia_offline(): void
    {
        $motor = new MotorNfce();
        $cfg   = $this->configuracaoSimplesNacional();
        $nota  = $this->notaVenda();

        $xmlNormal  = $motor->montarNfce($nota, $cfg, 'HOMOLOGACAO', 1, 1);
        $xmlOffline = $motor->montarNfce($nota, $cfg, 'HOMOLOGACAO', 1, 1, tpEmis: 9);

        $this->assertStringContainsString('<tpEmis>1</tpEmis>', $xmlNormal);
        $this->assertStringContainsString('<tpEmis>9</tpEmis>', $xmlOffline);
    }

    public function test_destinatario_e_incluido_quando_tem_documento(): void
    {
        $motor = new MotorNfce();
        $xml = $motor->montarNfce($this->notaVenda(), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<CPF>12345678900</CPF>', $xml);
    }

    /**
     * Mesmo bug real de MotorNfeMontarNfeTest ("cStat=883: GTIN (cEAN) sem
     * informação") — vale igual pra NFC-e.
     */
    public function test_monta_xml_usa_sem_gtin_quando_produto_nao_tem_codigo_de_barras(): void
    {
        $motor = new MotorNfce();
        $xml = $motor->montarNfce($this->notaVenda(), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

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

        $xml = (new MotorNfce())->montarNfce($notaComGtin, $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<cEAN>7891234567890</cEAN>', $xml);
        $this->assertStringContainsString('<cEANTrib>7891234567890</cEANTrib>', $xml);
    }

    /**
     * Mesmo bug real de MotorNfeMontarNfeTest ("cStat=806: Operação com
     * ICMS-ST sem informação do CEST") — vale igual pra NFC-e.
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

        $xml = (new MotorNfce())->montarNfce($notaComCest, $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<CEST>2600100</CEST>', $xml);
    }

    public function test_destinatario_omitido_quando_venda_anonima_sem_documento(): void
    {
        $motor = new MotorNfce();
        $xml = $motor->montarNfce($this->notaVenda(['cpf_cnpj' => '']), $this->configuracaoSimplesNacional(), 'HOMOLOGACAO', 1, 1);

        $this->assertStringNotContainsString('<dest>', $xml);
    }

    public function test_forma_pagamento_mapeia_para_tpag_correto(): void
    {
        $motor = new MotorNfce();
        $cfg   = $this->configuracaoSimplesNacional();

        $nota = new NotaFiscalData(
            tipo: 'NFSE', tomador: ['nome' => 'Cliente', 'cpf_cnpj' => '12345678900'],
            descricao: 'x', valorServicos: 0.0, aliquotaIss: 0.0, issRetido: false,
            codigoServicoFederal: '', codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria', referenciaExterna: 'nfce-pix', modelo: 'NFCE',
            itens: $this->notaVenda()->itens, formaPagamento: 'PIX',
        );
        $xml = $motor->montarNfce($nota, $cfg, 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<tPag>17</tPag>', $xml);
    }

    /**
     * Mesma disciplina de MotorNfeMontarNfeTest::test_xml_gerado_e_valido_
     * contra_xsd_oficial_exceto_assinatura_ausente() — valida o XML de
     * verdade contra o XSD oficial do vendor, não só string-matching.
     * `nfe_v4.00.xsd` serve os dois modelos (NFe/NFCe têm o MESMO elemento
     * raiz `<NFe>`, só o valor de `<mod>` distingue — confirmado no XSD:
     * não existe um `nfce_v4.00.xsd` separado no vendor).
     */
    public function test_xml_gerado_e_valido_contra_xsd_oficial_exceto_assinatura_ausente(): void
    {
        $cfg = $this->configuracaoSimplesNacional();

        $motor = new MotorNfce();
        $xml = $motor->montarNfce($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);

        $schema = base_path('vendor/nfephp-org/sped-nfe/schemes/PL_009_V4/nfe_v4.00.xsd');
        $this->assertFileExists($schema);

        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xml);
        $valido = $dom->schemaValidate($schema);

        if (!$valido) {
            $erros = array_map(static fn ($e) => trim($e->message), libxml_get_errors());
            $this->assertCount(1, $erros, 'Erros de schema inesperados: ' . implode(' | ', $erros));
            $this->assertStringContainsString('Signature', $erros[0]);
        } else {
            $this->fail('XML validou 100% sem assinatura — inesperado.');
        }
        libxml_clear_errors();
    }

    public function test_lanca_excecao_quando_cnpj_ausente(): void
    {
        $cfg = new Configuracao([
            'razao_social' => 'Oficina Teste', 'regime_tributario' => 'Simples Nacional',
            'uf' => 'MG', 'codigo_ibge' => '3132404', 'cidade' => 'Ilicínea',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '37275000',
            'inscricao_estadual' => '1234567',
        ]);

        $motor = new MotorNfce();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CNPJ da empresa não configurado');

        $motor->montarNfce($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);
    }
}
