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

    public function test_busca_parcial_ignora_acento_caixa_e_ordem_das_palavras(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto(); // "Filtro de Óleo"
        $this->criarProduto(['sku' => 'FLT-002', 'nome' => 'Filtro de Ar']);
        $this->criarProduto(['sku' => 'PST-001', 'nome' => 'Pastilha de Freio']);

        $nomes = fn (string $busca) => collect(
            $this->withToken($token)->getJson('/api/produtos?search=' . urlencode($busca))->json('data')
        )->pluck('nome')->all();

        $this->assertSame(['Filtro de Óleo'], $nomes('oleo'));          // sem acento
        $this->assertSame(['Filtro de Óleo'], $nomes('ÓLEO'));           // caixa + acento
        $this->assertSame(['Filtro de Óleo'], $nomes('oleo filtro'));    // outra ordem
        $this->assertSame(['Filtro de Ar', 'Filtro de Óleo'], $nomes('filt')); // parcial
        $this->assertSame(['Pastilha de Freio'], $nomes('past fre'));    // várias palavras parciais
        $this->assertSame([], $nomes('inexistente'));
    }

    public function test_busca_trata_percentual_e_underline_como_texto_literal(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto(['sku' => 'A-1', 'nome' => 'Aditivo 100%']);
        $this->criarProduto(['sku' => 'A-2', 'nome' => 'Aditivo 1000']);

        $r = $this->withToken($token)->getJson('/api/produtos?search=' . urlencode('100%'));

        $this->assertSame(['Aditivo 100%'], collect($r->json('data'))->pluck('nome')->all());
    }

    public function test_codigo_exato_acha_por_sku_ou_codigo_de_barras_sem_diferenciar_caixa(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto(['sku' => 'FLT-001', 'codigo_barras' => '7891234567890']);
        $this->criarProduto(['sku' => 'FLT-002', 'nome' => 'Filtro de Ar']);

        $porSku = $this->withToken($token)->getJson('/api/produtos?codigo=flt-001');
        $porBarras = $this->withToken($token)->getJson('/api/produtos?codigo=7891234567890');

        $this->assertSame(['FLT-001'], collect($porSku->json('data'))->pluck('sku')->all());
        $this->assertSame(['FLT-001'], collect($porBarras->json('data'))->pluck('sku')->all());
    }

    public function test_codigo_exato_nao_acha_correspondencia_parcial(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto(['sku' => 'FLT-001']);

        $r = $this->withToken($token)->getJson('/api/produtos?codigo=FLT-00');

        $this->assertSame([], $r->json('data'));
    }

    public function test_codigo_exato_devolve_todos_quando_sku_de_um_e_barras_de_outro(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto(['sku' => 'ABC123', 'nome' => 'Produto A']);
        $this->criarProduto(['sku' => 'XYZ', 'nome' => 'Produto B', 'codigo_barras' => 'ABC123']);

        $r = $this->withToken($token)->getJson('/api/produtos?codigo=ABC123');

        // O frontend usa essa contagem (>1) pra recusar a escolha automática.
        $this->assertSame(['Produto A', 'Produto B'], collect($r->json('data'))->pluck('nome')->all());
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
