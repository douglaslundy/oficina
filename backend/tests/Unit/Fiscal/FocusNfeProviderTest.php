<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Providers\FocusNfeProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FocusNfeProviderTest extends TestCase
{
    private function nota(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'email' => 'c@x.com', 'cep' => '01310100', 'logradouro' => 'Av A',
                'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
                'uf' => 'SP', 'codigo_ibge' => '3550308',
            ],
            descricao: 'Serviço de troca de óleo',
            valorServicos: 200.00,
            aliquotaIss: 5.0,
            issRetido: false,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços',
            referenciaExterna: 'os-123',
        );
    }

    private function notaNfe(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'email' => 'c@x.com', 'cep' => '01310100', 'logradouro' => 'Av A',
                'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
                'uf' => 'SP', 'codigo_ibge' => '3550308',
            ],
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'os-456',
            modelo: 'NFE',
            itens: [[
                'produto_id' => 'prod-1', 'descricao' => 'Filtro de óleo',
                'ncm' => '84212300', 'cfop' => '6102', 'origem' => 0,
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '00',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
        );
    }

    public function test_payload_nfe_monta_itens_com_dados_fiscais(): void
    {
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $payload = $p->montarPayloadNfe($this->notaNfe());

        $this->assertSame('Venda de Mercadoria', $payload['natureza_operacao']);
        $this->assertSame(1, $payload['tipo_documento']);
        $this->assertSame(1, $payload['finalidade_emissao']);
        $this->assertCount(1, $payload['items']);
        $this->assertSame('84212300', $payload['items'][0]['codigo_ncm']);
        $this->assertSame('6102', $payload['items'][0]['cfop']);
        $this->assertSame(0, $payload['items'][0]['icms_origem']);
        $this->assertSame('00', $payload['items'][0]['icms_situacao_tributaria']);
        $this->assertSame(2.0, $payload['items'][0]['quantidade_comercial']);
        $this->assertSame(35.50, $payload['items'][0]['valor_unitario_comercial']);
        $this->assertSame(71.0, $payload['items'][0]['valor_bruto']);
    }

    public function test_emitir_nfe_processando(): void
    {
        Http::fake([
            '*/nfe?ref=os-456' => Http::response(['status' => 'processando_autorizacao'], 202),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->emitir($this->notaNfe());

        $this->assertSame('PROCESSANDO', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/nfe?ref=os-456'));
    }

    public function test_emitir_nfe_autorizada_baixa_xml_real_e_nao_reusa_numero_como_protocolo(): void
    {
        Http::fake([
            '*/nfe?ref=os-456' => Http::response([
                'status' => 'autorizado',
                'numero' => '999',
                'chave_nfe' => 'CHAVE123',
                'caminho_xml_nota_fiscal' => 'https://focus/xml/os-456.xml',
                'caminho_danfe' => 'https://focus/danfe/os-456.pdf',
            ], 201),
            'https://focus/xml/os-456.xml' => Http::response('<xml>conteudo real da nfe</xml>', 200),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->emitir($this->notaNfe());

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('999', $r->numero);
        $this->assertStringContainsString('<xml>conteudo real da nfe</xml>', $r->xml);
        $this->assertNotSame($r->numero, $r->protocolo);
    }

    public function test_map_status_normaliza(): void
    {
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $this->assertSame('AUTORIZADA', $p->mapStatus('autorizado'));
        $this->assertSame('PROCESSANDO', $p->mapStatus('processando_autorizacao'));
        $this->assertSame('REJEITADA', $p->mapStatus('erro_autorizacao'));
        $this->assertSame('CANCELADA', $p->mapStatus('cancelado'));
    }

    public function test_payload_nfse_usa_campos_focus(): void
    {
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $payload = $p->montarPayloadNfse($this->nota());

        $this->assertSame(200.00, $payload['servico']['valor_servicos']);
        $this->assertSame('1401', $payload['servico']['codigo_tributario_municipio']);
        $this->assertSame(5.0, $payload['servico']['aliquota']);
        $this->assertSame('12345678000199', $payload['tomador']['cnpj']);
        $this->assertSame('Prestação de Serviços', $payload['natureza_operacao']);
    }

    public function test_emitir_envia_ref_e_processa(): void
    {
        Http::fake([
            '*/v2/nfse?ref=os-123' => Http::response([
                'status' => 'processando_autorizacao',
            ], 202),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->emitir($this->nota());

        $this->assertSame('PROCESSANDO', $r->status);
        $this->assertSame('os-123', $r->referenciaExterna);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'ref=os-123'));
    }

    public function test_consultar_autorizado(): void
    {
        Http::fake([
            '*/v2/nfse/os-123' => Http::response([
                'status' => 'autorizado',
                'numero' => '77',
                'caminho_xml_nota_fiscal' => '/xml/os-123.xml',
                'url' => 'http://focus/danfse/os-123.pdf',
            ], 200),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->consultar('os-123');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('77', $r->numero);
    }

    public function test_registrar_emissor_retorna_token_homologacao(): void
    {
        Http::fake([
            '*/v2/empresas' => Http::response([
                'id' => 'emp-99',
                'token_homologacao' => 'focus-homolog-1',
                'token_producao' => 'focus-prod-1',
            ], 201),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', null);
        $e = new \App\Services\Fiscal\Data\EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina X Ltda', nomeFantasia: 'Oficina X',
            inscricaoEstadual: '123', inscricaoMunicipal: '456', regimeTributario: 'Simples Nacional',
            email: 'of@x.com', telefone: '11999999999', cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Centro', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '4520-0/01',
        );
        $r = $p->registrarEmissor($e);

        $this->assertSame('REGISTRADO', $r->status);
        $this->assertSame('focus-homolog-1', $r->token);
    }

    public function test_cancelar_sucesso(): void
    {
        Http::fake(['*/v2/nfse/os-1' => Http::response([], 200)]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->cancelar('os-1', 'Serviço não prestado conforme acordado');

        $this->assertSame('CANCELADA', $r->status);
    }

    public function test_ambiente_e_explicito_nao_inferido_por_url(): void
    {
        // URL de homologação, mas ambiente PRODUCAO passado explicitamente —
        // o provider deve confiar no parâmetro, não no substring da URL.
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'PRODUCAO', 'tok');
        $this->assertTrue($p->ambienteProducao());
    }

    public function test_status_desconhecido_loga_warning(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::pattern('/status desconhecido/i'), \Mockery::any());

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $status = $p->mapStatus('status_nunca_visto_antes');

        $this->assertSame('PROCESSANDO', $status);
    }
}
