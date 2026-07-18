<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GerarCobrancasRecorrentesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_gera_pendentes_e_marca_vencidas(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);

        $oficinaAVencer = Oficina::create([
            'nome' => 'A Vencer', 'cnpj' => '11222333000181', 'slug' => 'a-vencer-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'ASAAS',
            'asaas_customer_id' => 'cus_1', 'proximo_vencimento' => now()->addDays(2)->toDateString(),
        ]);

        $oficinaVencida = Oficina::create([
            'nome' => 'Vencida', 'cnpj' => '11222333000271', 'slug' => 'vencida-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'ASAAS',
            'asaas_customer_id' => 'cus_2', 'proximo_vencimento' => now()->addMonth()->toDateString(),
        ]);

        Cobranca::create([
            'oficina_id' => $oficinaVencida->id, 'tipo' => 'ASSINATURA',
            'valor' => 100, 'status' => 'PENDENTE', 'vencimento' => now()->subDays(3)->toDateString(),
        ]);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_x'], 200)]);

        $this->artisan('cobrancas:gerar')->assertExitCode(0);

        $this->assertDatabaseHas('cobrancas', ['oficina_id' => $oficinaAVencer->id, 'status' => 'PENDENTE']);
        $this->assertDatabaseHas('oficinas', ['id' => $oficinaVencida->id, 'status' => 'INADIMPLENTE']);
    }
}
