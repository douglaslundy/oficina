<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Falha de segurança grave corrigida em 2026-09-14: SaaS\AuthController::login()
 * devolvia um token Bearer no corpo, guardado pelo frontend em localStorage/
 * document.cookie — ainda mais sensível aqui (acesso a TODAS as oficinas da
 * plataforma). Agora é sessão httpOnly via guard 'session' PRÓPRIO ('saas',
 * ver config/auth.php) — isolado do guard 'web' de propósito, pra uma sessão
 * de oficina nunca satisfazer `auth:saas` (ver comentário em config/auth.php).
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdmin(): SuperAdmin
    {
        return SuperAdmin::create([
            'nome' => 'Admin SaaS', 'email' => 'saas@mecanicapro.com',
            'senha_hash' => Hash::make('admin123'),
        ]);
    }

    public function test_login_com_credenciais_validas_nao_devolve_token_e_seta_cookie_de_sessao(): void
    {
        $this->criarAdmin();

        $response = $this->withHeader('referer', 'http://localhost')
            ->postJson('/api/saas/auth/login', ['email' => 'saas@mecanicapro.com', 'senha' => 'admin123']);

        $response->assertStatus(200)
            ->assertJsonStructure(['user' => ['id', 'nome', 'email']])
            ->assertJsonMissing(['token']);

        $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
        $this->assertTrue($cookies->get(config('session.cookie'))->isHttpOnly());
        $this->assertFalse($cookies->get('saas_logado')->isHttpOnly());
    }

    public function test_sessao_de_saas_nao_autentica_rota_de_oficina(): void
    {
        // Prova direta da razão de 'saas' ter virado guard 'session' próprio
        // em vez de reusar o mecanismo stateful do Sanctum (que compartilha
        // config('sanctum.guard') entre TODOS os guards sanctum da app) —
        // ver comentário em config/auth.php.
        $admin = $this->criarAdmin();
        $this->actingAs($admin, 'saas');

        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_login_sem_origem_reconhecida_retorna_erro_claro_em_vez_de_500(): void
    {
        $this->criarAdmin();

        $this->postJson('/api/saas/auth/login', ['email' => 'saas@mecanicapro.com', 'senha' => 'admin123'])
            ->assertStatus(400);
    }

    public function test_logout_chama_auth_logout_e_invalida_a_sessao(): void
    {
        $admin = $this->criarAdmin();
        $this->actingAs($admin, 'saas');

        $this->withHeader('referer', 'http://localhost')
            ->postJson('/api/saas/auth/logout')
            ->assertStatus(200);

        $this->assertFalse(auth('saas')->check(), 'Auth::guard(saas)->logout() precisa desautenticar o admin.');
    }
}
