<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_baixar_estoque_ao_concluir_os(): void
    {
        [$token, $mecId, $cliId] = $this->setupEntities();

        $produto = Produto::create([
            'nome' => 'Filtro', 'sku' => 'FLT01', 'categoria' => 'Filtros',
            'qty_atual' => 10, 'qty_minima' => 3, 'preco_venda' => 50,
        ]);

        $os = $this->withToken($token)->postJson('/api/os', [
            'cliente_id' => $cliId, 'mecanico_id' => $mecId,
            'problema_relatado' => 'Troca filtro', 'status' => 'ABERTA',
            'itens' => [[
                'tipo' => 'PECA', 'produto_id' => $produto->id,
                'descricao' => 'Filtro', 'quantidade' => 2, 'valor_unitario' => 50,
            ]],
        ])->json('data');

        $this->withToken($token)->patchJson("/api/os/{$os['id']}", [
            'status' => 'CONCLUIDA', 'valor_pago' => 100,
        ]);

        $this->assertEquals(8, $produto->fresh()->qty_atual);
    }
}
