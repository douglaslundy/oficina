<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotaFiscalNfeTest extends TestCase
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
            'uf'                => 'SP',
            'regime_tributario' => 'Simples Nacional',
        ], $overrides));
    }

    private function criarCliente(array $overrides = []): Cliente
    {
        // CNPJ (não CPF) de propósito: cliente PF (CPF) é roteado
        // automaticamente pra NFC-e desde a Rodada 25 — este arquivo testa
        // especificamente o fluxo de NF-e (modelo 55).
        return Cliente::create(array_merge([
            'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199', 'status' => 'REGULAR', 'uf' => 'SP',
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

    public function test_rejeita_natureza_misto(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Misto',
            'subtotal'          => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['natureza_operacao']);
    }

    public function test_venda_de_mercadoria_persiste_itens_com_dados_fiscais_do_produto(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produto->id,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NF-e');

        $notaId = $response->json('data.id');
        $this->assertDatabaseHas('notas_fiscais_itens', [
            'nota_fiscal_id' => $notaId,
            'produto_id'     => $produto->id,
            'ncm'            => '84212300',
            'origem'         => 0,
            // Cliente e oficina no mesmo estado (SP/SP), sem ST -> CFOP 5102
            // (Convênio s/nº de 15/12/1970). Regime Simples Nacional + NORMAL -> CSOSN 102
            // (Ajuste SINIEF 03/2010, Anexo Único, Tabela B). Ver CfopSaidaResolver e
            // TributacaoIcmsSaidaResolver.
            'cfop'           => '5102',
            'cst_csosn'      => '102',
        ]);
        $this->assertDatabaseHas('notas_fiscais', ['id' => $notaId, 'subtotal' => 90.00]);
    }

    public function test_venda_de_mercadoria_calcula_cfop_interestadual_quando_cliente_e_oficina_estao_em_ufs_diferentes(): void
    {
        // Oficina em SP, cliente em RJ -> operação interestadual sem ST -> CFOP 6102
        // (em vez do CFOP 5102 usado dentro do mesmo estado). Esse é exatamente o
        // ramo que ficaria sem cobertura se o CfopSaidaResolver estivesse mal
        // encanado (ex.: sempre usando o CFOP "de dentro do estado").
        $this->criarConfiguracao(['uf' => 'SP']);
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente(['uf' => 'RJ']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produto->id,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ]);

        $response->assertStatus(201);

        $notaId = $response->json('data.id');
        $this->assertDatabaseHas('notas_fiscais_itens', [
            'nota_fiscal_id' => $notaId,
            'produto_id'     => $produto->id,
            'cfop'           => '6102',
            'cst_csosn'      => '102',
        ]);
    }

    public function test_emitir_venda_de_mercadoria_fim_a_fim_via_provedor_focus(): void
    {
        // Round-trip completo: cria a NF-e (POST /notas-fiscais) e emite
        // (POST /notas-fiscais/{id}/emitir) contra um Http::fake da Focus, provando
        // que o resultado real do provedor (numero, chave, xml) chega até o registro
        // persistido — não só a camada de unit do FocusNfeProvider isoladamente.
        $this->criarConfiguracao(['uf' => 'SP']);
        $oficina = Oficina::create([
            'nome' => 'Oficina Focus Teste', 'cnpj' => (string) mt_rand(10000000000000, 99999999999999),
            'slug' => 'oficina-focus-' . uniqid(), 'provedor_fiscal' => 'FOCUS',
        ]);
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente(['uf' => 'SP']);
        $produto = $this->criarProduto();

        Http::fake([
            '*/v2/nfe?ref=*' => Http::response([
                'status'                  => 'autorizado',
                'numero'                  => '4321',
                'numero_protocolo'        => '151260029467289',
                'chave_nfe'               => 'CHAVE-E2E-XYZ',
                'caminho_xml_nota_fiscal' => 'https://focus/xml/e2e.xml',
                'caminho_danfe'           => 'https://focus/danfe/e2e.pdf',
            ], 201),
            'https://focus/xml/e2e.xml' => Http::response('<xml>nfe e2e</xml>', 200),
        ]);

        $headers = ['X-Tenant' => $oficina->slug];

        $criar = $this->withToken($token)->withHeaders($headers)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produto->id,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ]);
        $criar->assertStatus(201);
        $notaId = $criar->json('data.id');

        $emitir = $this->withToken($token)->withHeaders($headers)
            ->postJson("/api/notas-fiscais/{$notaId}/emitir");

        $emitir->assertStatus(200)
            ->assertJsonPath('data.status', 'AUTORIZADA')
            ->assertJsonPath('data.numero', 4321)
            ->assertJsonPath('data.chave_acesso', 'CHAVE-E2E-XYZ');

        $this->assertDatabaseHas('notas_fiscais', [
            'id'           => $notaId,
            'status'       => 'AUTORIZADA',
            'numero'       => 4321,
            'chave_acesso' => 'CHAVE-E2E-XYZ',
        ]);
    }

    public function test_venda_de_mercadoria_exige_itens(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['itens']);
    }

    public function test_venda_de_mercadoria_bloqueia_sem_uf_ou_regime_da_configuracao(): void
    {
        // Nenhuma Configuracao criada — não pode cair num default silencioso
        // de UF/regime tributário pra montar o CFOP/CST-CSOSN.
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produto->id,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ]);

        $response->assertStatus(422)->assertJsonPath(
            'message',
            'Complete a UF e o regime tributário da empresa em Configurações antes de emitir NF-e.'
        );
        $this->assertDatabaseCount('notas_fiscais', 0);
    }

    public function test_venda_de_mercadoria_bloqueia_sem_uf_do_cliente(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente(['uf' => null]);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produto->id,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ]);

        $response->assertStatus(422)->assertJsonPath(
            'message',
            'Complete a UF do cliente antes de emitir NF-e.'
        );
        $this->assertDatabaseCount('notas_fiscais', 0);
    }

    public function test_venda_de_mercadoria_bloqueia_produto_com_tributacao_icms_pendente(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $produto = $this->criarProduto(['tributacao_icms' => null]);

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produto->id,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ]);

        $response->assertStatus(422)->assertJsonPath(
            'message',
            "Produto \"{$produto->nome}\" está com a tributação de ICMS pendente de revisão. Complete em Produtos › Pendências Fiscais antes de emitir NF-e."
        );
        $this->assertDatabaseCount('notas_fiscais', 0);
    }

    public function test_venda_de_mercadoria_bloqueia_produto_com_origem_pendente(): void
    {
        // origem === null não pode virar (int) 0 silenciosamente no payload da Focus —
        // 0 é "mercadoria nacional", um fato fiscal específico, não um placeholder de
        // "vazio". Mesma família de guarda que a de tributacao_icms acima, mas para
        // um campo com semântica de default perigosa (ver fix #3 da onda final).
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $produto = $this->criarProduto(['origem' => null]);

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produto->id,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ]);

        $response->assertStatus(422)->assertJsonPath(
            'message',
            "Produto \"{$produto->nome}\" está com a origem da mercadoria pendente de revisão. Complete em Produtos › Pendências Fiscais antes de emitir NF-e."
        );
        $this->assertDatabaseCount('notas_fiscais', 0);
    }
}
