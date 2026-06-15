<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $role): string
    {
        static $cpfCounter = 10000000000;
        $cpf = (string) ++$cpfCounter;

        $user = Usuario::create([
            'nome'       => "Usuário {$role}",
            'email'      => strtolower($role) . '@test.com',
            'cpf'        => $cpf,
            'role'       => $role,
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('senha123'),
        ]);

        return $user->createToken('test')->plainTextToken;
    }

    public function test_mecanico_nao_pode_criar_cliente(): void
    {
        $token = $this->loginAs('MECANICO');

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'Bloqueado',
            'cpf_cnpj' => '87748248800',
        ]);

        $response->assertStatus(403);
    }

    public function test_atendente_pode_criar_cliente(): void
    {
        $token = $this->loginAs('ATENDENTE');

        $response = $this->withToken($token)->postJson('/api/clientes', [
            'nome'     => 'Cliente Atendente',
            'cpf_cnpj' => '87748248800',
        ]);

        $response->assertStatus(201);
    }

    public function test_financeiro_nao_pode_criar_usuario(): void
    {
        $token = $this->loginAs('FINANCEIRO');

        $response = $this->withToken($token)->postJson('/api/usuarios', [
            'nome'             => 'Bloqueado',
            'email'            => 'bloqueado@test.com',
            'cpf'              => '52998224725',
            'role'             => 'ATENDENTE',
            'status'           => 'ATIVO',
            'senha'            => 'Senha123',
            'senha_confirmacao' => 'Senha123',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_pode_criar_usuario(): void
    {
        $token = $this->loginAs('ADMIN');

        $response = $this->withToken($token)->postJson('/api/usuarios', [
            'nome'             => 'Novo Usuário',
            'email'            => 'novo@test.com',
            'cpf'              => '52998224725',
            'role'             => 'ATENDENTE',
            'status'           => 'ATIVO',
            'senha'            => 'Senha123',
            'senha_confirmacao' => 'Senha123',
        ]);

        $response->assertStatus(201);
    }

    public function test_mecanico_nao_pode_acessar_relatorios(): void
    {
        $token = $this->loginAs('MECANICO');

        $response = $this->withToken($token)->getJson('/api/relatorios/os');

        $response->assertStatus(403);
    }

    public function test_financeiro_pode_acessar_relatorios(): void
    {
        $token = $this->loginAs('FINANCEIRO');

        $response = $this->withToken($token)->getJson('/api/relatorios/os');

        $response->assertStatus(200);
    }

    public function test_unauthenticated_nao_pode_acessar_api(): void
    {
        $response = $this->getJson('/api/clientes');

        $response->assertStatus(401);
    }
}
