<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Oficina;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VeiculoTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(): Oficina
    {
        return Oficina::create([
            'nome'   => 'Oficina Teste',
            'slug'   => 'oficina-teste',
            'status' => 'ATIVA',
        ]);
    }

    private function loginAdmin(string $oficinaId): string
    {
        $user = Usuario::create([
            'nome'       => 'Admin',
            'email'      => 'admin@test.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $oficinaId,
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function criarCliente(string $oficinaId, string $nome, string $cpf): Cliente
    {
        return Cliente::create([
            'nome'       => $nome,
            'cpf_cnpj'   => $cpf,
            'oficina_id' => $oficinaId,
        ]);
    }

    public function test_criar_veiculo_cria_registro_de_propriedade(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');

        $response = $this->withToken($token)
            ->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", [
                'modelo' => 'Honda Civic',
                'ano'    => 2020,
                'placa'  => 'ABC1234',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('veiculo_proprietarios', [
            'veiculo_id' => $response->json('id'),
            'cliente_id' => $cliente->id,
            'data_fim'   => null,
        ]);
    }

    public function test_rejeitar_placa_duplicada_em_veiculo_ativo(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente1 = $this->criarCliente($oficina->id, 'João Silva', '11111111111');
        $cliente2 = $this->criarCliente($oficina->id, 'Maria Souza', '22222222222');

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente1->id}/veiculos", [
                'modelo' => 'Honda Civic', 'placa' => 'ABC-1234',
            ])->assertStatus(201);

        // Mesma placa, case e hífen diferentes — deve ser bloqueado.
        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente2->id}/veiculos", [
                'modelo' => 'Toyota Corolla', 'placa' => 'abc1234',
            ]);

        $response->assertStatus(422);
    }
}
