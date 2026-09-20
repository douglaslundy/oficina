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

    public function test_exportar_fiscal_json_traz_todos_os_ativos_com_os_sem_fiscal_por_ultimo(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto(['sku' => 'A-1', 'nome' => 'Alfa sem fiscal']);
        $this->criarProduto(['sku' => 'B-1', 'nome' => 'Beta completo', 'ncm' => '65061000', 'origem' => 0]);
        $this->criarProduto(['sku' => 'C-1', 'nome' => 'Inativo', 'ativo' => false]);

        $r = $this->withToken($token)->get('/api/produtos/exportar-fiscal?formato=json');

        $r->assertStatus(200);
        $this->assertStringContainsString('application/json', (string) $r->headers->get('Content-Type'));
        $this->assertStringContainsString('produtos-dados-fiscais-', (string) $r->headers->get('Content-Disposition'));
        $dados = json_decode($r->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $dados['total'], 'só produtos ativos');
        $this->assertSame(['Beta completo', 'Alfa sem fiscal'], array_column($dados['produtos'], 'nome'));
        $this->assertSame(0, $dados['produtos'][0]['origem']);
    }

    public function test_exportar_fiscal_respeita_o_filtro_de_categoria(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto(['sku' => 'A-1', 'nome' => 'Filtro X', 'categoria' => 'Filtros']);
        $this->criarProduto(['sku' => 'B-1', 'nome' => 'Freio Y', 'categoria' => 'Freios']);

        $r = $this->withToken($token)->get('/api/produtos/exportar-fiscal?formato=json&categoria=Freios');

        $dados = json_decode($r->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['Freio Y'], array_column($dados['produtos'], 'nome'));
    }

    public function test_exportar_fiscal_aceita_os_quatro_formatos(): void
    {
        $token = $this->loginAdmin();
        $this->criarProduto();

        $tipos = ['xml' => 'application/xml', 'pdf' => 'application/pdf', 'json' => 'application/json'];
        foreach ($tipos as $formato => $tipo) {
            $r = $this->withToken($token)->get("/api/produtos/exportar-fiscal?formato={$formato}");
            $r->assertStatus(200);
            $this->assertStringContainsString($tipo, (string) $r->headers->get('Content-Type'), $formato);
        }

        $xlsx = $this->withToken($token)->get('/api/produtos/exportar-fiscal?formato=xlsx');
        $xlsx->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('Content-Type'));
    }

    public function test_exportar_fiscal_rejeita_formato_invalido(): void
    {
        $token = $this->loginAdmin();

        $this->withToken($token)->getJson('/api/produtos/exportar-fiscal?formato=csv')->assertStatus(422);
        $this->withToken($token)->getJson('/api/produtos/exportar-fiscal')->assertStatus(422);
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
