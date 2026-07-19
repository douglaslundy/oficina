<?php
declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(array $overrides = []): Usuario
    {
        return Usuario::create(array_merge([
            'nome'       => 'Admin',
            'email'      => 'admin@mecanicapro.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ], $overrides));
    }

    public function test_login_com_credenciais_validas(): void
    {
        $this->criarUsuario();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@mecanicapro.com',
            'senha' => 'admin123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['token', 'user' => ['id', 'nome', 'email', 'role']]);
    }

    public function test_login_com_credenciais_invalidas(): void
    {
        $this->criarUsuario();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@mecanicapro.com',
            'senha' => 'senhaerrada',
        ]);

        $response->assertStatus(401)
                 ->assertJson(['message' => 'E-mail ou senha incorretos. Verifique e tente novamente.']);
    }

    public function test_login_usuario_inativo(): void
    {
        $this->criarUsuario(['email' => 'inativo@test.com', 'cpf' => '11111111111', 'status' => 'INATIVO']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'inativo@test.com',
            'senha' => 'admin123',
        ]);

        $response->assertStatus(403);
    }

    private function criarOficina(string $status): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        return Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => $status,
        ]);
    }

    public function test_login_com_oficina_inadimplente_e_permitido(): void
    {
        $oficina = $this->criarOficina('INADIMPLENTE');
        $this->criarUsuario(['email' => 'inadimplente@test.com', 'cpf' => '22222222222', 'oficina_id' => $oficina->id]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'inadimplente@test.com',
            'senha' => 'admin123',
        ]);

        $response->assertStatus(200);
    }

    public function test_login_com_oficina_suspensa_e_bloqueado(): void
    {
        $oficina = $this->criarOficina('SUSPENSA');
        $this->criarUsuario(['email' => 'suspensa@test.com', 'cpf' => '33333333333', 'oficina_id' => $oficina->id]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'suspensa@test.com',
            'senha' => 'admin123',
        ]);

        $response->assertStatus(403);
    }
}
