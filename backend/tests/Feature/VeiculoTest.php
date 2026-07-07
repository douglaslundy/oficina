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

    private function criarOficina(?string $slug = null, ?string $nome = null): Oficina
    {
        $slug ??= 'oficina-teste';

        return Oficina::create([
            'nome'   => $nome ?? 'Oficina Teste',
            'slug'   => $slug,
            'status' => 'ATIVA',
        ]);
    }

    private function loginAdmin(string $oficinaId, ?string $email = null, ?string $cpf = null): string
    {
        $user = Usuario::create([
            'nome'       => 'Admin',
            'email'      => $email ?? 'admin@test.com',
            'cpf'        => $cpf ?? '52998224725',
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

    public function test_placa_duplicada_permitida_em_oficinas_diferentes(): void
    {
        $oficina1 = $this->criarOficina();
        $token1 = $this->loginAdmin($oficina1->id);
        $cliente1 = $this->criarCliente($oficina1->id, 'João Silva', '11111111111');

        $this->withToken($token1)->withHeaders(['X-Tenant' => $oficina1->slug])
            ->postJson("/api/clientes/{$cliente1->id}/veiculos", [
                'modelo' => 'Honda Civic', 'placa' => 'ABC1234',
            ])->assertStatus(201);

        $oficina2 = $this->criarOficina('oficina-teste-2', 'Oficina Teste 2');
        $token2 = $this->loginAdmin($oficina2->id, 'admin2@test.com', '22233344409');
        $cliente2 = $this->criarCliente($oficina2->id, 'Maria Souza', '33333333333');

        $response = $this->withToken($token2)->withHeaders(['X-Tenant' => $oficina2->slug])
            ->postJson("/api/clientes/{$cliente2->id}/veiculos", [
                'modelo' => 'Toyota Corolla', 'placa' => 'ABC1234',
            ]);

        $response->assertStatus(201);
    }

    public function test_busca_por_placa_parcial_normaliza_case_e_hifen(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", [
                'modelo' => 'Honda Civic', 'placa' => 'ABC-1234',
            ])->assertStatus(201);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson('/api/veiculos/busca?placa=abc123');

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame('João Silva', $response->json('0.cliente_nome'));
    }

    public function test_busca_sem_correspondencia_retorna_vazio(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson('/api/veiculos/busca?placa=zzz9999');

        $response->assertStatus(200)->assertJsonCount(0);
    }
}
