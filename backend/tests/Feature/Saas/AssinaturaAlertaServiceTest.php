<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Services\AssinaturaAlertaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssinaturaAlertaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        return Oficina::create(array_merge([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'ciclo_cobranca' => 'MENSAL',
        ], $overrides));
    }

    public function test_sem_cobranca_pendente_nao_mostra(): void
    {
        $oficina = $this->criarOficina();

        $status = app(AssinaturaAlertaService::class)->status($oficina);

        $this->assertFalse($status['show']);
    }

    public function test_cobranca_pendente_mostra_fase_disponivel(): void
    {
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
            'link_pagamento' => 'https://gateway.test/pay/1',
        ]);

        $status = app(AssinaturaAlertaService::class)->status($oficina);

        $this->assertTrue($status['show']);
        $this->assertSame('DISPONIVEL', $status['fase']);
        $this->assertStringContainsString('disponível para pagamento', $status['mensagem']);
        $this->assertSame('https://gateway.test/pay/1', $status['link_pagamento']);
    }

    public function test_cobranca_vencida_mostra_fase_vencida_com_contagem_de_suspensao(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(3)->toDateString(),
        ]);

        $status = app(AssinaturaAlertaService::class)->status($oficina);

        $this->assertTrue($status['show']);
        $this->assertSame('VENCIDA', $status['fase']);
        $this->assertStringContainsString('evite a suspensão', $status['mensagem']);
        $this->assertStringContainsString('7 dias', $status['mensagem']);
    }

    public function test_respeita_limite_de_vezes_por_dia(): void
    {
        SaasConfig::get()->update(['alerta_cobranca_vezes_dia' => 1]);
        $oficina = $this->criarOficina([
            'alerta_cobranca_exibicoes_hoje'     => 1,
            'alerta_cobranca_ultima_exibicao_em' => now()->toDateString(),
        ]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);

        $status = app(AssinaturaAlertaService::class)->status($oficina);

        $this->assertFalse($status['show']);
    }

    public function test_exibicao_incrementa_contador_do_dia(): void
    {
        SaasConfig::get()->update(['alerta_cobranca_vezes_dia' => 3]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);

        app(AssinaturaAlertaService::class)->status($oficina);

        $oficina->refresh();
        $this->assertSame(1, $oficina->alerta_cobranca_exibicoes_hoje);
        $this->assertSame(now()->toDateString(), $oficina->alerta_cobranca_ultima_exibicao_em->toDateString());
    }
}
