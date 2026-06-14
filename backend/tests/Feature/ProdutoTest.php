<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProdutoTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome'       => 'Admin',
            'email'      => 'admin@test.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function criarProduto(array $overrides = []): Produto
    {
        return Produto::create(array_merge([
            'nome'       => 'Filtro de Óleo',
            'sku'        => 'FLT-001',
            'categoria'  => 'Filtros',
            'qty_atual'  => 20,
            'qty_minima' => 5,
            'preco_custo' => 15.00,
            'preco_venda' => 35.00,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // CRUD via API
    // -------------------------------------------------------------------------

    public function test_criar_produto(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/produtos', [
            'nome'       => 'Pastilha de Freio',
            'sku'        => 'PAS-001',
            'categoria'  => 'Freios',
            'qty_atual'  => 10,
            'qty_minima' => 2,
            'preco_custo' => 40.00,
            'preco_venda' => 90.00,
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['data' => ['id', 'nome', 'sku', 'categoria', 'qty_atual', 'status_estoque']]);

        $this->assertDatabaseHas('produtos', ['sku' => 'PAS-001', 'nome' => 'Pastilha de Freio']);
    }

    public function test_listar_produtos(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto();
        $this->criarProduto(['sku' => 'FLT-002', 'nome' => 'Filtro de Ar']);

        $response = $this->withToken($token)->getJson('/api/produtos');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page']]);

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_sku_unico(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto(['sku' => 'DUPLICADO']);

        $response = $this->withToken($token)->postJson('/api/produtos', [
            'nome'      => 'Outro Produto',
            'sku'       => 'DUPLICADO',
            'categoria' => 'Motor',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['sku']);
    }

    // -------------------------------------------------------------------------
    // Entrada de estoque via API
    // -------------------------------------------------------------------------

    public function test_entrada_estoque(): void
    {
        $token   = $this->loginAdmin();
        $produto = $this->criarProduto(['qty_atual' => 10]);

        $response = $this->withToken($token)->postJson("/api/produtos/{$produto->id}/estoque/entrada", [
            'quantidade' => 5,
            'motivo'     => 'Compra de fornecedor',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['message', 'qty_atual']);

        $this->assertSame(15, $produto->fresh()->qty_atual);
        $this->assertDatabaseHas('movimentacoes_estoque', [
            'produto_id' => $produto->id,
            'tipo'       => 'ENTRADA',
            'quantidade' => 5,
        ]);
    }

    // -------------------------------------------------------------------------
    // Filtro por status
    // -------------------------------------------------------------------------

    public function test_filtro_por_status_critico(): void
    {
        $token = $this->loginAdmin();

        // Produto CRITICO: qty_atual=1 < qty_minima*0.4 = 10*0.4 = 4
        $this->criarProduto(['sku' => 'CRI-001', 'qty_atual' => 1, 'qty_minima' => 10]);

        // Produto NORMAL
        $this->criarProduto(['sku' => 'NRM-001', 'qty_atual' => 20, 'qty_minima' => 5]);

        $response = $this->withToken($token)->getJson('/api/produtos?status=CRITICO');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('meta.total'));

        $skus = collect($response->json('data'))->pluck('sku')->toArray();
        $this->assertContains('CRI-001', $skus);
        $this->assertNotContains('NRM-001', $skus);
    }

    public function test_sku_auto_gerado_quando_omitido(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/produtos', [
            'nome'      => 'Produto sem SKU',
            'categoria' => 'Motor',
        ]);

        $response->assertStatus(201);
        $sku = $response->json('data.sku');
        $this->assertNotEmpty($sku);
    }
}
