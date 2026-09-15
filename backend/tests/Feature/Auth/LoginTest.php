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

    /**
     * Falha de segurança grave corrigida em 2026-09-14: era um token Bearer
     * no corpo da resposta, guardado pelo frontend em localStorage/
     * document.cookie (legível por qualquer XSS). Agora é sessão httpOnly
     * via Sanctum SPA auth — sem token nenhum na resposta, e o cliente
     * precisa vir de um domínio "stateful" (Referer/Origin reconhecido em
     * SANCTUM_STATEFUL_DOMAINS) pra sessão ser ativada
     * (EnsureFrontendRequestsAreStateful, ver bootstrap/app.php).
     * 'localhost' está na lista default do pacote mesmo sem configurar nada.
     */
    public function test_login_com_credenciais_validas(): void
    {
        $this->criarUsuario();

        $response = $this->withHeader('referer', 'http://localhost')
            ->postJson('/api/auth/login', [
                'email' => 'admin@mecanicapro.com',
                'senha' => 'admin123',
            ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['oficina_slug', 'user' => ['id', 'nome', 'email', 'role']])
                 ->assertJsonMissing(['token']);

        // Cookie httpOnly de sessão (nome vem de config/session.php) — a
        // prova de que a autenticação virou sessão de verdade, não token.
        $response->assertCookie(config('session.cookie'));

        $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
        $this->assertTrue($cookies->get(config('session.cookie'))->isHttpOnly(), 'Cookie de sessão precisa ser httpOnly.');
        $this->assertFalse($cookies->get('oficina_logado')->isHttpOnly(), 'Cookie de presença não deve ser httpOnly (só o proxy.ts do frontend lê).');
    }

    public function test_login_sem_origem_reconhecida_nao_ativa_sessao_stateful(): void
    {
        // Sem Referer/Origin, EnsureFrontendRequestsAreStateful não ativa o
        // pipeline de sessão — LoginController precisa degradar com um erro
        // claro em vez de um 500 (Session store not set on request).
        $this->criarUsuario();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@mecanicapro.com',
            'senha' => 'admin123',
        ]);

        $response->assertStatus(400);
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
            'nome' => 'Teste', 'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'slug' => 'teste-' . uniqid(),
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
