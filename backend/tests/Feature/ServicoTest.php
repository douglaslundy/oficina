<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Servico;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ServicoTest extends TestCase
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

    private function loginAtendente(): string
    {
        $user = Usuario::create([
            'nome'       => 'Atendente',
            'email'      => 'atend@test.com',
            'cpf'        => '11144477735',
            'role'       => 'ATENDENTE',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('atend123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function criarServico(array $overrides = []): Servico
    {
        return Servico::create(array_merge([
            'nome'         => 'Troca de Óleo',
            'valor_padrao' => 80.00,
            'ativo'        => true,
        ], $overrides));
    }

    public function test_listar_servicos(): void
    {
        $token = $this->loginAdmin();
        $this->criarServico();
        $this->criarServico(['nome' => 'Alinhamento', 'valor_padrao' => 120.00]);

        $response = $this->withToken($token)->getJson('/api/servicos');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'meta' => ['total']]);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_listar_apenas_ativos(): void
    {
        $token = $this->loginAdmin();
        $this->criarServico();
        $this->criarServico(['nome' => 'Inativo', 'ativo' => false]);

        $response = $this->withToken($token)->getJson('/api/servicos?ativo=1');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_criar_servico(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/servicos', [
            'nome'         => 'Revisão Completa',
            'valor_padrao' => 350.00,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.nome', 'Revisão Completa')
                 ->assertJsonPath('data.valor_padrao', 350.0);

        $this->assertDatabaseHas('servicos', ['nome' => 'Revisão Completa']);
    }

    public function test_atendente_pode_criar_servico(): void
    {
        $token = $this->loginAtendente();

        $response = $this->withToken($token)->postJson('/api/servicos', [
            'nome'         => 'Balanceamento',
            'valor_padrao' => 60.00,
        ]);

        $response->assertStatus(201);
    }

    public function test_criar_servico_sem_nome_falha(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/servicos', [
            'valor_padrao' => 50.00,
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['nome']);
    }

    public function test_editar_servico(): void
    {
        $token   = $this->loginAdmin();
        $servico = $this->criarServico();

        $response = $this->withToken($token)->putJson("/api/servicos/{$servico->id}", [
            'nome'         => 'Troca de Óleo Premium',
            'valor_padrao' => 120.00,
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.nome', 'Troca de Óleo Premium');

        $this->assertDatabaseHas('servicos', ['id' => $servico->id, 'nome' => 'Troca de Óleo Premium']);
    }

    public function test_desativar_servico(): void
    {
        $token   = $this->loginAdmin();
        $servico = $this->criarServico();

        $response = $this->withToken($token)->deleteJson("/api/servicos/{$servico->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('servicos', ['id' => $servico->id, 'ativo' => false]);
    }

    public function test_reativar_servico_via_put(): void
    {
        $token   = $this->loginAdmin();
        $servico = $this->criarServico(['ativo' => false]);

        $response = $this->withToken($token)->putJson("/api/servicos/{$servico->id}", [
            'ativo' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('servicos', ['id' => $servico->id, 'ativo' => true]);
    }

    public function test_mecanico_nao_pode_criar_servico(): void
    {
        $user = Usuario::create([
            'nome'       => 'Mec',
            'email'      => 'mec@test.com',
            'cpf'        => '33344455568',
            'role'       => 'MECANICO',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('mec123'),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/servicos', [
            'nome'         => 'Qualquer',
            'valor_padrao' => 10.00,
        ]);

        $response->assertStatus(403);
    }

    public function test_atendente_nao_pode_desativar_servico(): void
    {
        $token   = $this->loginAtendente();
        $servico = $this->criarServico();

        $response = $this->withToken($token)->deleteJson("/api/servicos/{$servico->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('servicos', ['id' => $servico->id, 'ativo' => true]);
    }
}
