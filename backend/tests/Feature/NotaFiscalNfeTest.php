<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
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

    private function criarCliente(): Cliente
    {
        return Cliente::create(['nome' => 'Cliente Teste', 'cpf_cnpj' => '87748248800', 'status' => 'REGULAR']);
    }

    private function criarProduto(): Produto
    {
        return Produto::create([
            'nome' => 'Filtro de Óleo', 'sku' => 'FLT-01', 'categoria' => 'Filtros',
            'qty_atual' => 10, 'qty_minima' => 2, 'preco_venda' => 45,
            'ncm' => '84212300', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
            'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);
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
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['itens']);
    }
}
