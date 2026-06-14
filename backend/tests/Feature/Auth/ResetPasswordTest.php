<?php
declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\PasswordResetToken;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(): Usuario
    {
        return Usuario::create([
            'nome'       => 'Admin',
            'email'      => 'admin@mecanicapro.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('senha_antiga'),
        ]);
    }

    private function criarToken(Usuario $usuario, bool $expirado = false, bool $usado = false): string
    {
        $token = 'token-valido-' . uniqid();
        PasswordResetToken::create([
            'usuario_id' => $usuario->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expirado ? now()->subMinutes(5) : now()->addMinutes(30),
            'usado'      => $usado,
        ]);
        return $token;
    }

    public function test_redefinir_senha_com_token_valido(): void
    {
        $usuario = $this->criarUsuario();
        $token   = $this->criarToken($usuario);

        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NovaSenha123',
            'password_confirmation' => 'NovaSenha123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['message']);

        $usuario->refresh();
        $this->assertTrue(Hash::check('NovaSenha123', $usuario->senha_hash));

        $this->assertDatabaseHas('password_reset_tokens_custom', [
            'usuario_id' => $usuario->id,
            'usado'      => true,
        ]);
    }

    public function test_rejeitar_token_expirado(): void
    {
        $usuario = $this->criarUsuario();
        $token   = $this->criarToken($usuario, expirado: true);

        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NovaSenha123',
            'password_confirmation' => 'NovaSenha123',
        ]);

        $response->assertStatus(400);
    }

    public function test_rejeitar_token_ja_usado(): void
    {
        $usuario = $this->criarUsuario();
        $token   = $this->criarToken($usuario, usado: true);

        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NovaSenha123',
            'password_confirmation' => 'NovaSenha123',
        ]);

        $response->assertStatus(400);
    }

    public function test_rejeitar_token_invalido(): void
    {
        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => 'token-inexistente',
            'password'              => 'NovaSenha123',
            'password_confirmation' => 'NovaSenha123',
        ]);

        $response->assertStatus(400);
    }

    public function test_rejeitar_senha_curta(): void
    {
        $usuario = $this->criarUsuario();
        $token   = $this->criarToken($usuario);

        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'abc',
            'password_confirmation' => 'abc',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }
}
