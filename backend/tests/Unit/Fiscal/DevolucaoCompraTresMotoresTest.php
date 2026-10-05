<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Exceptions\EmissaoBloqueadaException;
use App\Models\Configuracao;
use App\Services\Fiscal\CfopDevolucaoCompraResolver;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfe;
use App\Services\Fiscal\Providers\FocusNfeProvider;
use App\Services\Fiscal\Providers\SpedyProvider;
use Tests\TestCase;

/**
 * A mesma NF-e de devolução de compra montada pelos 3 motores (NFePHP, Spedy,
 * Focus): finalidade 4, nota referenciada, destinatário não consumidor final,
 * sem pagamento (tPag 90). A NF-e normal continua como era.
 */
class DevolucaoCompraTresMotoresTest extends TestCase
{
    private const CHAVE = '31260111222333000181550010000000011000000015';

    private function nota(bool $devolucao = true): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Fornecedor Peças LTDA', 'cpf_cnpj' => '12345678000199',
                'uf' => 'MG', 'cidade' => 'Ilicínea', 'codigo_ibge' => '3132404',
                'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'cep' => '37275000',
                'indicador_ie' => 1, 'inscricao_estadual' => '1234567890',
            ],
            descricao: 'Devolução',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: $devolucao ? 'Devolução de compra para comercialização' : 'Venda de Mercadoria',
            referenciaExterna: 'nfe-1',
            modelo: 'NFE',
            itens: [[
                'produto_id' => 'prod-1', 'sku' => 'FLT-001', 'descricao' => 'Filtro de óleo',
                'unidade' => 'PC', 'ncm' => '84212300', 'cfop' => $devolucao ? '5202' : '5102', 'origem' => 0,
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
            formaPagamento: 'Dinheiro',
            regimeTributario: 'Simples Nacional',
            cnpjEmitente: '11222333000181',
            finalidade: $devolucao ? 'DEVOLUCAO' : 'NORMAL',
            chaveReferenciada: $devolucao ? self::CHAVE : null,
        );
    }

    private function config(): Configuracao
    {
        return new Configuracao([
            'razao_social' => 'Oficina Teste', 'regime_tributario' => 'Simples Nacional',
            'cnpj' => '11222333000181', 'uf' => 'MG', 'codigo_ibge' => '3132404', 'cidade' => 'Ilicínea',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '37275000',
            'inscricao_estadual' => '1234567',
        ]);
    }

    // ── NFePHP ───────────────────────────────────────────────────────────

    public function test_nfephp_monta_finnfe4_com_refnfe_sem_pagamento(): void
    {
        $xml = (new MotorNfe())->montarNfe($this->nota(), $this->config(), 'HOMOLOGACAO', 10, 1);

        $this->assertStringContainsString('<finNFe>4</finNFe>', $xml);
        $this->assertStringContainsString('<NFref><refNFe>' . self::CHAVE . '</refNFe></NFref>', $xml);
        $this->assertStringContainsString('<indFinal>0</indFinal>', $xml);
        $this->assertStringContainsString('<indPres>1</indPres>', $xml);
        $this->assertStringContainsString('<tPag>90</tPag>', $xml);
        $this->assertStringContainsString('<vPag>0.00</vPag>', $xml);
        $this->assertStringContainsString('<CFOP>5202</CFOP>', $xml);
        $this->assertStringContainsString('<tpNF>1</tpNF>', $xml);
    }

    public function test_nfephp_nota_normal_continua_finnfe1_sem_nfref(): void
    {
        $xml = (new MotorNfe())->montarNfe($this->nota(false), $this->config(), 'HOMOLOGACAO', 10, 1);

        $this->assertStringContainsString('<finNFe>1</finNFe>', $xml);
        $this->assertStringNotContainsString('<NFref>', $xml);
        $this->assertStringContainsString('<indFinal>1</indFinal>', $xml);
        $this->assertStringContainsString('<indPres>1</indPres>', $xml);
    }

    public function test_nfephp_devolucao_sem_chave_valida_bloqueia(): void
    {
        $base = $this->nota();
        $semChave = new NotaFiscalData(...array_merge(get_object_vars($base), ['chaveReferenciada' => '123']));

        $this->expectException(EmissaoBloqueadaException::class);
        (new MotorNfe())->montarNfe($semChave, $this->config(), 'HOMOLOGACAO', 10, 1);
    }

    // ── Spedy ────────────────────────────────────────────────────────────

    public function test_spedy_payload_devolucao(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->nota());

        $this->assertSame('devolution', $payload['purposeType']);
        $this->assertSame('outgoing', $payload['operationType']);
        $this->assertSame([['accessKey' => self::CHAVE]], $payload['referencedDocuments']);
        $this->assertFalse($payload['isFinalCustomer']);
        $this->assertSame('presence', $payload['presenceType']);
        $this->assertSame('noPayment', $payload['payments'][0]['method']);
        $this->assertSame(0, $payload['payments'][0]['amount']);
        $this->assertSame(5202, $payload['items'][0]['cfop']);
    }

    public function test_spedy_payload_normal_nao_ganha_campos_de_devolucao(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->nota(false));

        $this->assertArrayNotHasKey('purposeType', $payload);
        $this->assertArrayNotHasKey('referencedDocuments', $payload);
        $this->assertTrue($payload['isFinalCustomer']);
        $this->assertSame('presence', $payload['presenceType']);
        $this->assertSame('money', $payload['payments'][0]['method']);
    }

    // ── Focus ────────────────────────────────────────────────────────────

    public function test_focus_payload_devolucao(): void
    {
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $payload = $p->montarPayloadNfe($this->nota());

        $this->assertSame(4, $payload['finalidade_emissao']);
        $this->assertSame(1, $payload['tipo_documento']);
        $this->assertSame([['chave_nfe' => self::CHAVE]], $payload['notas_referenciadas']);
        $this->assertSame('0', $payload['consumidor_final']);
        $this->assertArrayNotHasKey('presenca_comprador', $payload);
        $this->assertSame([['forma_pagamento' => '90', 'valor_pagamento' => 0]], $payload['formas_pagamento']);
        $this->assertSame('5202', $payload['items'][0]['cfop']);
    }

    public function test_focus_payload_normal_nao_ganha_campos_de_devolucao(): void
    {
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $payload = $p->montarPayloadNfe($this->nota(false));

        $this->assertSame(1, $payload['finalidade_emissao']);
        $this->assertArrayNotHasKey('notas_referenciadas', $payload);
        $this->assertArrayNotHasKey('formas_pagamento', $payload);
        $this->assertSame('1', $payload['consumidor_final']);
    }

    // ── CFOP ─────────────────────────────────────────────────────────────

    public function test_cfop_devolucao_de_compra(): void
    {
        $this->assertSame('5202', CfopDevolucaoCompraResolver::resolver('MG', 'MG', false));
        $this->assertSame('5411', CfopDevolucaoCompraResolver::resolver('MG', 'MG', true));
        $this->assertSame('6202', CfopDevolucaoCompraResolver::resolver('MG', 'SP', false));
        $this->assertSame('6411', CfopDevolucaoCompraResolver::resolver('mg', 'sp', true));
    }

    public function test_cfop_devolucao_uf_invalida_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CfopDevolucaoCompraResolver::resolver('MG', 'XX', false);
    }
}
