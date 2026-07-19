<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Services\AsaasService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsaasServiceCobrancaTest extends TestCase
{
    public function test_cobranca_avulsa_envia_external_reference_quando_informado(): void
    {
        config(['services.asaas.url' => 'https://sandbox.asaas.com/api/v3', 'services.asaas.api_key' => 'chave-teste']);

        Http::fake([
            '*/payments' => Http::response(['id' => 'pay_123'], 200),
        ]);

        $service = app(AsaasService::class);
        $result  = $service->criarCobrancaAvulsa('cus_abc', 199.90, '2026-08-01', 'cobranca-uuid-xyz');

        $this->assertSame('pay_123', $result['id']);
        Http::assertSent(fn($request) => $request['externalReference'] === 'cobranca-uuid-xyz');
    }

    public function test_cobranca_avulsa_sem_external_reference_nao_quebra(): void
    {
        config(['services.asaas.url' => 'https://sandbox.asaas.com/api/v3', 'services.asaas.api_key' => 'chave-teste']);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_456'], 200)]);

        $service = app(AsaasService::class);
        $result  = $service->criarCobrancaAvulsa('cus_abc', 50, '2026-08-01');

        $this->assertSame('pay_456', $result['id']);
        Http::assertSent(fn($request) => !array_key_exists('externalReference', $request->data()));
    }
}
