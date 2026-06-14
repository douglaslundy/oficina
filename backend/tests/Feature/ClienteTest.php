<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClienteTest extends TestCase
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

    public function test_criar_cliente_com_cpf_valido(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'João Silva',
            'cpf_cnpj' => '529.982.247-25',
            'telefone' => '(11) 99999-9999',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['data' => ['id', 'nome', 'cpf_cnpj']]);
    }

    public function test_rejeitar_cliente_com_cpf_invalido(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'Inválido',
            'cpf_cnpj' => '111.111.111-11',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['cpf_cnpj']);
    }

    public function test_listar_clientes_com_paginacao(): void
    {
        $token = $this->loginAdmin();
        $response = $this->withToken($token)->getJson('/api/clientes');
        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'meta' => ['total', 'per_page']]);
    }
}
