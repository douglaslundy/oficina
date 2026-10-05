<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaEntrada;
use App\Models\NotaEntradaItem;
use App\Models\Oficina;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Devolução de compra: rascunho da NF-e finNFe=4 + baixa de estoque, com o
 * teto de quantidade vindo da nota de compra.
 */
class DevolucaoCompraTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '31260111222333000181550010000000011000000015';

    private string $token;
    private NotaEntrada $nota;
    private NotaEntradaItem $item;
    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $oficina = Oficina::create([
            'nome' => 'Oficina Teste', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
        ]);
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $oficina->id,
        ]);
        \App\Tenancy\TenancyContext::set($oficina->id, $oficina->slug);
        $this->token = $user->createToken('test')->plainTextToken;

        Configuracao::create(['uf' => 'MG', 'regime_tributario' => 'Simples Nacional', 'cnpj' => '11222333000181', 'serie_nf' => '001']);
        Cliente::create(['nome' => 'Fornecedor LTDA', 'cpf_cnpj' => '12345678000199', 'uf' => 'MG', 'inscricao_estadual' => '1234567890']);

        $this->produto = Produto::create([
            'nome' => 'Filtro de óleo', 'sku' => 'FLT-001', 'categoria' => 'Filtros', 'unidade' => 'Un',
            'qty_atual' => 10, 'qty_minima' => 2, 'preco_custo' => 15.5, 'preco_venda' => 30,
            'ncm' => '84212300', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
        ]);
        $this->nota = NotaEntrada::create([
            'numero_nf' => '1234', 'chave_acesso' => self::CHAVE,
            'fornecedor_nome' => 'Fornecedor LTDA', 'fornecedor_cnpj' => '12.345.678/0001-99',
            'valor_total' => 155,
        ]);
        $this->item = NotaEntradaItem::create([
            'nota_entrada_id' => $this->nota->id, 'produto_id' => $this->produto->id,
            'descricao_xml' => 'FILTRO', 'quantidade' => 10, 'valor_unitario' => 15.5,
        ]);
    }

    protected function tearDown(): void
    {
        \App\Tenancy\TenancyContext::clear();
        parent::tearDown();
    }

    private function postDevolucao(float $qtd)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token, 'X-Tenant' => 'oficina-teste'])
            ->postJson("/api/entradas-nf/{$this->nota->id}/devolucao", [
                'itens' => [['item_id' => $this->item->id, 'quantidade' => $qtd]],
            ]);
    }

    public function test_devolucao_parcial_cria_rascunho_com_cfop_chave_e_valor_da_nota_de_compra(): void
    {
        $r = $this->postDevolucao(4);

        $r->assertStatus(201)
          ->assertJsonPath('data.finalidade', 'DEVOLUCAO')
          ->assertJsonPath('data.chave_referenciada', self::CHAVE)
          ->assertJsonPath('data.status', 'RASCUNHO')
          ->assertJsonPath('data.itens.0.cfop', '5202')
          ->assertJsonPath('data.itens.0.cst_csosn', '102');
        $this->assertEquals(62.0, (float) $r->json('data.valor_total')); // 4 x 15,50
    }

    public function test_nao_devolve_mais_que_a_nota_permite_somando_devolucoes_anteriores(): void
    {
        $this->postDevolucao(7)->assertStatus(201);

        $this->postDevolucao(4)->assertStatus(422); // saldo = 3
        $this->postDevolucao(3)->assertStatus(201);
        $this->postDevolucao(1)->assertStatus(422); // esgotado
    }

    public function test_devolucao_cancelada_libera_a_quantidade(): void
    {
        $id = $this->postDevolucao(10)->json('data.id');
        $this->postDevolucao(1)->assertStatus(422);

        \App\Models\NotaFiscal::where('id', $id)->update(['status' => 'REJEITADA']);

        $this->postDevolucao(10)->assertStatus(201);
    }

    public function test_baixa_de_estoque_respeita_saldo_da_nota_e_estoque_atual(): void
    {
        $h = ['Authorization' => 'Bearer ' . $this->token, 'X-Tenant' => 'oficina-teste'];
        $url = "/api/entradas-nf/{$this->nota->id}/devolucao-estoque";

        $this->withHeaders($h)->postJson($url, ['itens' => [['item_id' => $this->item->id, 'quantidade' => 4]]])->assertOk();
        $this->assertSame(6, $this->produto->fresh()->qty_atual);
        $this->assertSame(4, $this->item->fresh()->qtd_devolvida_estoque);

        // saldo da nota = 6, mas pedir 7 passa do teto
        $this->withHeaders($h)->postJson($url, ['itens' => [['item_id' => $this->item->id, 'quantidade' => 7]]])->assertStatus(422);
        $this->assertSame(6, $this->produto->fresh()->qty_atual);

        // estoque atual menor que o saldo da nota (vendeu peças): bloqueia sem ficar negativo
        $this->produto->update(['qty_atual' => 2]);
        $this->withHeaders($h)->postJson($url, ['itens' => [['item_id' => $this->item->id, 'quantidade' => 3]]])->assertStatus(422);
        $this->assertSame(2, $this->produto->fresh()->qty_atual);
    }
}
