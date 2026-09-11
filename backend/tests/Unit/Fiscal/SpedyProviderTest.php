<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Providers\SpedyProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpedyProviderTest extends TestCase
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

    public function test_map_status_normaliza(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $this->assertSame('AUTORIZADA', $p->mapStatus('authorized'));
        $this->assertSame('PROCESSANDO', $p->mapStatus('enqueued'));
        $this->assertSame('REJEITADA', $p->mapStatus('rejected'));
        $this->assertSame('CANCELADA', $p->mapStatus('canceled'));
    }

    public function test_payload_nfse_usa_campos_spedy(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfse($this->nota());

        $this->assertSame('Serviço de troca de óleo', $payload['description']);
        $this->assertSame('14.01', $payload['federalServiceCode']);
        $this->assertSame(200.00, $payload['total']['invoiceAmount']);
        $this->assertSame(0.05, $payload['total']['issRate']);
        $this->assertSame('12345678000199', $payload['receiver']['federalTaxNumber']);
        $this->assertSame('Prestação de Serviços', $payload['operationNature']);
    }

    public function test_emitir_autorizada(): void
    {
        Http::fake([
            '*/service-invoices' => Http::response([
                'id' => 'inv-1', 'status' => 'authorized',
                'accessKey' => 'CHAVE-SP', 'number' => '55',
            ], 201),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->nota());

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-SP', $r->chave);
        $this->assertSame('55', $r->numero);

        Http::assertSent(fn ($req) =>
            $req->hasHeader('X-Api-Key', 'tok') &&
            str_contains($req->url(), '/service-invoices')
        );
    }

    public function test_emitir_falha_retorna_rejeitada(): void
    {
        Http::fake([
            '*/service-invoices' => Http::response(['message' => 'CNPJ não habilitado'], 422),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->nota());

        $this->assertSame('REJEITADA', $r->status);
        $this->assertStringContainsString('CNPJ não habilitado', (string) $r->mensagemErro);
    }

    public function test_registrar_emissor_retorna_token(): void
    {
        Http::fake([
            '*/companies' => Http::response([
                'id' => 'comp-1',
                'apiCredentials' => ['apiKey' => 'spedy-key-1'],
            ], 201),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', null, null);
        $e = new \App\Services\Fiscal\Data\EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina X Ltda', nomeFantasia: 'Oficina X',
            inscricaoEstadual: '123', inscricaoMunicipal: '456', regimeTributario: 'Simples Nacional',
            email: 'of@x.com', telefone: '11999999999', cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Centro', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '4520-0/01',
        );
        $r = $p->registrarEmissor($e);

        $this->assertSame('REGISTRADO', $r->status);
        $this->assertSame('comp-1', $r->emissorExternoId);
        $this->assertSame('spedy-key-1', $r->token);
    }

    public function test_cancelar_sucesso(): void
    {
        Http::fake(['*/service-invoices/inv-1' => Http::response([], 200)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-1', 'Serviço não prestado conforme acordado');

        $this->assertSame('CANCELADA', $r->status);
    }

    public function test_cancelar_manda_o_campo_reason_nao_justification(): void
    {
        // docs.spedy.com.br/api-reference/nfs-e/cancelar-nfs-e.md confirma
        // o campo `reason` (nao `justification`, que era um chute anterior).
        Http::fake(['*/service-invoices/inv-1' => Http::response([], 200)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $p->cancelar('inv-1', 'Serviço não prestado');

        Http::assertSent(fn ($req) => $req['reason'] === 'Serviço não prestado' && !isset($req['justification']));
    }

    public function test_cancelar_nfce_usa_consumer_invoices(): void
    {
        Http::fake(['*/consumer-invoices/inv-nfce-1' => Http::response([], 200)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-nfce-1', 'Erro na emissão', 'NFCE');

        $this->assertSame('CANCELADA', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/consumer-invoices/inv-nfce-1') && $req->method() === 'DELETE');
    }

    public function test_cancelar_nfe_usa_product_invoices(): void
    {
        Http::fake(['*/product-invoices/inv-nfe-1' => Http::response([], 200)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-nfe-1', 'Erro na emissão', 'NFE');

        $this->assertSame('CANCELADA', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/product-invoices/inv-nfe-1') && $req->method() === 'DELETE');
    }

    public function test_status_desconhecido_loga_warning(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::pattern('/status desconhecido/i'), \Mockery::any());

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $status = $p->mapStatus('status_nunca_visto_antes');

        $this->assertSame('PROCESSANDO', $status);
    }

    /**
     * Payload confirmado contra docs.spedy.com.br/api-reference/nf-e/criar-nf-e.md
     * (2026-09-04) — POST /v1/product-invoices. Nunca testado em sandbox real
     * (sem credencial de emissor registrado ainda).
     */
    private function notaNfeSimplesNacional(array $overrides = []): NotaFiscalData
    {
        $args = array_merge([
            'tipo' => 'NFSE',
            'tomador' => [
                'nome' => 'Oficina Cliente LTDA', 'cpf_cnpj' => '12345678000199',
                'cep' => '37175-000', 'logradouro' => 'Rua 15 de Novembro', 'numero' => '472',
                'bairro' => 'Centro', 'cidade' => 'Ilicínea', 'uf' => 'MG', 'codigo_ibge' => '3130507',
            ],
            'descricao' => 'Venda de peças',
            'valorServicos' => 0.0,
            'aliquotaIss' => 0.0,
            'issRetido' => false,
            'codigoServicoFederal' => '',
            'codigoServicoMunicipal' => '',
            'naturezaOperacao' => 'Venda de Mercadoria',
            'referenciaExterna' => 'os-999',
            'modelo' => 'NFE',
            'itens' => [[
                'produto_id' => 'prod-1', 'sku' => 'FLT-001', 'descricao' => 'Filtro de óleo',
                'unidade' => 'PC', 'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
            'formaPagamento' => 'Dinheiro',
            'regimeTributario' => 'Simples Nacional',
        ], $overrides);

        return new NotaFiscalData(...$args);
    }

    public function test_payload_nfe_usa_schema_confirmado_da_doc(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional());

        // SEFAZ rejeita com "696: operação com não contribuinte deve indicar
        // consumidor final" quando isFinalCustomer=false e o destinatário não
        // tem IE (sempre o caso — clientes não tem coluna de IE). Bug real,
        // homologação 2026-09-10.
        $this->assertTrue($payload['isFinalCustomer']);
        $this->assertSame('12345678000199', $payload['receiver']['federalTaxNumber']);
        $this->assertSame('Venda de Mercadoria', $payload['operationNature']);

        $item = $payload['items'][0];
        $this->assertSame('FLT-001', $item['code']);
        $this->assertSame('Filtro de óleo', $item['description']);
        $this->assertSame('84212300', $item['ncm']);
        $this->assertSame(5102, $item['cfop']); // cfop é integer neste endpoint (NFC-e usa string)
        $this->assertSame('PC', $item['unit']);
        $this->assertSame(2.0, $item['quantity']);
        $this->assertSame(35.50, $item['unitAmount']);
        $this->assertSame(71.0, $item['totalAmount']);
        $this->assertSame(0, $item['taxes']['icms']['origin']);

        $this->assertSame('money', $payload['payments'][0]['method']);
        $this->assertSame(71.0, $payload['payments'][0]['amount']);
    }

    public function test_payload_nfe_manda_campos_tributaveis_obrigatorios(): void
    {
        // Bug real, homologação 2026-09-10: a SEFAZ rejeitou a NF-e com 3
        // erros de schema XML porque quantityTax/unitTaxAmount (uTrib/qTrib/
        // vUnTrib) nunca eram mandados — são OBRIGATÓRIOS no schema real da
        // Spedy (confirmado via docs.spedy.com.br), mesmo sem unidade de
        // conversão (tributável = comercial nesse caso).
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $item = $p->montarPayloadNfe($this->notaNfeSimplesNacional())['items'][0];

        $this->assertSame('PC', $item['unitTax']);
        $this->assertSame(2.0, $item['quantityTax']);
        $this->assertSame(35.50, $item['unitTaxAmount']);
        $this->assertTrue($item['makeupTotal']);
    }

    public function test_payload_nfe_manda_endereco_do_destinatario(): void
    {
        // A Spedy rejeita NF-e (modelo 55) com "Endereço do cliente é
        // obrigatório" se o receiver vier sem address — ao contrário da NFS-e,
        // que sempre mandou o bloco. Bug real: NF-e de venda rejeitada em
        // homologação (2026-09-11), enquanto a NFS-e da mesma OS autorizou.
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional());

        $addr = $payload['receiver']['address'];
        $this->assertSame('Rua 15 de Novembro', $addr['street']);
        $this->assertSame('472', $addr['number']);
        $this->assertSame('Centro', $addr['district']);
        $this->assertSame('37175000', $addr['postalCode']);
        $this->assertSame('3130507', $addr['city']['code']);
        $this->assertSame('Ilicínea', $addr['city']['name']);
        $this->assertSame('MG', $addr['city']['state']);
    }

    public function test_payload_nfe_manda_series_e_number_quando_alocados(): void
    {
        // Bug real, homologação 2026-09-10: mesmo com receiver.address e os
        // campos tributáveis corretos, a SEFAZ ainda rejeitava com "nNF valor
        // '0' inválido" + chave de acesso corrompida — a Spedy default pra
        // nNF=0 em product-invoices quando `series`/`number` (raiz) não são
        // mandados. Confirmado empiricamente no sandbox: mandando os dois
        // explícitos, a nota sai `enqueued` sem esse erro.
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional([
            'numeroAlocado' => '7', 'serieNf' => '001',
        ]));

        $this->assertSame('001', $payload['series']);
        $this->assertSame(7, $payload['number']);
    }

    public function test_payload_nfe_sem_numero_alocado_nao_manda_series_number(): void
    {
        // Chamada direta (ex.: fora do fluxo normal de emissão) sem
        // numeroAlocado/serieNf não deve mandar `series`/`number` vazios —
        // melhor deixar a Spedy tentar o default dela do que mandar null/0.
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional());

        $this->assertArrayNotHasKey('series', $payload);
        $this->assertArrayNotHasKey('number', $payload);
    }

    public function test_payload_nfce_manda_endereco_quando_o_cliente_tem(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce([
            'cep' => '37175-000', 'logradouro' => 'Rua A', 'numero' => '10',
            'bairro' => 'Centro', 'cidade' => 'Ilicínea', 'uf' => 'MG', 'codigo_ibge' => '3130507',
        ]));

        $this->assertSame('Rua A', $payload['receiver']['address']['street']);
        $this->assertSame('Ilicínea', $payload['receiver']['address']['city']['name']);
    }

    public function test_payload_nfce_balcao_sem_endereco_nao_manda_address(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce());

        $this->assertArrayNotHasKey('address', $payload['receiver']);
    }

    public function test_payload_nfe_simples_nacional_manda_csosn_nao_cst(): void
    {
        // A Spedy separa cst e csosn em campos distintos (confirmado na doc) —
        // ao contrário do cst_csosn unificado que o resto do sistema usa.
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional());

        $this->assertSame(102, $payload['items'][0]['taxes']['icms']['csosn']);
        $this->assertArrayNotHasKey('cst', $payload['items'][0]['taxes']['icms']);
    }

    public function test_payload_nfe_regime_normal_manda_cst_nao_csosn(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional(['regimeTributario' => 'Lucro Presumido']));

        $this->assertSame(102, $payload['items'][0]['taxes']['icms']['cst']);
        $this->assertArrayNotHasKey('csosn', $payload['items'][0]['taxes']['icms']);
    }

    public function test_payload_nfe_sem_regime_tributario_lanca_excecao(): void
    {
        // Nunca deve cair num default silencioso de CST/CSOSN — decisão fiscal
        // sem base não pode virar um chute (mesma regra já aplicada em CrtResolver).
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');

        $this->expectException(\InvalidArgumentException::class);
        $p->montarPayloadNfe($this->notaNfeSimplesNacional(['regimeTributario' => '']));
    }

    public function test_emitir_nfe_usa_product_invoices(): void
    {
        Http::fake([
            '*/product-invoices' => Http::response([
                'id' => 'inv-nfe-1', 'status' => 'authorized', 'accessKey' => 'CHAVE-NFE-SP', 'number' => '77',
            ], 201),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->notaNfeSimplesNacional());

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-NFE-SP', $r->chave);
        $this->assertSame('77', $r->numero);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/product-invoices')
            && !str_contains($req->url(), 'consumer-invoices'));
    }

    public function test_consultar_nfe_usa_product_invoices(): void
    {
        Http::fake([
            '*/product-invoices/inv-nfe-1' => Http::response([
                'status' => 'authorized', 'accessKey' => 'CHAVE-NFE-2', 'number' => '78',
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('inv-nfe-1', 'NFE');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-NFE-2', $r->chave);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/product-invoices/inv-nfe-1'));
    }

    private function notaNfce(array $tomadorExtra = []): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: array_merge(['nome' => 'Cliente Balcão', 'cpf_cnpj' => '87748248800'], $tomadorExtra),
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'os-nfce-1',
            modelo: 'NFCE',
            itens: [[
                'produto_id' => 'prod-1', 'sku' => 'FLT-001', 'descricao' => 'Filtro de óleo',
                'unidade' => 'PC', 'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
            formaPagamento: 'Dinheiro',
        );
    }

    public function test_payload_nfce_manda_campos_tributaveis_obrigatorios(): void
    {
        // Mesmo schema de item (SefazInvoiceItemDto) usado pela NF-e — ver
        // test_payload_nfe_manda_campos_tributaveis_obrigatorios().
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $item = $p->montarPayloadNfce($this->notaNfce())['items'][0];

        $this->assertSame('PC', $item['unitTax']);
        $this->assertSame(2.0, $item['quantityTax']);
        $this->assertSame(35.50, $item['unitTaxAmount']);
        $this->assertTrue($item['makeupTotal']);
    }

    public function test_payload_nfce_usa_sku_e_unidade_do_item(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce());

        $this->assertSame('FLT-001', $payload['items'][0]['productCode']);
        $this->assertSame('PC', $payload['items'][0]['commercialUnit']);
    }

    public function test_payload_nfce_usa_campos_spedy_inferidos(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce());

        $this->assertTrue($payload['isFinalCustomer']);
        $this->assertSame('87748248800', $payload['receiver']['individualTaxNumber']);
        $this->assertCount(1, $payload['items']);
        $this->assertSame('5102', $payload['items'][0]['cfop']);
        $this->assertSame(71.0, $payload['payments'][0]['value']);
    }

    public function test_emitir_nfce_enfileirada_retorna_processando(): void
    {
        Http::fake([
            '*/consumer-invoices' => Http::response([
                'id' => 'inv-nfce-1', 'status' => 'enqueued',
            ], 202),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->notaNfce());

        $this->assertSame('PROCESSANDO', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/consumer-invoices'));
    }

    public function test_consultar_nfce_usa_recurso_correto(): void
    {
        Http::fake([
            '*/consumer-invoices/inv-nfce-1' => Http::response([
                'status' => 'authorized', 'accessKey' => 'CHAVE-NFCE-SP', 'number' => '9',
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('inv-nfce-1', 'NFCE');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-NFCE-SP', $r->chave);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/consumer-invoices/inv-nfce-1'));
    }

    public function test_consultar_nota_recebida_completa_baixa_e_faz_parse_do_xml(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
      <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-07-01T09:15:32-03:00</dhEmi></ide>
      <emit><CNPJ>12345678000199</CNPJ><xNome>Fornecedor Teste</xNome></emit>
      <det nItem="1">
        <prod><cEAN>7891234567890</cEAN><xProd>FILTRO DE OLEO</xProd><qCom>10.0000</qCom><vUnCom>15.5000</vUnCom><NCM>84212300</NCM><CFOP>5102</CFOP><uCom>UN</uCom></prod>
        <imposto><ICMS><ICMS00><orig>0</orig><CST>00</CST></ICMS00></ICMS></imposto>
      </det>
    </infNFe>
  </NFe>
</nfeProc>
XML;

        Http::fake([
            '*/inbound-product-invoices?*' => Http::response([
                'items' => [['id' => 'inv-abc', 'accessKey' => '35260712345678000199550010000012340000000001', 'isComplete' => true]],
            ], 200),
            '*/inbound-product-invoices/inv-abc/xml' => Http::response($xml, 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultarNotaRecebida('35260712345678000199550010000012340000000001');

        $this->assertSame('COMPLETA', $r->status);
        $this->assertSame('Fornecedor Teste', $r->dados['fornecedor_nome']);
        $this->assertCount(1, $r->dados['itens']);
        $this->assertSame('84212300', $r->dados['itens'][0]['ncm']);
        $this->assertSame('7891234567890', $r->dados['itens'][0]['codigo_barras']);
    }

    public function test_consultar_nota_recebida_incompleta_manifesta_e_retorna_aguardando(): void
    {
        Http::fake([
            '*/inbound-product-invoices?*' => Http::response([
                'items' => [['id' => 'inv-abc', 'accessKey' => 'CHAVE1', 'isComplete' => false]],
            ], 200),
            '*/inbound-product-invoices/inv-abc/manifest' => Http::response(['status' => 'ok'], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultarNotaRecebida('CHAVE1');

        $this->assertSame('AGUARDANDO_MANIFESTACAO', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/manifest') && $req['status'] === 'acknowledged');
    }

    public function test_consultar_nota_recebida_nao_encontrada(): void
    {
        Http::fake(['*/inbound-product-invoices?*' => Http::response(['items' => []], 200)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultarNotaRecebida('CHAVE-INEXISTENTE');

        $this->assertSame('NAO_ENCONTRADA', $r->status);
    }

    public function test_consultar_nota_recebida_erro_do_provedor(): void
    {
        Http::fake(['*/inbound-product-invoices?*' => Http::response(['message' => 'Chave de API inválida'], 403)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultarNotaRecebida('CHAVE1');

        $this->assertSame('ERRO', $r->status);
        $this->assertStringContainsString('Chave de API inválida', (string) $r->mensagemErro);
    }

    public function test_consultar_nota_recebida_sem_emissor_registrado_nao_chama_a_api(): void
    {
        // Sem EmissorFiscal (estado padrão de qualquer oficina que nunca
        // configurou emissão), o header cairia no masterKey da plataforma —
        // que NÃO é escopado por empresa e devolveria notas de outros
        // tenants. A chamada não pode sair.
        Http::fake();

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', null, null);
        $r = $p->consultarNotaRecebida('35260712345678000199550010000012340000000001');

        $this->assertSame('ERRO', $r->status);
        $this->assertStringContainsString('não está registrada na Spedy', (string) $r->mensagemErro);
        Http::assertNothingSent();
    }

    public function test_listar_notas_recebidas_sem_emissor_registrado_nao_chama_a_api(): void
    {
        Http::fake();

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', null, null);

        $this->assertSame([], $p->listarNotasRecebidas('12345678000199'));
        Http::assertNothingSent();
    }

    public function test_consultar_nota_recebida_com_manifesto_falhando_retorna_erro(): void
    {
        // O POST de manifesto falhando não pode virar AGUARDANDO_MANIFESTACAO:
        // a ciência nunca foi registrada, então "tente de novo em instantes"
        // seria mentira — a nota nunca ficaria completa.
        Http::fake([
            '*/inbound-product-invoices?*' => Http::response([
                'items' => [['id' => 'inv-abc', 'accessKey' => 'CHAVE1', 'isComplete' => false]],
            ], 200),
            '*/inbound-product-invoices/inv-abc/manifest' => Http::response(['message' => 'Add-on não contratado'], 403),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultarNotaRecebida('CHAVE1');

        $this->assertSame('ERRO', $r->status);
        $this->assertStringContainsString('ciência da operação', (string) $r->mensagemErro);
    }

    public function test_listar_notas_recebidas_com_falha_http_lanca_excecao(): void
    {
        // Falha do provedor não pode virar lista vazia — o controller precisa
        // conseguir distinguir "nenhuma nota" de "provedor com erro".
        Http::fake(['*/inbound-product-invoices*' => Http::response(['message' => 'Chave de API inválida'], 403)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Chave de API inválida');
        $p->listarNotasRecebidas('12345678000199');
    }

    public function test_listar_notas_recebidas_mapeia_a_lista(): void
    {
        Http::fake([
            '*/inbound-product-invoices' => Http::response([
                'items' => [
                    ['accessKey' => 'CHAVE1', 'isComplete' => true, 'amount' => 250.5, 'issuedOn' => '2026-09-01T10:00:00', 'issuer' => ['name' => 'Fornecedor A', 'federalTaxNumber' => '11111111000191']],
                    ['accessKey' => 'CHAVE2', 'isComplete' => false, 'amount' => 80.0, 'issuedOn' => '2026-09-02T10:00:00', 'issuer' => ['name' => 'Fornecedor B', 'federalTaxNumber' => '22222222000192']],
                ],
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $resumos = $p->listarNotasRecebidas('12345678000199');

        $this->assertCount(2, $resumos);
        $this->assertSame('CHAVE1', $resumos[0]->chaveAcesso);
        $this->assertSame('Fornecedor A', $resumos[0]->fornecedorNome);
        $this->assertTrue($resumos[0]->completa);
        $this->assertSame('2026-09-01', $resumos[0]->dataEmissao);
        $this->assertFalse($resumos[1]->completa);
    }

    // ── Modo AUTOMATICO_PROVEDOR — emissão via POST /v1/orders ──────────────

    public function test_modo_automatico_emite_via_orders_sem_campo_fiscal_no_payload(): void
    {
        Http::fake([
            '*/orders' => Http::response([
                'id' => 'order-1',
                'invoices' => [['id' => 'inv-spedy-1', 'status' => 'enqueued', 'model' => 'productInvoice']],
            ], 201),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->notaNfeSimplesNacional(['calculoTributarioModo' => 'AUTOMATICO_PROVEDOR']));

        $this->assertSame('PROCESSANDO', $r->status);
        $this->assertSame('inv-spedy-1', $r->referenciaExterna);

        Http::assertSent(function ($req) {
            if (!str_contains($req->url(), '/orders')) {
                return false;
            }
            $body     = $req->data();
            $itemZero = $body['items'][0] ?? [];
            // Nenhum campo fiscal no item — a Spedy calcula.
            return !array_key_exists('ncm', $itemZero)
                && !array_key_exists('cfop', $itemZero)
                && !array_key_exists('taxes', $itemZero)
                && ($itemZero['product']['invoiceModel'] ?? null) === 'productInvoice';
        });
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/product-invoices'));
    }

    public function test_modo_automatico_invoice_rejeitada_vira_rejeitada_com_mensagem(): void
    {
        Http::fake([
            '*/orders' => Http::response([
                'id' => 'order-2',
                'invoices' => [[
                    'id' => 'inv-spedy-2', 'status' => 'rejected',
                    'processingDetail' => ['message' => 'O certificado digital é obrigatório para emissão de NF-e.'],
                ]],
            ], 201),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->notaNfeSimplesNacional(['calculoTributarioModo' => 'AUTOMATICO_PROVEDOR']));

        $this->assertSame('REJEITADA', $r->status);
        $this->assertStringContainsString('certificado digital', (string) $r->mensagemErro);
    }

    public function test_modo_automatico_falha_http_vira_rejeitada(): void
    {
        Http::fake(['*/orders' => Http::response(['message' => 'Empresa não encontrada'], 404)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->notaNfeSimplesNacional(['calculoTributarioModo' => 'AUTOMATICO_PROVEDOR']));

        $this->assertSame('REJEITADA', $r->status);
        $this->assertStringContainsString('Empresa não encontrada', (string) $r->mensagemErro);
    }

    public function test_modo_manual_continua_indo_pra_product_invoices(): void
    {
        Http::fake([
            '*/product-invoices' => Http::response(['id' => 'x', 'status' => 'enqueued'], 201),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        // sem calculoTributarioModo → default 'MANUAL'
        $p->emitir($this->notaNfeSimplesNacional());

        Http::assertSent(fn ($req) => str_contains($req->url(), '/product-invoices'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/orders'));
    }
}
