<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Services\CobrancaRecorrenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuspenderVencidasTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        return Oficina::create(array_merge([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'INADIMPLENTE',
        ], $overrides));
    }

    public function test_suspende_oficina_vencida_alem_do_limite(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(1, $suspensas);
        $oficina->refresh();
        $this->assertSame('SUSPENSA', $oficina->status);
    }

    public function test_nao_suspende_antes_do_limite(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(3)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(0, $suspensas);
        $oficina->refresh();
        $this->assertSame('INADIMPLENTE', $oficina->status);
    }

    public function test_nao_suspende_com_voto_de_confianca_ativo(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina(['voto_confianca_ate' => now()->addDays(2)->toDateString()]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(15)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(0, $suspensas);
        $oficina->refresh();
        $this->assertSame('INADIMPLENTE', $oficina->status);
    }

    public function test_suspende_quando_voto_de_confianca_expirou(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina(['voto_confianca_ate' => now()->subDay()->toDateString()]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(15)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(1, $suspensas);
    }

    public function test_ignora_override_por_oficina(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 30]);
        $oficina = $this->criarOficina(['dias_suspensao_vencido' => 5]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(7)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(1, $suspensas);
    }
}
