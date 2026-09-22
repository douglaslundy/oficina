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
            'km_atual'          => 1000,
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

    /**
     * Bug real achado em auditoria (2026-09-14): `$total += quantidade *
     * valor_unitario` em loop, sem `round()`, pode gerar ruído de
     * subcentavo (ex.: 33,33 + 33,33 + 33,34 = 99,99999999999999 em vez de
     * 100,00 exato). Isso fazia `min($totalPago, $total)` gravar
     * `valor_pago` com o valor "sujo", e `ClienteStatusService::recalcular()`
     * (`valor_pago < valor_total`) podia marcar o cliente como DEVEDOR
     * mesmo com o pagamento completo — uma fração de centavo invisível ao
     * usuário.
     */
    public function test_total_da_os_nao_acumula_ruido_de_ponto_flutuante(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto1 = $this->criarProduto(10);
        $produto2 = $this->criarProduto(10);
        $produto3 = $this->criarProduto(10);

        $response = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId,
            'problema_relatado' => 'Teste arredondamento', 'status' => 'ABERTA', 'km_atual' => 1000,
            'itens' => [
                ['tipo' => 'PECA', 'produto_id' => $produto1->id, 'descricao' => 'Item 1', 'quantidade' => 1, 'valor_unitario' => 33.33],
                ['tipo' => 'PECA', 'produto_id' => $produto2->id, 'descricao' => 'Item 2', 'quantidade' => 1, 'valor_unitario' => 33.33],
                ['tipo' => 'PECA', 'produto_id' => $produto3->id, 'descricao' => 'Item 3', 'quantidade' => 1, 'valor_unitario' => 33.34],
            ],
            'pagamentos' => [['forma_pagamento' => 'Dinheiro', 'valor' => 100.00]],
        ]);

        $response->assertStatus(201);
        $osId = $response->json('data.id');

        $os = \App\Models\OrdemServico::find($osId);
        $this->assertSame(100.0, (float) $os->valor_total);
        // Antes do fix: valor_pago podia ficar "sujo" (99.99999999999999),
        // menor que valor_total (100.0 exato salvo pelo Postgres) —
        // marcando o cliente como DEVEDOR mesmo com pagamento completo.
        $this->assertSame(100.0, (float) $os->valor_pago);
        $this->assertGreaterThanOrEqual((float) $os->valor_total, (float) $os->valor_pago);
    }

    public function test_estoque_baixa_ao_inserir_peca_na_os(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId,
            'problema_relatado' => 'Troca filtro', 'status' => 'ABERTA', 'km_atual' => 1000,
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
            'status' => 'ABERTA', 'km_atual' => 1000,
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
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
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
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
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
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
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
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
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
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
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
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
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
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
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
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
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

    /**
     * Tarefa 2026-09-22: campo de desconto ao registrar pagamento (OS e
     * PDV). Testes cobrindo o clamp (desconto nunca deixa o total negativo)
     * e o reflexo do desconto no `valor_total`/`saldo_devedor` — a
     * integração fiscal (rateio entre NF-e/NFS-e) tem cobertura própria em
     * `Fiscal/EmissaoOrquestradorTest`.
     */
    public function test_criar_os_com_desconto_reduz_valor_total(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $response = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50,
            ]],
            'desconto' => 30,
        ]);

        $response->assertStatus(201);
        $os = \App\Models\OrdemServico::find($response->json('data.id'));
        // Subtotal dos itens é 2 x R$50 = R$100 — com desconto de R$30, total = R$70.
        $this->assertSame(30.0, (float) $os->desconto);
        $this->assertSame(70.0, (float) $os->valor_total);
    }

    public function test_desconto_maior_que_subtotal_e_clampado_sem_deixar_total_negativo(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 1, 'valor_unitario' => 50,
            ]],
            'desconto' => 500,
        ])->json('data');

        $fresh = \App\Models\OrdemServico::find($os['id']);
        $this->assertSame(50.0, (float) $fresh->desconto, 'Desconto não pode ultrapassar o subtotal dos itens.');
        $this->assertSame(0.0, (float) $fresh->valor_total);
    }

    public function test_atualizar_desconto_da_os_recalcula_valor_total(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50,
            ]],
        ])->json('data');
        $this->assertSame(100.0, (float) \App\Models\OrdemServico::find($os['id'])->valor_total);

        $this->withToken($token)->putJson("/api/os/{$os['id']}", ['desconto' => 25])->assertOk();

        $fresh = \App\Models\OrdemServico::find($os['id']);
        $this->assertSame(25.0, (float) $fresh->desconto);
        $this->assertSame(75.0, (float) $fresh->valor_total);
    }

    /**
     * Item removido depois do desconto aplicado pode deixar o desconto
     * maior que o novo subtotal — `recalcularTotalComDesconto()` precisa
     * reclampar, não só recalcular o subtotal.
     */
    public function test_remover_item_reclampa_desconto_maior_que_o_novo_subtotal(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produtoA = $this->criarProduto(10);
        $produtoB = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
            'itens' => [
                ['tipo' => 'PECA', 'produto_id' => $produtoA->id, 'descricao' => 'A', 'quantidade' => 1, 'valor_unitario' => 60],
                ['tipo' => 'PECA', 'produto_id' => $produtoB->id, 'descricao' => 'B', 'quantidade' => 1, 'valor_unitario' => 40],
            ],
        ])->json('data');

        $this->withToken($token)->putJson("/api/os/{$os['id']}", ['desconto' => 80])->assertOk();
        $this->assertSame(20.0, (float) \App\Models\OrdemServico::find($os['id'])->valor_total);

        $itemB = collect($os['itens'])->firstWhere('descricao', 'B');
        $this->withToken($token)->deleteJson("/api/os/{$os['id']}/itens/{$itemB['id']}")->assertOk();

        $fresh = \App\Models\OrdemServico::find($os['id']);
        // Novo subtotal é só o item A (60) — o desconto de 80 não cabe mais.
        $this->assertSame(60.0, (float) $fresh->desconto);
        $this->assertSame(0.0, (float) $fresh->valor_total);
    }

    public function test_registrar_pagamento_com_desconto_reduz_saldo_devedor_e_grava_forma_pagamento(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();
        $produto = $this->criarProduto(10);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50,
            ]],
        ])->json('data');

        $this->withToken($token)->postJson("/api/os/{$os['id']}/pagamentos", [
            'forma_pagamento' => 'PIX', 'valor' => 80, 'desconto' => 20,
        ])->assertStatus(201);

        $fresh = \App\Models\OrdemServico::find($os['id']);
        $this->assertSame(20.0, (float) $fresh->desconto);
        $this->assertSame(80.0, (float) $fresh->valor_total, 'valor_total = 100 (subtotal) - 20 (desconto).');
        $this->assertSame(80.0, (float) $fresh->valor_pago);
        $this->assertSame(0.0, (float) $fresh->saldo_devedor);
        // Formulário de pagamento é agora a única fonte de forma_pagamento da OS.
        $this->assertSame('PIX', $fresh->forma_pagamento);
    }

    public function test_listagem_de_os_esconde_canceladas_por_padrao(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();

        $aberta = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
        ])->json('data');
        $cancelada = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId, 'status' => 'ABERTA', 'km_atual' => 1000,
        ])->json('data');
        $this->withToken($token)->putJson("/api/os/{$cancelada['id']}", [
            'status' => 'CANCELADA', 'devolver_estoque' => false,
        ])->assertOk();

        // Padrão (sem filtro): OS cancelada some da lista.
        $semFiltro = $this->withToken($token)->getJson('/api/os')->json('data');
        $this->assertTrue(collect($semFiltro)->contains('id', $aberta['id']));
        $this->assertFalse(collect($semFiltro)->contains('id', $cancelada['id']));

        // Checkbox "Mostrar OS canceladas" marcado: volta a aparecer.
        $comCheckbox = $this->withToken($token)->getJson('/api/os?incluir_canceladas=1')->json('data');
        $this->assertTrue(collect($comCheckbox)->contains('id', $cancelada['id']));

        // Filtro explícito de status também prevalece sobre o padrão.
        $filtroExplicito = $this->withToken($token)->getJson('/api/os?status=CANCELADA')->json('data');
        $this->assertTrue(collect($filtroExplicito)->contains('id', $cancelada['id']));
        $this->assertFalse(collect($filtroExplicito)->contains('id', $aberta['id']));
    }
}
