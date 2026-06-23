<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Produto;
use App\Models\Usuario;
use App\Services\AlertaDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class OrdemServicoTest extends TestCase
{
    use RefreshDatabase;

    private function setupEntities(): array
    {
        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => 'a@t.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('pass'),
        ]);
        $cliente = Cliente::create(['nome' => 'Cliente Teste', 'cpf_cnpj' => '87748248800']);
        $token = $admin->createToken('t')->plainTextToken;
        return [$token, $admin->id, $cliente->id];
    }

    public function test_criar_os(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();

        $response = $this->withToken($token)->postJson('/api/os', [
            'cliente_id'        => $cliId,
            'mecanico_id'       => $mecId,
            'problema_relatado' => 'Troca de óleo',
            'status'            => 'ABERTA',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['data' => ['id', 'numero', 'status']]);
    }

    private function criarProduto(int $qtyAtual = 10): Produto
    {
        return Produto::create([
            'nome' => 'Filtro', 'sku' => 'FLT01', 'categoria' => 'Filtros',
            'qty_atual' => $qtyAtual, 'qty_minima' => 3, 'preco_venda' => 50,
        ]);
    }

    public function test_estoque_baixa_ao_inserir_peca_na_os(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId,
            'problema_relatado' => 'Troca filtro', 'status' => 'ABERTA',
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50,
            ]],
        ])->assertStatus(201);

        // Baixa é imediata na criação, sem precisar concluir a OS.
        $this->assertEquals(8, $produto->fresh()->qty_atual);
    }

    public function test_concluir_os_nao_baixa_estoque_em_duplicidade(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId,
            'status' => 'ABERTA',
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50,
            ]],
        ])->json('data');

        // Já baixou para 8 na criação.
        $this->assertEquals(8, $produto->fresh()->qty_atual);

        $this->withToken($token)->putJson("/api/os/{$os['id']}", [
            'status' => 'CONCLUIDA', 'valor_pago' => 100,
        ])->assertOk();

        // Concluir não pode baixar de novo.
        $this->assertEquals(8, $produto->fresh()->qty_atual);
    }

    public function test_adicionar_peca_via_endpoint_baixa_estoque(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA',
        ])->json('data');

        $this->withToken($token)->postJson("/api/os/{$os['id']}/itens", [
            'tipo' => 'PECA', 'produto_id' => $produto->id,
            'descricao' => 'Filtro', 'quantidade' => 3, 'valor_unitario' => 50,
        ])->assertStatus(201);

        $this->assertEquals(7, $produto->fresh()->qty_atual);
    }

    public function test_remover_peca_via_endpoint_devolve_estoque(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA',
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 3, 'valor_unitario' => 50,
            ]],
        ])->json('data');

        $this->assertEquals(7, $produto->fresh()->qty_atual);
        $itemId = $os['itens'][0]['id'];

        $this->withToken($token)->deleteJson("/api/os/{$os['id']}/itens/{$itemId}")
            ->assertOk();

        // Ao remover, o estoque volta.
        $this->assertEquals(10, $produto->fresh()->qty_atual);
    }

    public function test_inserir_peca_sem_estoque_suficiente_retorna_422(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(1);

        $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA',
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 5, 'valor_unitario' => 50,
            ]],
        ])->assertStatus(422)
          ->assertJsonFragment(['message' => 'Estoque insuficiente para: Filtro']);

        // Estoque não pode ter sido alterado (transação revertida).
        $this->assertEquals(1, $produto->fresh()->qty_atual);
    }

    public function test_mudanca_de_status_dispara_alerta(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();

        $spy = Mockery::spy(AlertaDispatchService::class);
        $this->app->instance(AlertaDispatchService::class, $spy);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA',
        ])->json('data');

        $this->withToken($token)->putJson("/api/os/{$os['id']}", [
            'status' => 'EM_ANDAMENTO',
        ])->assertOk();

        $spy->shouldHaveReceived('dispatch')
            ->withArgs(fn ($tipo, $vars = []) => $tipo === 'OS_STATUS_MUDOU'
                && ($vars['status'] ?? null) === 'EM_ANDAMENTO')
            ->once();
    }

    public function test_cancelar_os_devolve_estoque(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA',
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50,
            ]],
        ])->json('data');

        $this->assertEquals(8, $produto->fresh()->qty_atual);

        $this->withToken($token)->putJson("/api/os/{$os['id']}", [
            'status' => 'CANCELADA',
        ])->assertOk();

        $this->assertEquals(10, $produto->fresh()->qty_atual);
    }

    public function test_cancelar_os_com_devolver_estoque_false_nao_devolve(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA',
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50,
            ]],
        ])->json('data');

        $this->assertEquals(8, $produto->fresh()->qty_atual);

        $this->withToken($token)->putJson("/api/os/{$os['id']}", [
            'status' => 'CANCELADA', 'devolver_estoque' => false,
        ])->assertOk();

        // Opção de não devolver: estoque permanece baixado.
        $this->assertEquals(8, $produto->fresh()->qty_atual);
    }

    public function test_cancelar_os_com_devolver_estoque_true_devolve(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA',
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50,
            ]],
        ])->json('data');

        $this->assertEquals(8, $produto->fresh()->qty_atual);

        $this->withToken($token)->putJson("/api/os/{$os['id']}", [
            'status' => 'CANCELADA', 'devolver_estoque' => true,
        ])->assertOk();

        $this->assertEquals(10, $produto->fresh()->qty_atual);
    }

    public function test_os_cancelada_nao_pode_mudar_de_status(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA',
        ])->json('data');

        $this->withToken($token)->putJson("/api/os/{$os['id']}", [
            'status' => 'CANCELADA', 'devolver_estoque' => false,
        ])->assertOk();

        // Tentar reabrir/concluir uma OS cancelada deve ser rejeitado.
        $this->withToken($token)->putJson("/api/os/{$os['id']}", [
            'status' => 'EM_ANDAMENTO',
        ])->assertStatus(422)
          ->assertJsonFragment(['message' => 'OS cancelada não pode ter o status alterado.']);

        $this->assertEquals('CANCELADA', \App\Models\OrdemServico::find($os['id'])->status);
    }
}
