<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Services\AssinaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssinaturaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mudar_ciclo_cancela_pendente_e_recalcula_vencimento(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'ciclo_cobranca' => 'MENSAL',
            'proximo_vencimento' => now()->addMonth()->toDateString(),
        ]);

        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => $oficina->proximo_vencimento,
        ]);

        app(AssinaturaService::class)->mudarCiclo($oficina, 'ANUAL');

        $this->assertDatabaseHas('cobrancas', ['id' => $cobranca->id, 'status' => 'CANCELADA']);
        $oficina->refresh();
        $this->assertSame('ANUAL', $oficina->ciclo_cobranca);
        $this->assertSame(now()->addMonths(12)->toDateString(), $oficina->proximo_vencimento->toDateString());
    }
}
