<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Oficina;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrdemServicoVeiculoTest extends TestCase
{
    use RefreshDatabase;

    private function setupEntities(): array
    {
        $oficina = Oficina::create(['nome' => 'Oficina Teste', 'slug' => 'oficina-teste', 'status' => 'ATIVA']);
        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => 'a@t.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('pass'),
            'oficina_id' => $oficina->id,
        ]);
        $cliente = Cliente::create(['nome' => 'Cliente Teste', 'cpf_cnpj' => '87748248800', 'oficina_id' => $oficina->id]);
        $token = $admin->createToken('t')->plainTextToken;
        return [$token, $oficina, $cliente];
    }

    private function withTenant(string $token, Oficina $oficina)
    {
        return $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug]);
    }

    public function test_criar_os_persiste_veiculo_id_valido(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $veiculoId = $this->withTenant($token, $oficina)
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');

        $response = $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id,
            'veiculo_id' => $veiculoId,
            'status'     => 'ABERTA',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.veiculo_id', $veiculoId);
        $this->assertDatabaseHas('ordens_servico', ['cliente_id' => $cliente->id, 'veiculo_id' => $veiculoId]);
    }

    public function test_criar_os_com_id_sintetico_de_veiculo_legado_grava_null(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $response = $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id,
            'veiculo_id' => "__proprio_{$cliente->id}",
            'status'     => 'ABERTA',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.veiculo_id', null);
    }

    public function test_listar_os_filtra_por_veiculo_id(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $veiculoId = $this->withTenant($token, $oficina)
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');
        $outroVeiculoId = $this->withTenant($token, $oficina)
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Fiat Uno', 'placa' => 'XYZ9999'])
            ->json('id');

        $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id, 'veiculo_id' => $veiculoId, 'status' => 'ABERTA',
        ])->assertStatus(201);
        $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id, 'veiculo_id' => $outroVeiculoId, 'status' => 'ABERTA',
        ])->assertStatus(201);

        $response = $this->withTenant($token, $oficina)->getJson("/api/os?veiculo_id={$veiculoId}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }
}
