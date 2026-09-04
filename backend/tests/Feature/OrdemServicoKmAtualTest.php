<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Oficina;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrdemServicoKmAtualTest extends TestCase
{
    use RefreshDatabase;

    private function setupEntities(): array
    {
        $oficina = Oficina::create(['nome' => 'Oficina Teste', 'slug' => 'oficina-teste', 'cnpj' => uniqid('c'), 'status' => 'ATIVA']);
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

    public function test_criar_os_sem_km_atual_retorna_422(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $response = $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id,
            'status'     => 'ABERTA',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('km_atual');
    }

    public function test_criar_os_com_km_atual_persiste_e_atualiza_km_ultimo_do_veiculo(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $veiculoId = $this->withTenant($token, $oficina)
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');

        $response = $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id,
            'veiculo_id' => $veiculoId,
            'status'     => 'ABERTA',
            'km_atual'   => 84500,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.km_atual', 84500);
        $this->assertDatabaseHas('ordens_servico', ['cliente_id' => $cliente->id, 'km_atual' => 84500]);
        $this->assertDatabaseHas('veiculos', ['id' => $veiculoId, 'km_ultimo' => 84500]);
    }

    public function test_os_sem_km_pode_receber_km_via_update(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        // Rascunho de OS sem KM (equivale ao gerado por um agendamento).
        $os = \App\Models\OrdemServico::create([
            'cliente_id' => $cliente->id,
            'status'     => 'ABERTA',
            'oficina_id' => $oficina->id,
        ]);

        $this->withTenant($token, $oficina)
            ->putJson("/api/os/{$os->id}", ['km_atual' => 72000])
            ->assertOk()
            ->assertJsonPath('data.km_atual', 72000);
    }

    public function test_update_nao_reescreve_km_ja_registrado(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $os = \App\Models\OrdemServico::create([
            'cliente_id' => $cliente->id,
            'status'     => 'ABERTA',
            'oficina_id' => $oficina->id,
            'km_atual'   => 60000,
        ]);

        $this->withTenant($token, $oficina)
            ->putJson("/api/os/{$os->id}", ['km_atual' => 10])
            ->assertOk()
            ->assertJsonPath('data.km_atual', 60000);
    }

    public function test_venda_balcao_nao_exige_km_atual(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $response = $this->withTenant($token, $oficina)->postJson('/api/os', [
            'tipo'       => 'VENDA_BALCAO',
            'cliente_id' => $cliente->id,
            'itens'      => [[
                'tipo' => 'SERVICO', 'descricao' => 'Mão de obra', 'quantidade' => 1, 'valor_unitario' => 100,
            ]],
        ]);

        $response->assertStatus(201);
    }
}
