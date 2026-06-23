<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\Cliente;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgendamentoTest extends TestCase
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

    private function criarCliente(): Cliente
    {
        return Cliente::create([
            'nome'     => 'Cliente Teste',
            'cpf_cnpj' => '87748248800',
        ]);
    }

    public function test_criar_agendamento(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $response = $this->withToken($token)->postJson('/api/agendamentos', [
            'cliente_id'       => $cliente->id,
            'tipo_servico'     => 'Troca de óleo',
            'data_hora_inicio' => '2026-07-01 09:00:00',
            'data_hora_fim'    => '2026-07-01 10:00:00',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['data' => ['id', 'tipo_servico', 'status']]);

        $this->assertSame('AGENDADO', $response->json('data.status'));
    }

    public function test_filtrar_por_periodo(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        Agendamento::create([
            'cliente_id'       => $cliente->id,
            'tipo_servico'     => 'Revisão',
            'data_hora_inicio' => '2026-07-05 08:00:00',
            'data_hora_fim'    => '2026-07-05 09:00:00',
            'status'           => 'AGENDADO',
        ]);

        Agendamento::create([
            'cliente_id'       => $cliente->id,
            'tipo_servico'     => 'Freios',
            'data_hora_inicio' => '2026-08-10 10:00:00',
            'data_hora_fim'    => '2026-08-10 11:00:00',
            'status'           => 'AGENDADO',
        ]);

        $response = $this->withToken($token)->getJson(
            '/api/agendamentos?inicio=2026-07-01&fim=2026-07-31'
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Revisão', $response->json('data.0.tipo_servico'));
    }

    public function test_index_aceita_periodo_em_iso8601_completo(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        Agendamento::create([
            'cliente_id'       => $cliente->id,
            'tipo_servico'     => 'Revisão',
            'data_hora_inicio' => '2026-07-15 09:00:00',
            'data_hora_fim'    => '2026-07-15 10:00:00',
            'status'           => 'AGENDADO',
        ]);

        // O frontend envia o período em ISO 8601 completo (com T e Z), não date-only.
        $inicio = '2026-07-13T00:00:00.000Z';
        $fim    = '2026-07-20T00:00:00.000Z';

        $response = $this->withToken($token)->getJson("/api/agendamentos?inicio={$inicio}&fim={$fim}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Revisão', $response->json('data.0.tipo_servico'));
    }

    public function test_confirmar_agendamento_cria_os(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $agendamento = Agendamento::create([
            'cliente_id'       => $cliente->id,
            'tipo_servico'     => 'Alinhamento',
            'data_hora_inicio' => '2026-07-15 14:00:00',
            'data_hora_fim'    => '2026-07-15 15:00:00',
            'status'           => 'AGENDADO',
        ]);

        $response = $this->withToken($token)->postJson("/api/agendamentos/{$agendamento->id}/confirmar");

        $response->assertStatus(200)
                 ->assertJsonStructure(['message', 'os_id', 'os_numero']);

        $this->assertSame('CONFIRMADO', $agendamento->fresh()->status);
        $this->assertNotNull($agendamento->fresh()->os_id);
    }

    public function test_nao_pode_excluir_agendamento_confirmado(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $agendamento = Agendamento::create([
            'cliente_id'       => $cliente->id,
            'tipo_servico'     => 'Suspensão',
            'data_hora_inicio' => '2026-07-20 09:00:00',
            'data_hora_fim'    => '2026-07-20 10:00:00',
            'status'           => 'CONFIRMADO',
        ]);

        $response = $this->withToken($token)->deleteJson("/api/agendamentos/{$agendamento->id}");

        $response->assertStatus(400);
    }
}
