<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsuarioTest extends TestCase
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

    public function test_listar_usuarios(): void
    {
        $token = $this->loginAdmin();
        $response = $this->withToken($token)->getJson('/api/usuarios');
        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }

    public function test_criar_usuario_admin(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/usuarios', [
            'nome'  => 'Novo Mecânico',
            'email' => 'mecanico@test.com',
            'cpf'   => '87748248800',
            'role'  => 'MECANICO',
            'senha' => 'Senha123!',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['data' => ['id', 'nome', 'email', 'role']]);
    }

    public function test_rejeitar_email_duplicado(): void
    {
        $token = $this->loginAdmin();

        $this->withToken($token)->postJson('/api/usuarios', [
            'nome'  => 'Outro',
            'email' => 'admin@test.com',
            'cpf'   => '87748248800',
            'role'  => 'ATENDENTE',
            'senha' => 'Senha123!',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }
}
