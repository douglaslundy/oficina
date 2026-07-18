<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoServiceCobrancaTest extends TestCase
{
    public function test_cobranca_avulsa_usa_external_reference_informado(): void
    {
        Http::fake([
            '*/checkout/preferences' => Http::response(['id' => 'pref_123', 'init_point' => 'https://mp.test/checkout/pref_123'], 200),
        ]);

        $service = app(MercadoPagoService::class);
        $result  = $service->criarCobrancaAvulsa('cus_mp_1', 149.90, '2026-08-01', 'cobranca-uuid-xyz');

        $this->assertSame('pref_123', $result['id']);
        $this->assertSame('https://mp.test/checkout/pref_123', $result['init_point']);
        Http::assertSent(fn($request) => $request['external_reference'] === 'cobranca-uuid-xyz');
    }

    public function test_cobranca_avulsa_sem_referencia_usa_customer_id(): void
    {
        Http::fake(['*/checkout/preferences' => Http::response(['id' => 'pref_456'], 200)]);

        $service = app(MercadoPagoService::class);
        $service->criarCobrancaAvulsa('cus_mp_2', 50, '2026-08-01');

        Http::assertSent(fn($request) => $request['external_reference'] === 'cus_mp_2');
    }
}
