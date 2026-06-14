<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemServico;
use App\Models\OsItem;
use App\Models\Produto;
use App\Models\Usuario;
use App\Services\EstoqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EstoqueServiceTest extends TestCase
{
    use RefreshDatabase;

    private EstoqueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EstoqueService::class);
    }

    // -------------------------------------------------------------------------
    // getStatus
    // -------------------------------------------------------------------------

    public function test_status_sem_estoque(): void
    {
        $status = $this->service->getStatus(0, 10);
        $this->assertSame('SEM_ESTOQUE', $status);
    }

    public function test_status_critico(): void
    {
        // qty_minima = 10, threshold = 10 * 0.4 = 4 → qty = 3 → CRITICO
        $status = $this->service->getStatus(3, 10);
        $this->assertSame('CRITICO', $status);
    }

    public function test_status_baixo(): void
    {
        // qty_atual=6 < qty_minima=10 but 6 >= 10*0.4=4 → BAIXO
        $status = $this->service->getStatus(6, 10);
        $this->assertSame('BAIXO', $status);
    }

    public function test_status_normal(): void
    {
        $status = $this->service->getStatus(10, 10);
        $this->assertSame('NORMAL', $status);
    }

    // -------------------------------------------------------------------------
    // baixarEstoqueOs
    // -------------------------------------------------------------------------

    private function criarAdmin(): Usuario
    {
        return Usuario::create([
            'nome'       => 'Admin',
            'email'      => 'admin@test.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ]);
    }

    private function criarOs(Usuario $admin, ?array $itens = null): OrdemServico
    {
        $cliente = Cliente::create(['nome' => 'Cliente Teste', 'cpf_cnpj' => '87748248800']);

        $os = OrdemServico::create([
            'cliente_id'        => $cliente->id,
            'mecanico_id'       => $admin->id,
            'problema_relatado' => 'Teste',
            'status'            => 'ABERTA',
            'valor_total'       => 100,
            'valor_pago'        => 0,
        ]);

        foreach ($itens ?? [] as $item) {
            OsItem::create(array_merge(['os_id' => $os->id], $item));
        }

        return $os;
    }

    public function test_baixar_estoque_os(): void
    {
        $admin = $this->criarAdmin();
        Auth::login($admin);

        $produto = Produto::create([
            'nome'       => 'Filtro',
            'sku'        => 'FLT01',
            'categoria'  => 'Filtros',
            'qty_atual'  => 10,
            'qty_minima' => 3,
            'preco_venda' => 50,
        ]);

        $os = $this->criarOs($admin, [[
            'tipo'          => 'PECA',
            'produto_id'    => $produto->id,
            'descricao'     => 'Filtro de óleo',
            'quantidade'    => 2,
            'valor_unitario' => 50,
        ]]);

        $this->service->baixarEstoqueOs($os);

        $this->assertSame(8, $produto->fresh()->qty_atual);

        $this->assertDatabaseHas('movimentacoes_estoque', [
            'produto_id' => $produto->id,
            'tipo'       => 'SAIDA',
            'quantidade' => 2,
            'os_id'      => $os->id,
        ]);
    }

    public function test_baixar_estoque_insuficiente(): void
    {
        $admin = $this->criarAdmin();
        Auth::login($admin);

        $produto = Produto::create([
            'nome'       => 'Pastilha',
            'sku'        => 'PAS01',
            'categoria'  => 'Freios',
            'qty_atual'  => 1,
            'qty_minima' => 5,
            'preco_venda' => 80,
        ]);

        $os = $this->criarOs($admin, [[
            'tipo'           => 'PECA',
            'produto_id'     => $produto->id,
            'descricao'      => 'Pastilha de freio',
            'quantidade'     => 4,
            'valor_unitario' => 80,
        ]]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Estoque insuficiente/');

        $this->service->baixarEstoqueOs($os);
    }
}
