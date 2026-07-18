<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Services\CobrancaRecorrenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CobrancaRecorrenteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 199.90]);

        return Oficina::create(array_merge([
            'nome'               => 'Oficina Teste',
            'cnpj'               => '11222333000181',
            'slug'               => 'oficina-teste-' . uniqid(),
            'plano_id'           => $plano->id,
            'status'             => 'ATIVA',
            'gateway'            => 'ASAAS',
            'asaas_customer_id'  => 'cus_123',
            'ciclo_cobranca'     => 'MENSAL',
            'proximo_vencimento' => now()->addDays(3)->toDateString(),
        ], $overrides))->load('plano');
    }

    public function test_gera_cobranca_quando_dentro_da_antecedencia(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5]);
        $oficina = $this->criarOficina(); // vence em 3 dias, antecedência padrão 5 -> deve gerar

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        $geradas = app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertSame(1, $geradas);
        $this->assertDatabaseHas('cobrancas', [
            'oficina_id' => $oficina->id,
            'tipo'       => 'ASSINATURA',
            'status'     => 'PENDENTE',
            'valor'      => '199.90',
        ]);
    }

    public function test_nao_gera_cobranca_fora_da_antecedencia(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5]);
        $this->criarOficina(['proximo_vencimento' => now()->addDays(20)->toDateString()]);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        $geradas = app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertSame(0, $geradas);
        $this->assertDatabaseCount('cobrancas', 0);
    }

    public function test_nao_duplica_cobranca_ja_existente_para_o_mesmo_vencimento(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5]);
        $oficina = $this->criarOficina();

        Cobranca::create([
            'oficina_id'     => $oficina->id,
            'tipo'           => 'ASSINATURA',
            'valor'          => 199.90,
            'status'         => 'PENDENTE',
            'vencimento'     => $oficina->proximo_vencimento,
            'mes_referencia' => $oficina->proximo_vencimento->copy()->startOfMonth(),
        ]);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        $geradas = app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertSame(0, $geradas);
        $this->assertDatabaseCount('cobrancas', 1);
    }

    public function test_calcula_valor_anual_com_desconto(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5, 'desconto_anual_pct' => 10]);
        $oficina = $this->criarOficina(['ciclo_cobranca' => 'ANUAL']);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        app(CobrancaRecorrenteService::class)->gerarPendentes();

        // 199.90 * 12 * 0.9 = 2158.92
        $this->assertDatabaseHas('cobrancas', [
            'oficina_id' => $oficina->id,
            'valor'      => '2158.92',
        ]);
    }

    public function test_pula_oficina_sem_customer_id_sem_quebrar(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5]);
        $this->criarOficina(['asaas_customer_id' => null]);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        $geradas = app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertSame(0, $geradas);
    }
}
