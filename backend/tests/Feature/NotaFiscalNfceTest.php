<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotaFiscalNfceTest extends TestCase
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
            'uf'                => 'MG',
            'regime_tributario' => 'Simples Nacional',
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

    private function payloadVenda(string $clienteId, string $produtoId, array $extra = []): array
    {
        return array_merge([
            'cliente_id'        => $clienteId,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produtoId,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ], $extra);
    }

    public function test_cliente_pessoa_fisica_gera_nfce_automaticamente(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NFC-e');
    }

    public function test_cliente_pessoa_juridica_sempre_gera_nfe(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Oficina Cliente LTDA', 'cpf_cnpj' => '11222333000181', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NF-e');
    }

    public function test_forcar_nfe_com_cliente_pessoa_fisica_gera_nfe(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id, ['forcar_nfe' => true]));

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NF-e');
    }

    public function test_forcar_nfe_com_cliente_pessoa_juridica_e_ignorado(): void
    {
        $this->criarConfiguracao();
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Oficina Cliente LTDA', 'cpf_cnpj' => '11222333000181', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id, ['forcar_nfe' => true]));

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NF-e');
    }

    public function test_nfce_dentro_do_estado_usa_cfop_5102(): void
    {
        $this->criarConfiguracao(['uf' => 'MG']);
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'MG']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));

        $notaId = $response->json('data.id');
        $this->assertDatabaseHas('notas_fiscais_itens', [
            'nota_fiscal_id' => $notaId, 'cfop' => '5102', 'cst_csosn' => '102',
        ]);
    }

    public function test_nfce_fora_do_estado_usa_cfop_6108(): void
    {
        $this->criarConfiguracao(['uf' => 'MG']);
        $token   = $this->loginAdmin();
        $cliente = Cliente::create(['nome' => 'Fulano', 'cpf_cnpj' => '87748248800', 'uf' => 'SP']);
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', $this->payloadVenda($cliente->id, $produto->id));

        $notaId = $response->json('data.id');
        $this->assertDatabaseHas('notas_fiscais_itens', [
            'nota_fiscal_id' => $notaId, 'cfop' => '6108',
        ]);
    }
}
