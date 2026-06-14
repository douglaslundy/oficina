<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotaFiscalTest extends TestCase
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

    private function criarCliente(): Cliente
    {
        return Cliente::create([
            'nome'     => 'Cliente Teste',
            'cpf_cnpj' => '87748248800',
            'status'   => 'REGULAR',
        ]);
    }

    public function test_criar_nota_fiscal_rascunho(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Prestação de Serviços',
            'subtotal'          => 500.00,
            'desconto'          => 0,
            'aliquota_iss'      => 5.00,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'RASCUNHO');
    }

    public function test_listar_notas_fiscais(): void
    {
        $token = $this->loginAdmin();
        $response = $this->withToken($token)->getJson('/api/notas-fiscais');
        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'meta']);
    }

    public function test_cancelar_nota_fiscal(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $nf = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Prestação de Serviços',
            'subtotal'          => 300.00,
        ])->json('data');

        NotaFiscal::find($nf['id'])->update(['status' => 'AUTORIZADA']);

        $response = $this->withToken($token)->postJson("/api/notas-fiscais/{$nf['id']}/cancelar", [
            'motivo' => 'Cancelamento de teste para verificação do sistema',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('notas_fiscais', ['id' => $nf['id'], 'status' => 'CANCELADA']);
    }

    public function test_rejeitar_cancelamento_sem_motivo(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $nf = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Prestação de Serviços',
            'subtotal'          => 300.00,
        ])->json('data');

        $response = $this->withToken($token)->postJson("/api/notas-fiscais/{$nf['id']}/cancelar", [
            'motivo' => 'curto',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['motivo']);
    }
}
