<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Services\CobrancaRecorrenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CobrancaLinkPagamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_motor_recorrente_persiste_link_pagamento_asaas(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'ASAAS',
            'asaas_customer_id' => 'cus_1', 'proximo_vencimento' => now()->addDays(2)->toDateString(),
        ]);

        Http::fake(['*/payments' => Http::response([
            'id' => 'pay_x', 'invoiceUrl' => 'https://asaas.test/i/pay_x',
        ], 200)]);

        app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertDatabaseHas('cobrancas', [
            'oficina_id'     => $oficina->id,
            'link_pagamento' => 'https://asaas.test/i/pay_x',
        ]);
    }

    public function test_motor_recorrente_persiste_link_pagamento_mercadopago(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste MP', 'cnpj' => '11222333000271', 'slug' => 'teste-mp-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'MERCADOPAGO',
            'mp_customer_id' => 'cus_mp_1', 'proximo_vencimento' => now()->addDays(2)->toDateString(),
        ]);

        Http::fake(['*/checkout/preferences' => Http::response([
            'id' => 'pref_x', 'init_point' => 'https://mp.test/checkout/pref_x',
        ], 200)]);

        app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertDatabaseHas('cobrancas', [
            'oficina_id'     => $oficina->id,
            'link_pagamento' => 'https://mp.test/checkout/pref_x',
        ]);
    }
}
