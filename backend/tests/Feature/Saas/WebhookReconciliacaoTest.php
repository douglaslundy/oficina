<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookReconciliacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComCobranca(string $gateway, string $paymentIdField, string $paymentId): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'INADIMPLENTE', 'gateway' => $gateway,
            'ciclo_cobranca' => 'MENSAL', 'proximo_vencimento' => '2026-08-01',
        ]);
        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => '2026-08-01', 'gateway' => $gateway,
            $paymentIdField => $paymentId,
        ]);
        return [$oficina, $cobranca];
    }

    public function test_asaas_payment_confirmed_reconcilia_por_payment_id(): void
    {
        config(['services.asaas.webhook_token' => 'token-teste']);
        [$oficina, $cobranca] = $this->criarOficinaComCobranca('ASAAS', 'asaas_payment_id', 'pay_asaas_1');

        $response = $this->withHeaders(['asaas-access-token' => 'token-teste'])
            ->postJson('/api/saas/webhooks/asaas', [
                'event'   => 'PAYMENT_CONFIRMED',
                'payment' => ['id' => 'pay_asaas_1', 'value' => 100],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cobrancas', ['id' => $cobranca->id, 'status' => 'PAGA']);
        $this->assertDatabaseHas('oficinas', ['id' => $oficina->id, 'status' => 'ATIVA', 'proximo_vencimento' => '2026-09-01']);
    }

    public function test_mp_payment_aprovado_reconcilia_por_payment_id(): void
    {
        [$oficina, $cobranca] = $this->criarOficinaComCobranca('MERCADOPAGO', 'mp_payment_id', 'pref_mp_1');

        Http::fake([
            '*/v1/payments/mp_pay_1' => Http::response(['id' => 'mp_pay_1', 'status' => 'approved', 'external_reference' => $cobranca->id], 200),
        ]);

        $response = $this->postJson('/api/saas/webhooks/mercadopago?data_id=mp_pay_1', [
            'type' => 'payment',
            'data' => ['id' => 'mp_pay_1'],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cobrancas', ['id' => $cobranca->id, 'status' => 'PAGA']);
        $this->assertDatabaseHas('oficinas', ['id' => $oficina->id, 'status' => 'ATIVA', 'proximo_vencimento' => '2026-09-01']);
    }
}
