<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CategoriaPadraoFiscal;
use App\Models\Oficina;
use App\Models\Produto;
use App\Models\ProdutoFiscalDivergencia;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests da Etapa A (campos fiscais em produtos) que faltavam desde a
 * rodada 17 — a política de conflito (PoliticaConflitoFiscalTest) só cobre a
 * DECISÃO em memória; estes testes cobrem a PERSISTÊNCIA real via HTTP +
 * Postgres: nada aqui rodou contra banco de verdade antes desta rodada.
 */
class ProdutoFiscalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Oficina, 1: string} */
    private function criarOficinaComAdmin(string $slug = 'oficina-fiscal-test', string $cpf = '52998224725'): array
    {
        $oficina = Oficina::create([
            'nome' => 'Oficina Fiscal Teste', 'slug' => $slug, 'cnpj' => "{$cpf}0001", 'status' => 'ATIVA',
        ]);
        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => "admin@{$slug}.com", 'cpf' => $cpf,
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $oficina->id,
        ]);
        $token = $admin->createToken('test')->plainTextToken;

        return [$oficina, $token];
    }

    private function itemBase(array $overrides = []): array
    {
        return array_merge([
            'nome'            => 'Filtro de Óleo XPTO',
            'categoria'       => 'Filtros',
            'unidade'         => 'Un',
            'quantidade'      => 10,
            'valor_unitario'  => 15.50,
        ], $overrides);
    }

    // ── Produto novo nasce com os dados do XML ──────────────────────────

    public function test_importacao_preenche_produto_novo_com_dados_fiscais_do_xml(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();

        $payload = ['itens' => [$this->itemBase([
            'codigo_barras'   => '7891234567890',
            'ncm'             => '84212300',
            'cest'            => '0104300',
            'origem'          => 0,
            'cst_csosn'       => '60',
            'tributacao_icms' => 'ST',
        ])]];

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(201);

        $produto = Produto::where('codigo_barras', '7891234567890')->first();
        $this->assertNotNull($produto);
        $this->assertSame('84212300', $produto->ncm);
        $this->assertSame('0104300', $produto->cest);
        $this->assertSame(0, $produto->origem);
        $this->assertSame('ST', $produto->tributacao_icms);
        $this->assertSame('XML', $produto->fiscal_fonte);
    }

    public function test_importacao_sem_dado_fiscal_no_xml_aplica_padrao_da_categoria(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();

        CategoriaPadraoFiscal::create([
            'oficina_id' => $oficina->id, 'categoria' => 'Filtros',
            'ncm' => '84212300', 'origem' => 0, 'tributacao_icms' => 'ST',
        ]);

        $payload = ['itens' => [$this->itemBase(['codigo_barras' => '7899990000001'])]];

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(201);

        $produto = Produto::where('codigo_barras', '7899990000001')->first();
        $this->assertSame('84212300', $produto->ncm);
        $this->assertSame(0, $produto->origem);
        $this->assertSame('ST', $produto->tributacao_icms);
        $this->assertSame('PADRAO', $produto->fiscal_fonte);
    }

    // ── Produto existente: preenche vazio, nunca sobrescreve valor revisado ──

    public function test_importacao_preenche_ncm_vazio_de_produto_existente(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-01', 'categoria' => 'Elétrica', 'oficina_id' => $oficina->id,
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
        ]);

        $payload = ['itens' => [[
            'produto_id' => $produto->id, 'codigo_barras' => '789000',
            'quantidade' => 7, 'valor_unitario' => 12.00,
            'ncm' => '85122000', 'origem' => 2, 'tributacao_icms' => 'NORMAL',
        ]]];

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(201);
        $produto->refresh();
        $this->assertSame('85122000', $produto->ncm);
        $this->assertSame(2, $produto->origem);
        $this->assertSame('NORMAL', $produto->tributacao_icms);
        $this->assertSame('XML', $produto->fiscal_fonte);
    }

    public function test_importacao_nao_sobrescreve_ncm_ja_revisado_e_gera_divergencia(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'Pastilha', 'sku' => 'PST-01', 'categoria' => 'Freios', 'oficina_id' => $oficina->id,
            'codigo_barras' => '789111', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
            'ncm' => '87083090', 'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);

        $payload = ['itens' => [[
            'produto_id' => $produto->id, 'codigo_barras' => '789111',
            'quantidade' => 4, 'valor_unitario' => 42.00,
            'ncm' => '84213100', // diferente do já revisado
        ]]];

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(201);
        $produto->refresh();
        // Não sobrescreveu o valor já revisado manualmente.
        $this->assertSame('87083090', $produto->ncm);
        $this->assertSame('MANUAL', $produto->fiscal_fonte);

        $this->assertDatabaseHas('produto_fiscal_divergencias', [
            'produto_id'   => $produto->id,
            'campo'        => 'ncm',
            'valor_atual'  => '87083090',
            'valor_xml'    => '84213100',
            'resolvido_em' => null,
        ]);
    }

    // ── Regressão: origem=0 é valor fiscal válido (bug reincidiu 4x) ────────

    public function test_origem_zero_do_xml_e_persistida_como_valor_valido(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'Amortecedor', 'sku' => 'AMT-01', 'categoria' => 'Suspensão', 'oficina_id' => $oficina->id,
            'codigo_barras' => '789222', 'qty_atual' => 2, 'qty_minima' => 1, 'preco_venda' => 300,
            'origem' => null,
        ]);

        $payload = ['itens' => [[
            'produto_id' => $produto->id, 'codigo_barras' => '789222',
            'quantidade' => 1, 'valor_unitario' => 200.00,
            'origem' => 0,
        ]]];

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(201);
        $produto->refresh();
        $this->assertSame(0, $produto->origem);
        $this->assertSame('XML', $produto->fiscal_fonte);
    }

    // ── Endpoint de pendências fiscais ───────────────────────────────────

    public function test_pendencias_fiscais_lista_produto_sem_ncm_e_divergencia_aberta(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();

        $semNcm = Produto::create([
            'nome' => 'Correia', 'sku' => 'COR-01', 'categoria' => 'Motor', 'oficina_id' => $oficina->id,
        ]);
        $revisado = Produto::create([
            'nome' => 'Bateria', 'sku' => 'BAT-01', 'categoria' => 'Elétrica', 'oficina_id' => $oficina->id,
            'ncm' => '85071000', 'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);
        $comDivergencia = Produto::create([
            'nome' => 'Disco de Freio', 'sku' => 'DSC-01', 'categoria' => 'Freios', 'oficina_id' => $oficina->id,
            'ncm' => '87083090', 'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);
        ProdutoFiscalDivergencia::create([
            'oficina_id' => $oficina->id, 'produto_id' => $comDivergencia->id,
            'campo' => 'ncm', 'valor_atual' => '87083090', 'valor_xml' => '87089990',
        ]);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson('/api/produtos/pendencias-fiscais');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($semNcm->id, $ids);
        $this->assertContains($comDivergencia->id, $ids);
        $this->assertNotContains($revisado->id, $ids);
        $this->assertCount(1, $response->json('divergencias'));
    }

    // ── Marcar como revisado ─────────────────────────────────────────────

    public function test_marcar_revisado_recusa_produto_sem_ncm(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();
        $produto = Produto::create(['nome' => 'X', 'sku' => 'X1', 'categoria' => 'Outros', 'oficina_id' => $oficina->id]);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/produtos/{$produto->id}/marcar-revisado");

        $response->assertStatus(422);
        $this->assertNull($produto->fresh()->fiscal_revisado_em);
    }

    public function test_marcar_revisado_confirma_produto_com_ncm(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'X', 'sku' => 'X1', 'categoria' => 'Outros', 'oficina_id' => $oficina->id,
            'ncm' => '84212300', 'fiscal_fonte' => 'PADRAO',
        ]);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/produtos/{$produto->id}/marcar-revisado");

        $response->assertStatus(200);
        $produto->refresh();
        $this->assertSame('MANUAL', $produto->fiscal_fonte);
        $this->assertNotNull($produto->fiscal_revisado_em);
    }

    // ── Resolução de divergência ─────────────────────────────────────────

    public function test_resolver_divergencia_aceitou_xml_atualiza_produto(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'X', 'sku' => 'X1', 'categoria' => 'Freios', 'oficina_id' => $oficina->id,
            'ncm' => '11111111', 'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);
        $divergencia = ProdutoFiscalDivergencia::create([
            'oficina_id' => $oficina->id, 'produto_id' => $produto->id,
            'campo' => 'ncm', 'valor_atual' => '11111111', 'valor_xml' => '22222222',
        ]);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/produtos/divergencias/{$divergencia->id}/resolver", ['resolucao' => 'ACEITOU_XML']);

        $response->assertStatus(200);
        $this->assertSame('22222222', $produto->fresh()->ncm);
        $this->assertSame('XML', $produto->fresh()->fiscal_fonte);
        $divergencia->refresh();
        $this->assertNotNull($divergencia->resolvido_em);
        $this->assertSame('ACEITOU_XML', $divergencia->resolucao);
    }

    public function test_resolver_divergencia_manteve_preserva_valor_atual(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'X', 'sku' => 'X1', 'categoria' => 'Freios', 'oficina_id' => $oficina->id,
            'ncm' => '11111111', 'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);
        $divergencia = ProdutoFiscalDivergencia::create([
            'oficina_id' => $oficina->id, 'produto_id' => $produto->id,
            'campo' => 'ncm', 'valor_atual' => '11111111', 'valor_xml' => '22222222',
        ]);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/produtos/divergencias/{$divergencia->id}/resolver", ['resolucao' => 'MANTEVE']);

        $response->assertStatus(200);
        $this->assertSame('11111111', $produto->fresh()->ncm);
        $divergencia->refresh();
        $this->assertNotNull($divergencia->resolvido_em);
        $this->assertSame('MANTEVE', $divergencia->resolucao);
    }

    // ── Padrões fiscais por categoria ────────────────────────────────────

    public function test_categorias_fiscais_update_e_index_por_tenant(): void
    {
        [$oficina, $token] = $this->criarOficinaComAdmin();

        $update = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->putJson('/api/categorias-fiscais', ['categorias' => [
                ['categoria' => 'Filtros', 'ncm' => '84212300', 'origem' => 0, 'tributacao_icms' => 'ST'],
            ]]);
        $update->assertStatus(200);

        $index = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson('/api/categorias-fiscais');
        $index->assertStatus(200);

        $filtros = collect($index->json('data'))->firstWhere('categoria', 'Filtros');
        $this->assertSame('84212300', $filtros['ncm']);
        $this->assertSame(0, $filtros['origem']);
        $this->assertSame('ST', $filtros['tributacao_icms']);

        $outros = collect($index->json('data'))->firstWhere('categoria', 'Outros');
        $this->assertNull($outros['ncm']);
    }

    public function test_categorias_fiscais_sao_isoladas_por_oficina(): void
    {
        [$oficinaA, $tokenA] = $this->criarOficinaComAdmin('oficina-fiscal-a', '52998224725');
        [$oficinaB, $tokenB] = $this->criarOficinaComAdmin('oficina-fiscal-b', '11144477735');

        $this->withToken($tokenA)->withHeaders(['X-Tenant' => $oficinaA->slug])
            ->putJson('/api/categorias-fiscais', ['categorias' => [
                ['categoria' => 'Motor', 'ncm' => '84099090', 'origem' => 0, 'tributacao_icms' => 'NORMAL'],
            ]])->assertStatus(200);

        $indexB = $this->withToken($tokenB)->withHeaders(['X-Tenant' => $oficinaB->slug])
            ->getJson('/api/categorias-fiscais');

        $motorB = collect($indexB->json('data'))->firstWhere('categoria', 'Motor');
        $this->assertNull($motorB['ncm']);
    }
}
