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

    /**
     * Os 10 valores reais de InvoiceStatus (confirmado ao vivo contra
     * openapi/v1.json, 2026-09-23) — antes do fix, só 5 eram tratados e os
     * outros 5 caíam em statusDesconhecido() (PROCESSANDO pra sempre). O
     * caso mais grave era `denied` (Denegado), um desfecho terminal real
     * que nunca aparecia como falha acionável.
     */
    public function test_map_status_cobre_todos_os_10_valores_reais_do_enum(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $this->assertSame('PROCESSANDO', $p->mapStatus('created'));
        $this->assertSame('PROCESSANDO', $p->mapStatus('enqueued'));
        $this->assertSame('PROCESSANDO', $p->mapStatus('received'));
        $this->assertSame('AUTORIZADA', $p->mapStatus('authorized'));
        $this->assertSame('PROCESSANDO', $p->mapStatus('inContingent'));
        $this->assertSame('REJEITADA', $p->mapStatus('rejected'));
        $this->assertSame('CANCELADA', $p->mapStatus('canceled'));
        $this->assertSame('REJEITADA', $p->mapStatus('denied'));
        $this->assertSame('REJEITADA', $p->mapStatus('removed'));
        $this->assertSame('REJEITADA', $p->mapStatus('disabled'));
    }

    /**
     * `denied` não pode ficar indistinguível de um `rejected` comum na
     * mensagem que o usuário/suporte vê — são juridicamente diferentes
     * (denegação é problema cadastral do emitente, não do documento).
     */
    public function test_consultar_denegada_prefixa_a_mensagem_como_denegado_nao_generico(): void
    {
        Http::fake([
            '*/product-invoices*' => Http::response([
                'items' => [[
                    'status' => 'denied',
                    'processingDetail' => ['message' => 'CNPJ do emitente irregular junto à SEFAZ.'],
                ]],
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $resultado = $p->consultar('os-123', 'NFE');

        $this->assertSame('REJEITADA', $resultado->status);
        $this->assertStringContainsString('[Denegado]', $resultado->mensagemErro);
        $this->assertStringContainsString('CNPJ do emitente irregular', $resultado->mensagemErro);
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
    }

    /**
     * Bug real achado 2026-09-15 (auditoria contra docs.spedy.com.br/
     * api-reference/nfs-e/criar-nfs-e.md): `effectiveDate` está no `required`
     * do schema de POST /v1/service-invoices e nunca era mandado — bloqueava
     * TODA emissão de NFS-e via Spedy por campo obrigatório ausente.
     */
    public function test_payload_nfse_manda_effective_date(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfse($this->nota());

        $this->assertArrayHasKey('effectiveDate', $payload);
        $this->assertNotEmpty($payload['effectiveDate']);
    }

    /**
     * `status` não existe como campo de request (é controlado só pela
     * Spedy) e `operationNature` não existe no schema de NFS-e (só em NF-e/
     * NFC-e) — ambos removidos, ver docblock de montarPayloadNfse().
     */
    public function test_payload_nfse_nao_manda_campos_inexistentes_no_schema(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfse($this->nota());

        $this->assertArrayNotHasKey('status', $payload);
        $this->assertArrayNotHasKey('operationNature', $payload);
    }

    public function test_payload_nfse_manda_integration_id_para_reconciliacao(): void
    {
        // Bug de reconciliação (registrado 2026-09-10, PROGRESSO.md/TAREFAS.md):
        // sem mandar a nossa referência na criação, consultar() nunca consegue
        // achar a nota de volta na Spedy — toda NFS-e emitida via Spedy fica
        // PROCESSANDO pra sempre no nosso banco mesmo já autorizada lá.
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfse($this->nota());

        $this->assertSame('os-123', $payload['integrationId']);
    }

    public function test_payload_nfe_trunca_integration_id_para_36_caracteres(): void
    {
        // BUG REAL DE PRODUÇÃO (2026-09-14, descoberto minutos depois do
        // deploy do fix de reconciliação): a Spedy rejeita a CRIAÇÃO da nota
        // com HTTP 400 "The field IntegrationId must be a string with a
        // maximum length of 36." — a nossa referência interna é sempre
        // `nf-<uuid>` (39 chars: prefixo "nf-" + UUID de 36), estourando o
        // limite. Confirmado batendo direto na Spedy real via tinker.
        // Fix: manda só os últimos 36 chars (o UUID em si, sem o prefixo).
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional([
            'referenciaExterna' => 'nf-be23a656-74fd-49b0-b46f-f0e696aa3b95',
        ]));

        $this->assertSame('be23a656-74fd-49b0-b46f-f0e696aa3b95', $payload['integrationId']);
        $this->assertLessThanOrEqual(36, strlen($payload['integrationId']));
    }

    public function test_emitir_autorizada(): void
    {
        Http::fake([
            '*/service-invoices' => Http::response([
                'id' => 'inv-1', 'status' => 'authorized',
                'accessKey' => 'CHAVE-SP', 'number' => '55',
            ], 201),
            // Bug real de produção (2026-09-14): a Spedy NUNCA devolve o XML
            // inline no corpo de emissão/consulta (doc oficial confirmada) —
            // é preciso baixar via endpoint dedicado. `xml_retorno` ficava
            // sempre vazio pra toda nota autorizada via Spedy antes deste fix.
            '*/service-invoices/inv-1/xml' => Http::response('<xml>fake-nfse</xml>', 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->nota());

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-SP', $r->chave);
        $this->assertSame('55', $r->numero);
        $this->assertSame('<xml>fake-nfse</xml>', $r->xml);

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

    private function emissor(string $regimeTributario): \App\Services\Fiscal\Data\EmissorData
    {
        return new \App\Services\Fiscal\Data\EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina X Ltda', nomeFantasia: 'Oficina X',
            inscricaoEstadual: '123', inscricaoMunicipal: '456', regimeTributario: $regimeTributario,
            email: 'of@x.com', telefone: '11999999999', cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Centro', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '4520-0/01',
        );
    }

    /**
     * Bug real achado 2026-09-15: `lucroPresumido`/`lucroReal` não existem no
     * enum `TaxRegime` real da Spedy (só `simplesNacional`,
     * `simplesNacionalExcessoSublimite`, `regimeNormal`,
     * `simplesNacionalMEI`) — `POST /v1/companies` rejeitava com erro de
     * enum inválido pra toda oficina fora do Simples Nacional, bloqueando o
     * cadastro fiscal inteiro. Lucro Presumido e Lucro Real caem em
     * `regimeNormal`.
     */
    public function test_payload_empresa_lucro_presumido_e_real_mapeiam_pra_regime_normal(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master');

        $this->assertSame('regimeNormal', $p->montarPayloadEmpresa($this->emissor('Lucro Presumido'))['taxRegime']);
        $this->assertSame('regimeNormal', $p->montarPayloadEmpresa($this->emissor('Lucro Real'))['taxRegime']);
        $this->assertSame('simplesNacional', $p->montarPayloadEmpresa($this->emissor('Simples Nacional'))['taxRegime']);
    }

    /**
     * Bug real achado 2026-09-15: `isMain` não existe em
     * `CompanyEconomicActivityDto` — o campo real é `type`
     * (`main`/`secondary`). Era ignorado pela Spedy; nenhuma atividade
     * econômica ficava marcada como principal.
     */
    public function test_payload_empresa_usa_type_main_nao_ismain(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master');
        $payload = $p->montarPayloadEmpresa($this->emissor('Simples Nacional'));

        $this->assertSame('main', $payload['economicActivities'][0]['type']);
        $this->assertArrayNotHasKey('isMain', $payload['economicActivities'][0]);
    }

    /**
     * BUG REAL DE PRODUÇÃO (2026-09-14, achado registrado em TAREFAS.md desde
     * a Rodada 40 seção 1, confirmado agora): `cancelar()` fazia
     * `DELETE /{recurso}/{referencia}` usando a nossa referência interna
     * (`nf-<uuid>`) como se fosse o ID real da Spedy — igual ao bug antigo do
     * `consultar()`. Confirmado batendo direto no sandbox: `DELETE` pela
     * nossa referência dá 404; `DELETE` pelo `id` real da Spedy (obtido via
     * `GET ?integrationId=`) funciona e retorna
     * "Cancelamento da nota fiscal está em processamento.". Fix: busca o
     * `id` real primeiro (mesmo filtro do `consultar()`), só então cancela.
     */
    public function test_cancelar_busca_o_id_real_antes_de_deletar(): void
    {
        Http::fake([
            '*/service-invoices?*' => Http::response([
                'items' => [['id' => 'spedy-real-id-1', 'integrationId' => 'inv-1', 'status' => 'authorized']],
            ], 200),
            '*/service-invoices/spedy-real-id-1' => Http::response([], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-1', 'Serviço não prestado conforme acordado');

        $this->assertSame('CANCELADA', $r->status);
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/service-invoices')
            && !str_contains($req->url(), '/service-invoices/')
            && ($req['integrationId'] ?? null) === 'inv-1'
        );
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/service-invoices/spedy-real-id-1')
            && $req->method() === 'DELETE'
        );
    }

    public function test_cancelar_manda_o_campo_reason_nao_justification(): void
    {
        // docs.spedy.com.br/api-reference/nfs-e/cancelar-nfs-e.md confirma
        // o campo `reason` (nao `justification`, que era um chute anterior).
        Http::fake([
            '*/service-invoices?*' => Http::response(['items' => [['id' => 'spedy-real-id-1']]], 200),
            '*/service-invoices/spedy-real-id-1' => Http::response([], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $p->cancelar('inv-1', 'Serviço não prestado');

        Http::assertSent(fn ($req) => ($req['reason'] ?? null) === 'Serviço não prestado' && !isset($req['justification']));
    }

    public function test_cancelar_nfce_usa_consumer_invoices(): void
    {
        Http::fake([
            '*/consumer-invoices?*' => Http::response(['items' => [['id' => 'spedy-real-nfce-1']]], 200),
            '*/consumer-invoices/spedy-real-nfce-1' => Http::response([], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-nfce-1', 'Erro na emissão', 'NFCE');

        $this->assertSame('CANCELADA', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/consumer-invoices/spedy-real-nfce-1') && $req->method() === 'DELETE');
    }

    public function test_cancelar_nfe_usa_product_invoices(): void
    {
        Http::fake([
            '*/product-invoices?*' => Http::response(['items' => [['id' => 'spedy-real-nfe-1']]], 200),
            '*/product-invoices/spedy-real-nfe-1' => Http::response([], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-nfe-1', 'Erro na emissão', 'NFE');

        $this->assertSame('CANCELADA', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/product-invoices/spedy-real-nfe-1') && $req->method() === 'DELETE');
    }

    public function test_cancelar_sem_encontrar_a_nota_retorna_rejeitada_com_mensagem_clara(): void
    {
        // Nota emitida ANTES do fix de integrationId nunca foi tagueada na
        // Spedy — o filtro não encontra nada. Precisa de mensagem clara
        // (não a genérica "Erro ao cancelar"), já que a causa é diferente
        // (nota antiga, não uma falha de rede).
        Http::fake(['*/product-invoices?*' => Http::response(['items' => []], 200)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-nfe-antiga', 'Erro na emissão', 'NFE');

        $this->assertSame('REJEITADA', $r->status);
        $this->assertStringContainsString('não encontrada', (string) $r->mensagemErro);
        Http::assertNotSent(fn ($req) => $req->method() === 'DELETE');
    }

    public function test_cancelar_extrai_mensagem_real_do_formato_errors_da_spedy(): void
    {
        // Achado real ao investigar o pedido do usuário ("porque está dando
        // erro ao cancelar"): a Spedy devolve erros 400 no formato
        // {"errors":[{"message":"...","path":"..."}]} — mas o código só lia
        // uma chave `message` de nível raiz, que não existe nesse formato,
        // então SEMPRE caía no fallback genérico ("Erro ao cancelar
        // (Spedy)."), escondendo o motivo real (confirmado batendo direto
        // no sandbox: "A nota fiscal não pode ser cancelada."). Mesmo bug
        // afetava emitir()/registrarEmissor() — inclusive explica por que o
        // registro de emissor da stuntmotos nunca teve detalhe do erro real.
        Http::fake([
            '*/product-invoices?*' => Http::response(['items' => [['id' => 'spedy-real-id-1']]], 200),
            '*/product-invoices/spedy-real-id-1' => Http::response(
                ['errors' => [['message' => 'A nota fiscal não pode ser cancelada.', 'path' => null]]],
                400,
            ),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-1', 'Motivo qualquer', 'NFE');

        $this->assertSame('REJEITADA', $r->status);
        $this->assertSame('A nota fiscal não pode ser cancelada.', $r->mensagemErro);
    }

    public function test_cancelar_falha_ao_localizar_nao_vira_cancelada(): void
    {
        Http::fake(['*/product-invoices?*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->cancelar('inv-nfe-1', 'Erro na emissão', 'NFE');

        $this->assertSame('REJEITADA', $r->status);
        Http::assertNotSent(fn ($req) => $req->method() === 'DELETE');
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
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102', 'cest' => '0107600',
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
        unset($item); // mantém o teste original abaixo intacto
    }

    /**
     * Correção 2026-09-23 (NF-e #13, cStat=232 — ver
     * IndicadorIeDestinatarioResolverTest): destinatário PJ com IE real
     * cadastrada precisa sair como contribuinte, não consumidor final.
     * Schema confirmado em openapi/v1.json (SefazInvoiceReceiverDto.
     * stateTaxNumber) — não adivinhado.
     *
     * Correção 2026-09-23 (continuação, auditoria fiscal completa):
     * `isFinalCustomer` NÃO é mais derivado de `indicador_ie` — uma PJ com
     * IE ainda é o consumidor final do serviço/peça pra esta oficina (não
     * compra pra revenda), então continua `true` mesmo tendo IE. Só
     * `stateTaxNumber` reflete a IE em si.
     */
    public function test_payload_nfe_com_ie_do_destinatario_manda_state_tax_number_mas_continua_consumidor_final(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $nota = $this->notaNfeSimplesNacional([
            'tomador' => [
                'nome' => 'Oficina Cliente LTDA', 'cpf_cnpj' => '12345678000199',
                'cep' => '37175-000', 'logradouro' => 'Rua 15 de Novembro', 'numero' => '472',
                'bairro' => 'Centro', 'cidade' => 'Ilicínea', 'uf' => 'MG', 'codigo_ibge' => '3130507',
                'indicador_ie' => 1, 'inscricao_estadual' => '1234567890',
            ],
        ]);

        $payload = $p->montarPayloadNfe($nota);

        $this->assertTrue($payload['isFinalCustomer']);
        $this->assertSame('1234567890', $payload['receiver']['stateTaxNumber']);
    }

    public function test_payload_nfe_destinatario_isento_de_ie_nao_manda_state_tax_number(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $nota = $this->notaNfeSimplesNacional([
            'tomador' => [
                'nome' => 'Empresa Isenta LTDA', 'cpf_cnpj' => '12345678000199',
                'cep' => '37175-000', 'logradouro' => 'Rua 15 de Novembro', 'numero' => '472',
                'bairro' => 'Centro', 'cidade' => 'Ilicínea', 'uf' => 'MG', 'codigo_ibge' => '3130507',
                'indicador_ie' => 2, 'inscricao_estadual' => null,
            ],
        ]);

        $payload = $p->montarPayloadNfe($nota);

        $this->assertTrue($payload['isFinalCustomer']);
        $this->assertArrayNotHasKey('stateTaxNumber', $payload['receiver']);
    }

    public function test_payload_nfe_pessoa_fisica_continua_consumidor_final(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        // indicador_ie ausente do tomador (comportamento default/legado) —
        // continua isFinalCustomer=true, mesmo resultado de antes da
        // correção. Cobre o caso comum (pessoa física) e a ausência do
        // campo em chamadas antigas/testes que não o populam.
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional());

        $this->assertTrue($payload['isFinalCustomer']);
        $this->assertArrayNotHasKey('stateTaxNumber', $payload['receiver']);
    }

    public function test_payload_nfe_original_ainda_bate(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional());
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

    public function test_payload_nfe_manda_integration_id_para_reconciliacao(): void
    {
        // Mesmo bug de reconciliação do teste da NFS-e — a NF-e via
        // product-invoices também nunca mandava a nossa referência.
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfe($this->notaNfeSimplesNacional());

        $this->assertSame('os-999', $payload['integrationId']);
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

    public function test_payload_nfe_manda_cest_do_item(): void
    {
        // SEFAZ rejeitou de verdade (código 806, homologação 2026-09-10):
        // "Operação com ICMS-ST sem informação do CEST". O dado já existia
        // em produtos.cest, só não era lido em NfeService::montarNotaData().
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $item = $p->montarPayloadNfe($this->notaNfeSimplesNacional())['items'][0];

        $this->assertSame('0107600', $item['cest']);
    }

    public function test_payload_nfce_manda_cest_do_item(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $item = $p->montarPayloadNfce($this->notaNfce())['items'][0];

        $this->assertSame('0107600', $item['cest']);
    }

    public function test_payload_nfe_manda_grupo_pis_cofins_zerado(): void
    {
        // SEFAZ rejeitou de verdade (código 745, homologação 2026-09-10):
        // "NF-e sem grupo do PIS". A doc da Spedy marca taxes.pis/cofins
        // como opcionais, mas a SEFAZ exige o grupo (XSD v4.00) em toda
        // NF-e — mesmo achado já confirmado pra NFePHP em MotorNfe. CST 49
        // com tudo zerado é o padrão pra Simples Nacional (PIS/COFINS pago
        // via DAS, não calculado por operação).
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $taxes = $p->montarPayloadNfe($this->notaNfeSimplesNacional())['items'][0]['taxes'];

        $this->assertSame(['cst' => 49, 'baseTax' => 0, 'rate' => 0, 'amount' => 0], $taxes['pis']);
        $this->assertSame(['cst' => 49, 'baseTax' => 0, 'rate' => 0, 'amount' => 0], $taxes['cofins']);
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

    public function test_payload_nfce_manda_integration_id_para_reconciliacao(): void
    {
        // Mesmo bug de reconciliação — consumer-invoices também nunca mandava
        // a nossa referência.
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce());

        $this->assertSame('os-nfce-1', $payload['integrationId']);
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
            '*/product-invoices/inv-nfe-1/xml' => Http::response('<xml>fake-nfe</xml>', 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->notaNfeSimplesNacional());

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-NFE-SP', $r->chave);
        $this->assertSame('77', $r->numero);
        $this->assertSame('<xml>fake-nfe</xml>', $r->xml);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/product-invoices')
            && !str_contains($req->url(), 'consumer-invoices'));
    }

    public function test_consultar_nfe_filtra_por_integration_id(): void
    {
        // Bug de reconciliação (2026-09-10): consultar() batia em
        // GET /product-invoices/{referencia_externa}, mas a Spedy nunca
        // conhece a nossa referência interna como ID dela — 404 sempre.
        // Confirmado empiricamente no sandbox: GET ?integrationId=X filtra
        // de verdade. Corrigido pra filtrar em vez de tentar path/{id}.
        Http::fake([
            '*/product-invoices*' => Http::response([
                'items' => [['status' => 'authorized', 'accessKey' => 'CHAVE-NFE-2', 'number' => '78']],
                'totalCount' => 1,
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('os-999', 'NFE');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-NFE-2', $r->chave);
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/product-invoices')
            && !str_contains($req->url(), '/product-invoices/')
            && ($req['integrationId'] ?? null) === 'os-999'
        );
    }

    public function test_consultar_falha_http_mantem_processando_em_vez_de_rejeitada(): void
    {
        // Bug real confirmado em produção (stuntmotos, 10 NF-e): falha ao
        // CONSULTAR (rede, 401, 5xx) virava REJEITADA local com a mensagem
        // genérica "Erro ao consultar (Spedy)." — sem a SEFAZ ter dito nada.
        // Mesma classe de defeito já corrigida em MotorNfse::consultar()
        // (Rodada 37, PROGRESSO.md): falha de consulta nunca pode virar um
        // status fiscal substantivo, só "ainda não sei" (PROCESSANDO, que o
        // scheduler/polling tentam de novo depois).
        Http::fake(['*/product-invoices*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('os-999', 'NFE');

        $this->assertSame('PROCESSANDO', $r->status);
    }

    public function test_consultar_nfe_sem_item_na_listagem_retorna_processando(): void
    {
        // A criação é assíncrona na Spedy — a nota pode ainda não aparecer na
        // listagem por integrationId no instante da consulta. Isso não é erro
        // nem rejeição, é "ainda processando" (o próximo poll tenta de novo).
        Http::fake(['*/product-invoices*' => Http::response(['items' => [], 'totalCount' => 0], 200)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('os-ainda-nao-existe', 'NFE');

        $this->assertSame('PROCESSANDO', $r->status);
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
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102', 'cest' => '0107600',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
            formaPagamento: 'Dinheiro',
            regimeTributario: 'Simples Nacional',
        );
    }

    /**
     * Correção 2026-09-23 (auditoria fiscal completa): NFC-e nunca mandava
     * a IE do destinatário — mesma classe de gap do bug original de NF-e
     * (cStat=232), só que no fluxo de balcão (menos frequente na prática,
     * maioria dos compradores de NFC-e é PF, mas o schema é o mesmo
     * SefazInvoiceReceiverDto).
     */
    public function test_payload_nfce_com_ie_do_destinatario_manda_state_tax_number(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $nota = $this->notaNfce([
            'nome' => 'Empresa Compradora LTDA', 'cpf_cnpj' => '12345678000199',
            'indicador_ie' => 1, 'inscricao_estadual' => '1234567890',
        ]);

        $payload = $p->montarPayloadNfce($nota);

        $this->assertSame('1234567890', $payload['receiver']['stateTaxNumber']);
        // NFC-e é sempre consumidor final por definição — indicador_ie não
        // deve alterar isso (diferente de indFinal, que nunca foi o bug
        // aqui, só na NF-e).
        $this->assertTrue($payload['isFinalCustomer']);
    }

    public function test_payload_nfce_sem_ie_nao_manda_state_tax_number(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce());

        $this->assertArrayNotHasKey('stateTaxNumber', $payload['receiver']);
    }

    /**
     * Correção 2026-09-23: `presenceType` (indPres) nunca era mandado.
     * Balcão de oficina é sempre operação presencial — mesma regra já
     * hardcoded em MotorNfe::montarNfe() (`indPres => 1`), agora também
     * mandada pra Spedy (campo confirmado ao vivo em openapi/v1.json).
     */
    public function test_payload_nfe_e_nfce_mandam_presence_type_operacao_presencial(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');

        $this->assertSame('presence', $p->montarPayloadNfe($this->notaNfeSimplesNacional())['presenceType']);
        $this->assertSame('presence', $p->montarPayloadNfce($this->notaNfce())['presenceType']);
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
        // Nomes corrigidos 2026-09-15 contra docs.spedy.com.br: `code` (não
        // `productCode`) e `unit` (não `commercialUnit`) — ver docblock de
        // montarPayloadNfce().
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce());

        $this->assertSame('FLT-001', $payload['items'][0]['code']);
        $this->assertSame('PC', $payload['items'][0]['unit']);
    }

    public function test_payload_nfce_usa_campos_confirmados_na_doc(): void
    {
        // Renomeado de "..._campos_spedy_inferidos": campos confirmados
        // 2026-09-15 contra docs.spedy.com.br, não mais inferidos por
        // analogia. `federalTaxNumber` (não `individualTaxNumber` — esse
        // campo não existe no schema do Receiver, nem pra CPF) e
        // `payments[].amount` (não `.value`).
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce());

        $this->assertTrue($payload['isFinalCustomer']);
        $this->assertSame('87748248800', $payload['receiver']['federalTaxNumber']);
        $this->assertArrayNotHasKey('individualTaxNumber', $payload['receiver']);
        $this->assertCount(1, $payload['items']);
        $this->assertSame(5102, $payload['items'][0]['cfop']);
        $this->assertSame(71.0, $payload['payments'][0]['amount']);
    }

    public function test_payload_nfce_usa_valor_de_enum_valido_pro_metodo_de_pagamento(): void
    {
        // `method: 'cash'` (valor antigo) não é um enum válido da Spedy —
        // os válidos incluem `money`, não `cash`. mapFormaPagamento() já é
        // compartilhado com a NF-e.
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce());

        $this->assertSame('money', $payload['payments'][0]['method']);
    }

    public function test_payload_nfce_manda_grupo_pis_cofins_zerado(): void
    {
        // NFC-e nunca mandava PIS/COFINS (schema achado 2026-09-15) — mesmo
        // padrão CST 49 zerado já usado por montarPayloadNfe().
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $taxes = $p->montarPayloadNfce($this->notaNfce())['items'][0]['taxes'];

        $this->assertSame(['cst' => 49, 'baseTax' => 0, 'rate' => 0, 'amount' => 0], $taxes['pis']);
        $this->assertSame(['cst' => 49, 'baseTax' => 0, 'rate' => 0, 'amount' => 0], $taxes['cofins']);
    }

    public function test_payload_nfce_simples_nacional_manda_csosn_aninhado_em_taxes_icms(): void
    {
        // ICMS soltos no item (`icmsOrigin`/`icmsTaxSituation`, nomes que não
        // existem no schema real) trocados por `taxes.icms.origin`/`.csosn`,
        // mesmo formato confirmado e já usado por montarPayloadNfe().
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfce($this->notaNfce());

        $this->assertSame(0, $payload['items'][0]['taxes']['icms']['origin']);
        $this->assertSame(102, $payload['items'][0]['taxes']['icms']['csosn']);
        $this->assertArrayNotHasKey('cst', $payload['items'][0]['taxes']['icms']);
        $this->assertArrayNotHasKey('icmsOrigin', $payload['items'][0]);
        $this->assertArrayNotHasKey('icmsTaxSituation', $payload['items'][0]);
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

    public function test_consultar_nfce_filtra_por_integration_id(): void
    {
        Http::fake([
            '*/consumer-invoices*' => Http::response([
                'items' => [['status' => 'authorized', 'accessKey' => 'CHAVE-NFCE-SP', 'number' => '9']],
                'totalCount' => 1,
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('os-nfce-1', 'NFCE');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-NFCE-SP', $r->chave);
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/consumer-invoices')
            && !str_contains($req->url(), '/consumer-invoices/')
            && ($req['integrationId'] ?? null) === 'os-nfce-1'
        );
    }

    public function test_consultar_nfse_filtra_por_integration_id(): void
    {
        Http::fake([
            '*/service-invoices*' => Http::response([
                'items' => [['status' => 'authorized', 'accessKey' => 'CHAVE-SP-2', 'number' => '15']],
                'totalCount' => 1,
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('os-123', 'NFSE');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-SP-2', $r->chave);
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/service-invoices')
            && !str_contains($req->url(), '/service-invoices/')
            && ($req['integrationId'] ?? null) === 'os-123'
        );
    }

    /**
     * Bug real de produção (2026-09-14, achado verificando "o botão de
     * baixar XML funciona pra todos os motores?"): a doc oficial da Spedy
     * confirma que o corpo de emissão/consulta NUNCA traz o XML inline — é
     * preciso baixar via `GET /{recurso}/{id}/xml`, só disponível depois de
     * autorizada. `resultadoDe()`/`resultadoNfceDe()` liam `$json['xml']`,
     * um campo que a Spedy nunca envia — `xml_retorno` ficava sempre vazio
     * pra toda nota autorizada via Spedy (confirmado: zero notas com
     * `xml_retorno` preenchido no banco de produção antes deste fix).
     */
    public function test_consultar_autorizada_baixa_xml_pelo_endpoint_dedicado(): void
    {
        Http::fake([
            // Ordem importa: Http::fake() casa o PRIMEIRO padrão que bater, e
            // '*/product-invoices*' (wildcard genérico) também bateria com a
            // URL do /xml se viesse primeiro — o padrão específico precisa
            // vir antes do genérico.
            '*/product-invoices/inv-42/xml' => Http::response('<nfeProc>autorizada</nfeProc>', 200),
            '*/product-invoices*' => Http::response([
                'items' => [['id' => 'inv-42', 'status' => 'authorized', 'accessKey' => 'CHAVE-X', 'number' => '1']],
                'totalCount' => 1,
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('ref-1', 'NFE');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('<nfeProc>autorizada</nfeProc>', $r->xml);
    }

    /**
     * Bug real achado 2026-09-15: `protocolo` era sempre `null` (limitação
     * documentada em 2026-08-03 por falta de confirmação) — confirmado agora
     * contra docs.spedy.com.br que a resposta traz `authorization.protocol`.
     */
    public function test_consultar_autorizada_le_protocolo_de_authorization(): void
    {
        Http::fake([
            '*/product-invoices*' => Http::response([
                'items' => [[
                    'id' => 'inv-99', 'status' => 'authorized', 'accessKey' => 'CHAVE-99', 'number' => '5',
                    'authorization' => ['protocol' => '135250000012345'],
                ]],
                'totalCount' => 1,
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('ref-99', 'NFE');

        $this->assertSame('135250000012345', $r->protocolo);
    }

    public function test_consultar_autorizada_sem_id_nao_tenta_baixar_xml(): void
    {
        Http::fake([
            '*/product-invoices*' => Http::response([
                'items' => [['status' => 'authorized', 'accessKey' => 'CHAVE-Y', 'number' => '2']],
                'totalCount' => 1,
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('ref-2', 'NFE');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertNull($r->xml);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/xml'));
    }

    public function test_consultar_autorizada_falha_ao_baixar_xml_nao_derruba_autorizacao(): void
    {
        Http::fake([
            '*/product-invoices/inv-43/xml' => Http::response(['message' => 'not found'], 404),
            '*/product-invoices*' => Http::response([
                'items' => [['id' => 'inv-43', 'status' => 'authorized', 'accessKey' => 'CHAVE-Z', 'number' => '3']],
                'totalCount' => 1,
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultar('ref-3', 'NFE');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertNull($r->xml);
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

    private const INFO_CORREIOS = 'Empresa optante pelo Simples Nacional. Placa: ABC1D23 | Modelo: HONDA CG 160 | KM: 40332';

    private function comInfo(NotaFiscalData $base): NotaFiscalData
    {
        return new NotaFiscalData(...array_merge(get_object_vars($base), ['informacoesComplementares' => self::INFO_CORREIOS]));
    }

    public function test_payload_nfse_manda_informacoes_adicionais(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');

        $this->assertSame(self::INFO_CORREIOS, $p->montarPayloadNfse($this->comInfo($this->nota()))['additionalInformation']);
        $this->assertArrayNotHasKey('additionalInformation', $p->montarPayloadNfse($this->nota()));
    }

    public function test_payload_nfe_e_nfce_mandam_additional_information_infcpl(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $nota = $this->comInfo($this->notaNfeSimplesNacional());

        $this->assertSame(self::INFO_CORREIOS, $p->montarPayloadNfe($nota)['additionalInformation']);
        $this->assertSame(self::INFO_CORREIOS, $p->montarPayloadNfce($nota)['additionalInformation']);
        $this->assertArrayNotHasKey('additionalInformation', $p->montarPayloadNfe($this->notaNfeSimplesNacional()));
    }

    public function test_payload_nfse_manda_codigo_de_tributacao_nacional_de_6_digitos(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');

        $this->assertSame('140101', $p->montarPayloadNfse($this->nota())['nationalTaxationCode']);
    }

    public function test_payload_nfse_omite_codigo_nacional_quando_nao_ha_mapeamento_oficial(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $outro = new NotaFiscalData(...array_merge(get_object_vars($this->nota()), ['codigoServicoFederal' => '07.02']));

        $this->assertArrayNotHasKey('nationalTaxationCode', $p->montarPayloadNfse($outro));
    }
}
