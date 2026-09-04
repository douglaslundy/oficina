<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotaFiscalNfceTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function criarConfiguracao(array $overrides = []): Configuracao
    {
        return Configuracao::create(array_merge([
            'razao_social'      => 'Oficina Teste LTDA',
            'uf'                => 'MG',
            'regime_tributario' => 'Simples Nacional',
        ], $overrides));
    }

    private function criarProduto(array $overrides = []): Produto
    {
        return Produto::create(array_merge([
            'nome' => 'Filtro de Óleo', 'sku' => 'FLT-01', 'categoria' => 'Filtros',
            'qty_atual' => 10, 'qty_minima' => 2, 'preco_venda' => 45,
            'ncm' => '84212300', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
            'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ], $overrides));
    }

    private function payloadVenda(string $clienteId, string $produtoId, array $extra = []): array
    {
        return array_merge([
            'cliente_id'        => $clienteId,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produtoId,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ], $extra);
    }

    public function test_cliente_pessoa_fisica_gera_nfce_automaticamente(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NFC-e');
    }

    public function test_cliente_pessoa_juridica_sempre_gera_nfe(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Oficina Cliente LTDA', 'cpf_cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NF-e');
    }

    public function test_forcar_nfe_com_cliente_pessoa_fisica_gera_nfe(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id, ['forcar_nfe' => true]));

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NF-e');
    }

    public function test_forcar_nfe_com_cliente_pessoa_juridica_e_ignorado(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Oficina Cliente LTDA', 'cpf_cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id, ['forcar_nfe' => true]));

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NF-e');
    }

    public function test_nfce_dentro_do_estado_usa_cfop_5102(): void
    {
        $this->criarConfiguracao(['uf' => 'MG']);
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));

        $notaId = $response->json('data.id');
        $this->assertDatabaseHas('notas_fiscais_itens', [
            'nota_fiscal_id' => $notaId, 'cfop' => '5102', 'cst_csosn' => '102',
        ]);
    }

    public function test_pessoa_fisica_fora_do_estado_cai_para_nfe(): void
    {
        // Venda a consumidor final PF de outra UF: NFC-e é presencial/intrastate por
        // definição legal, então cai pra NF-e (mesmo escape hatch de forcar_nfe) em
        // vez de gerar uma NFC-e interestadual com CFOP e local_destino contraditórios.
        $this->criarConfiguracao(['uf' => 'MG']);
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'SP']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NF-e');

        $notaId = $response->json('data.id');
        $this->assertDatabaseHas('notas_fiscais_itens', [
            'nota_fiscal_id' => $notaId, 'cfop' => '6102', 'cst_csosn' => '102',
        ]);
    }

    public function test_emitir_nfce_autorizada_sincrona_fim_a_fim_via_focus(): void
    {
        $this->criarConfiguracao(['uf' => 'MG']);
        $oficina = \App\Models\Oficina::create([
            'nome' => 'Oficina Focus NFC-e', 'cnpj' => (string) mt_rand(10000000000000, 99999999999999),
            'slug' => 'oficina-nfce-' . uniqid(), 'provedor_fiscal' => 'FOCUS',
        ]);
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        Http::fake([
            '*/v2/nfce?ref=*' => Http::response([
                'status' => 'autorizado', 'numero' => '10', 'chave_nfe' => 'CHAVE-NFCE-E2E',
                'caminho_xml_nota_fiscal' => 'https://focus/xml/nfce-e2e.xml',
                'caminho_danfe' => 'https://focus/danfe/nfce-e2e.pdf',
                'qrcode_url' => 'https://homologacao.nfce.fazenda.mg.gov.br/qrcode?p=CHAVE',
            ], 201),
            'https://focus/xml/nfce-e2e.xml' => Http::response('<xml>nfce e2e</xml>', 200),
        ]);

        $headers = ['X-Tenant' => $oficina->slug];
        $criar = $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));
        $notaId = $criar->json('data.id');

        $emitir = $this->withToken($token)->withHeaders($headers)
            ->postJson("/api/notas-fiscais/{$notaId}/emitir");

        $emitir->assertStatus(200)
            ->assertJsonPath('data.status', 'AUTORIZADA')
            ->assertJsonPath('data.numero', 10)
            ->assertJsonPath('data.chave_acesso', 'CHAVE-NFCE-E2E');

        $this->assertDatabaseHas('notas_fiscais', [
            'id' => $notaId, 'status' => 'AUTORIZADA', 'numero' => 10, 'qrcode_url' => 'https://homologacao.nfce.fazenda.mg.gov.br/qrcode?p=CHAVE',
        ]);
    }

    public function test_numeracao_nfce_nao_compartilha_contador_com_nfe(): void
    {
        $this->criarConfiguracao(['uf' => 'MG']);
        $oficina = \App\Models\Oficina::create([
            'nome' => 'Oficina Numeracao', 'cnpj' => (string) mt_rand(10000000000000, 99999999999999),
            'slug' => 'oficina-num-' . uniqid(), 'provedor_fiscal' => 'FOCUS',
        ]);
        $token = $this->loginAdmin();
        $headers = ['X-Tenant' => $oficina->slug];

        Http::fake([
            '*/v2/nfce?ref=*' => Http::response(['status' => 'autorizado', 'numero' => '1', 'chave_nfe' => 'K1'], 201),
            '*/v2/nfe?ref=*'  => Http::response(['status' => 'autorizado', 'numero' => '500', 'chave_nfe' => 'K2'], 201),
        ]);

        $clientePf = Cliente::create(['nome' => 'PF', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $clientePj = Cliente::create(['nome' => 'PJ', 'cpf_cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'uf' => 'MG']);
        $produto   = $this->criarProduto();

        $nfce1 = $this->withToken($token)->withHeaders($headers)->postJson('/api/notas-fiscais', $this->payloadVenda($clientePf->id, $produto->id));
        $this->withToken($token)->withHeaders($headers)->postJson("/api/notas-fiscais/{$nfce1->json('data.id')}/emitir");

        $nfe1 = $this->withToken($token)->withHeaders($headers)->postJson('/api/notas-fiscais', $this->payloadVenda($clientePj->id, $produto->id));
        $this->withToken($token)->withHeaders($headers)->postJson("/api/notas-fiscais/{$nfe1->json('data.id')}/emitir");

        $nfce2 = $this->withToken($token)->withHeaders($headers)->postJson('/api/notas-fiscais', $this->payloadVenda($clientePf->id, $produto->id));
        $emitirNfce2 = $this->withToken($token)->withHeaders($headers)->postJson("/api/notas-fiscais/{$nfce2->json('data.id')}/emitir");

        // Sequência: emite NFC-e (consome proximo_numero_nfce: 1->2), emite NF-e
        // (consome proximo_numero_nf: 1->2), emite outra NFC-e (consome
        // proximo_numero_nfce: 2->3). Se os contadores fossem compartilhados por
        // engano, proximo_numero_nf teria avançado pra 3 também — a asserção exata
        // (não só "diferentes") é o que realmente prova o isolamento.
        $emitirNfce2->assertStatus(200);
        $this->assertSame(2, \App\Models\Configuracao::first()->proximo_numero_nf);
        $this->assertSame(3, \App\Models\Configuracao::first()->proximo_numero_nfce);
    }

    public function test_status_consulta_provedor_quando_processando_e_atualiza_para_autorizada(): void
    {
        $this->criarConfiguracao(['uf' => 'MG']);
        $oficina = \App\Models\Oficina::create([
            'nome' => 'Oficina Spedy NFC-e', 'cnpj' => (string) mt_rand(10000000000000, 99999999999999),
            'slug' => 'oficina-spedy-nfce-' . uniqid(), 'provedor_fiscal' => 'SPEDY',
        ]);
        $token   = $this->loginAdmin();
        $headers = ['X-Tenant' => $oficina->slug];
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        Http::fake([
            '*/consumer-invoices' => Http::response(['id' => 'inv-1', 'status' => 'enqueued'], 202),
            '*/consumer-invoices/*' => Http::response([
                'status' => 'authorized', 'accessKey' => 'CHAVE-POLL', 'number' => '3',
            ], 200),
        ]);

        $criar  = $this->withToken($token)->withHeaders($headers)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));
        $notaId = $criar->json('data.id');
        $emitir = $this->withToken($token)->withHeaders($headers)->postJson("/api/notas-fiscais/{$notaId}/emitir");
        $emitir->assertJsonPath('data.status', 'PROCESSANDO');

        $status = $this->withToken($token)->withHeaders($headers)->getJson("/api/notas-fiscais/{$notaId}/status");

        $status->assertStatus(200)
            ->assertJsonPath('data.status', 'AUTORIZADA')
            ->assertJsonPath('data.chave_acesso', 'CHAVE-POLL');
        $this->assertDatabaseHas('notas_fiscais', ['id' => $notaId, 'status' => 'AUTORIZADA']);
    }

    public function test_status_nao_consulta_provedor_quando_ja_autorizada(): void
    {
        $this->criarConfiguracao(['uf' => 'MG']);
        $oficina = \App\Models\Oficina::create([
            'nome' => 'Oficina Focus 2', 'cnpj' => (string) mt_rand(10000000000000, 99999999999999),
            'slug' => 'oficina-focus2-' . uniqid(), 'provedor_fiscal' => 'FOCUS',
        ]);
        $token   = $this->loginAdmin();
        $headers = ['X-Tenant' => $oficina->slug];
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        Http::fake([
            '*/v2/nfce?ref=*' => Http::response(['status' => 'autorizado', 'numero' => '1', 'chave_nfe' => 'K1'], 201),
        ]);

        $criar  = $this->withToken($token)->withHeaders($headers)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));
        $notaId = $criar->json('data.id');
        $this->withToken($token)->withHeaders($headers)->postJson("/api/notas-fiscais/{$notaId}/emitir");

        // Http::fake() acumula o histórico de requests em Http::$recorded até a
        // próxima chamada a fake() (que o zera) — o emitir() acima já disparou uma
        // request real pro fake da SEFAZ, então resetamos aqui pra isolar só o que
        // o status() faz a seguir. status() só chama o provedor quando a nota está
        // PROCESSANDO; como ela já está AUTORIZADA aqui, uma implementação correta
        // nem tenta consultar de novo. Http::assertNothingSent() abaixo prova
        // diretamente que nenhuma chamada HTTP saiu — não dependemos de como um
        // Http::fake() sem rota registrada se comportaria.
        Http::fake();

        $status = $this->withToken($token)->withHeaders($headers)->getJson("/api/notas-fiscais/{$notaId}/status");

        Http::assertNothingSent();
        $status->assertStatus(200)->assertJsonPath('data.status', 'AUTORIZADA');
    }

    public function test_pdf_de_nfce_autorizada_retorna_200(): void
    {
        $this->criarConfiguracao(['uf' => 'MG']);
        $oficina = \App\Models\Oficina::create([
            'nome' => 'Oficina PDF NFC-e', 'cnpj' => (string) mt_rand(10000000000000, 99999999999999),
            'slug' => 'oficina-pdf-nfce-' . uniqid(), 'provedor_fiscal' => 'FOCUS',
        ]);
        $token   = $this->loginAdmin();
        $headers = ['X-Tenant' => $oficina->slug];
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        Http::fake([
            '*/v2/nfce?ref=*' => Http::response([
                'status' => 'autorizado', 'numero' => '7', 'chave_nfe' => 'CHAVE-PDF',
                'qrcode_url' => 'https://homologacao.nfce.fazenda.mg.gov.br/qrcode?p=CHAVE',
            ], 201),
        ]);

        $criar  = $this->withToken($token)->withHeaders($headers)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));
        $notaId = $criar->json('data.id');
        $emitir = $this->withToken($token)->withHeaders($headers)->postJson("/api/notas-fiscais/{$notaId}/emitir");
        $emitir->assertStatus(200);
        $this->assertDatabaseHas('notas_fiscais', ['id' => $notaId, 'qrcode_url' => 'https://homologacao.nfce.fazenda.mg.gov.br/qrcode?p=CHAVE']);

        $pdf = $this->withToken($token)->withHeaders($headers)->get("/api/notas-fiscais/{$notaId}/pdf");

        $pdf->assertStatus(200);
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
    }
}
