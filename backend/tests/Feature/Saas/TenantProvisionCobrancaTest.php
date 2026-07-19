<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Plano;
use App\Services\TenantProvisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantProvisionCobrancaTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisionar_define_ciclo_e_vencimento_sem_criar_subscription(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 199.90]);

        Http::fake([
            '*/customers' => Http::response(['id' => 'cus_novo'], 200),
        ]);

        $oficina = app(TenantProvisionService::class)->provisionar([
            'nome' => 'Nova Oficina', 'cnpj' => '11222333000181', 'slug' => 'nova-oficina-' . uniqid(),
            'plano_id' => $plano->id, 'admin_nome' => 'Admin', 'admin_email' => 'admin@nova.com',
            'admin_cpf' => '52998224725',
        ]);

        $this->assertSame('MENSAL', $oficina->ciclo_cobranca);
        $this->assertSame(now()->addMonth()->toDateString(), $oficina->proximo_vencimento->toDateString());
        Http::assertNotSent(fn($request) => str_contains($request->url(), 'subscriptions') || str_contains($request->url(), 'preapproval'));
    }
}
