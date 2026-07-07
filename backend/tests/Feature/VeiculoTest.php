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

    public function test_detalhe_do_veiculo_retorna_proprietario_historico_e_os(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');

        $veiculoResp = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", [
                'modelo' => 'Honda Civic', 'ano' => 2020, 'placa' => 'ABC-1234',
            ]);
        $veiculoId = $veiculoResp->json('id');

        // OS parcialmente paga: valor_total e valor_pago propositalmente diferentes
        // para provar que o resumo soma valor_pago (recebido), não valor_total (faturado).
        \App\Models\OrdemServico::create([
            'cliente_id'  => $cliente->id,
            'veiculo_id'  => $veiculoId,
            'oficina_id'  => $oficina->id,
            'status'      => 'CONCLUIDA',
            'valor_total' => 200,
            'valor_pago'  => 150,
        ]);

        // OS cancelada: deve ser excluída do resumo e do histórico.
        \App\Models\OrdemServico::create([
            'cliente_id'  => $cliente->id,
            'veiculo_id'  => $veiculoId,
            'oficina_id'  => $oficina->id,
            'status'      => 'CANCELADA',
            'valor_total' => 500,
            'valor_pago'  => 500,
        ]);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson("/api/veiculos/{$veiculoId}");

        $response->assertStatus(200)
            ->assertJsonPath('proprietario_atual.nome', 'João Silva')
            ->assertJsonPath('resumo.total_os', 1)
            ->assertJsonPath('resumo.valor_total_gasto', 150)
            ->assertJsonCount(1, 'historico_proprietarios')
            ->assertJsonCount(1, 'historico_os');
    }

    public function test_transferir_veiculo_atualiza_proprietario_e_historico(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $clienteAntigo = $this->criarCliente($oficina->id, 'João Silva', '11111111111');
        $clienteNovo = $this->criarCliente($oficina->id, 'Maria Souza', '22222222222');

        $veiculoId = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$clienteAntigo->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/veiculos/{$veiculoId}/transferir", ['novo_cliente_id' => $clienteNovo->id]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('veiculos', ['id' => $veiculoId, 'cliente_id' => $clienteNovo->id]);
        $this->assertDatabaseHas('veiculo_proprietarios', [
            'veiculo_id' => $veiculoId, 'cliente_id' => $clienteNovo->id, 'data_fim' => null,
        ]);

        $antigo = \App\Models\VeiculoProprietario::where('veiculo_id', $veiculoId)
            ->where('cliente_id', $clienteAntigo->id)->first();
        $this->assertNotNull($antigo->data_fim);
    }

    public function test_transferir_para_o_mesmo_cliente_e_rejeitado(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');

        $veiculoId = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/veiculos/{$veiculoId}/transferir", ['novo_cliente_id' => $cliente->id]);

        $response->assertStatus(422);
    }

    public function test_mecanico_nao_pode_transferir_veiculo(): void
    {
        $oficina = $this->criarOficina();
        $adminToken = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');
        $outroCliente = $this->criarCliente($oficina->id, 'Maria Souza', '22222222222');

        $veiculoId = $this->withToken($adminToken)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');

        $mecanico = \App\Models\Usuario::create([
            'nome' => 'Mecânico', 'email' => 'mec@test.com', 'cpf' => '33333333333',
            'role' => 'MECANICO', 'status' => 'ATIVO', 'senha_hash' => \Illuminate\Support\Facades\Hash::make('123'),
            'oficina_id' => $oficina->id,
        ]);
        $mecToken = $mecanico->createToken('t')->plainTextToken;

        $response = $this->withToken($mecToken)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/veiculos/{$veiculoId}/transferir", ['novo_cliente_id' => $outroCliente->id]);

        $response->assertStatus(403);
    }
}
