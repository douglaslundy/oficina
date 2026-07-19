<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OficinaCobrancaEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 199.90]);
        return Oficina::create(array_merge([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'MERCADOPAGO',
            'mp_customer_id' => 'cus_mp_1', 'ciclo_cobranca' => 'MENSAL',
            'proximo_vencimento' => now()->addMonth()->toDateString(),
        ], $overrides));
    }

    public function test_atualizar_oficina_aceita_overrides_de_cobranca(): void
    {
        $this->autenticarSuperAdmin();
        $oficina = $this->criarOficina();

        $response = $this->putJson("/api/saas/oficinas/{$oficina->id}", [
            'proximo_vencimento'         => '2026-09-10',
            'dias_antecedencia_cobranca' => 7,
            'dias_suspensao_vencido'     => 15,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('oficinas', [
            'id' => $oficina->id, 'proximo_vencimento' => '2026-09-10',
            'dias_antecedencia_cobranca' => 7, 'dias_suspensao_vencido' => 15,
        ]);
    }

    public function test_mudar_ciclo_para_anual_recalcula_vencimento_e_cancela_pendente(): void
    {
        $this->autenticarSuperAdmin();
        $oficina = $this->criarOficina();

        $cobrancaAntiga = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'PENDENTE', 'vencimento' => $oficina->proximo_vencimento,
        ]);

        $response = $this->postJson("/api/saas/oficinas/{$oficina->id}/mudar-ciclo", ['ciclo' => 'ANUAL']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('oficinas', ['id' => $oficina->id, 'ciclo_cobranca' => 'ANUAL']);
        $this->assertDatabaseHas('cobrancas', ['id' => $cobrancaAntiga->id, 'status' => 'CANCELADA']);

        $oficina->refresh();
        $this->assertSame(now()->addMonths(12)->toDateString(), $oficina->proximo_vencimento->toDateString());
    }

    public function test_gerar_cobranca_avulsa_usa_id_pre_gerado_como_referencia(): void
    {
        $this->autenticarSuperAdmin();
        $oficina = $this->criarOficina();

        Http::fake(['*/checkout/preferences' => Http::response(['id' => 'pref_999', 'init_point' => 'https://mp.test/x'], 200)]);

        $response = $this->postJson("/api/saas/oficinas/{$oficina->id}/gerar-cobranca", [
            'valor' => 199.90, 'vencimento' => now()->addDays(5)->toDateString(),
        ]);

        $response->assertStatus(201);
        $cobrancaId = $response->json('cobranca.id');

        Http::assertSent(fn($request) => $request['external_reference'] === $cobrancaId);
        $this->assertDatabaseHas('cobrancas', ['id' => $cobrancaId, 'mp_payment_id' => 'pref_999', 'tipo' => 'AVULSA']);
    }
}
