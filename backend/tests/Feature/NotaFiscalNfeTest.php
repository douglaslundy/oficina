<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        return Cliente::create(array_merge([
            'nome' => 'Cliente Teste', 'cpf_cnpj' => '87748248800', 'status' => 'REGULAR', 'uf' => 'SP',
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
        ]);
        $this->assertDatabaseHas('notas_fiscais', ['id' => $notaId, 'subtotal' => 90.00]);
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
}
