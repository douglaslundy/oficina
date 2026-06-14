<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\OrdemServico;
use App\Models\Usuario;
use App\Services\ClienteStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClienteStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClienteStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClienteStatusService::class);
    }

    private function criarAdmin(): Usuario
    {
        return Usuario::create([
            'nome'       => 'Admin',
            'email'      => 'admin@test.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ]);
    }

    private function criarCliente(): Cliente
    {
        return Cliente::create([
            'nome'     => 'Cliente Teste',
            'cpf_cnpj' => '87748248800',
        ]);
    }

    private function criarOs(array $attrs): OrdemServico
    {
        $admin   = $this->criarAdmin();
        $cliente = Cliente::findOrFail($attrs['cliente_id']);

        return OrdemServico::create(array_merge([
            'mecanico_id'       => $admin->id,
            'problema_relatado' => 'Teste',
            'status'            => 'ABERTA',
            'valor_total'       => 200,
            'valor_pago'        => 0,
        ], $attrs));
    }

    public function test_status_devedor(): void
    {
        $cliente = $this->criarCliente();

        $this->criarOs([
            'cliente_id'  => $cliente->id,
            'status'      => 'CONCLUIDA',
            'valor_total' => 500,
            'valor_pago'  => 200, // valor_pago < valor_total → DEVEDOR
        ]);

        $resultado = $this->service->recalcular($cliente->id);

        $this->assertSame('DEVEDOR', $resultado);
        $this->assertSame('DEVEDOR', $cliente->fresh()->status);
    }

    public function test_status_os_aberta(): void
    {
        $cliente = $this->criarCliente();

        $this->criarOs([
            'cliente_id'  => $cliente->id,
            'status'      => 'ABERTA',
            'valor_total' => 300,
            'valor_pago'  => 300, // sem débito
        ]);

        $resultado = $this->service->recalcular($cliente->id);

        $this->assertSame('OS_ABERTA', $resultado);
        $this->assertSame('OS_ABERTA', $cliente->fresh()->status);
    }

    public function test_status_regular(): void
    {
        $cliente = $this->criarCliente();
        // sem ordens de serviço

        $resultado = $this->service->recalcular($cliente->id);

        $this->assertSame('REGULAR', $resultado);
        $this->assertSame('REGULAR', $cliente->fresh()->status);
    }

    public function test_recalcular_apos_pagamento(): void
    {
        $cliente = $this->criarCliente();

        $os = $this->criarOs([
            'cliente_id'  => $cliente->id,
            'status'      => 'CONCLUIDA',
            'valor_total' => 400,
            'valor_pago'  => 200,
        ]);

        // Primeiro estado: DEVEDOR
        $statusDevedor = $this->service->recalcular($cliente->id);
        $this->assertSame('DEVEDOR', $statusDevedor);

        // Quita o débito
        $os->update(['valor_pago' => 400]);

        // Agora deve voltar a REGULAR (sem OS abertas)
        $statusRegular = $this->service->recalcular($cliente->id);
        $this->assertSame('REGULAR', $statusRegular);
        $this->assertSame('REGULAR', $cliente->fresh()->status);
    }
}
