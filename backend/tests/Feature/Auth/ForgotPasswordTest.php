<?php
declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\PasswordResetToken;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
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
            'senha_hash' => Hash::make('admin123'),
        ]);
    }

    public function test_retorna_200_mesmo_com_email_invalido(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'naoexiste@mecanicapro.com',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['message']);
    }

    public function test_cria_token_quando_email_existe(): void
    {
        Queue::fake();
        $usuario = $this->criarUsuario();

        $this->postJson('/api/auth/forgot-password', [
            'email' => $usuario->email,
        ]);

        $this->assertDatabaseHas('password_reset_tokens_custom', [
            'usuario_id' => $usuario->id,
            'usado'      => false,
        ]);
    }

    public function test_nao_cria_token_quando_email_nao_existe(): void
    {
        Queue::fake();

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'fantasma@mecanicapro.com',
        ]);

        $this->assertDatabaseCount('password_reset_tokens_custom', 0);
    }

    public function test_valida_campo_email_obrigatorio(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', []);
        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }
}
