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

class AssinaturaAlertaServiceBloqueioTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        return Oficina::create(array_merge([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'SUSPENSA',
        ], $overrides));
    }

    public function test_bloqueio_com_fatura_vencida_e_voto_disponivel(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
            'link_pagamento' => 'https://gateway.test/pay/1',
        ]);

        $status = app(AssinaturaAlertaService::class)->statusBloqueio($oficina);

        $this->assertTrue($status['suspensa']);
        $this->assertTrue($status['voto_confianca_disponivel']);
        $this->assertSame('VENCIDA', $status['fase']);
        $this->assertSame('https://gateway.test/pay/1', $status['link_pagamento']);
    }

    public function test_bloqueio_com_voto_ja_usado(): void
    {
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
            'voto_confianca_usado_em' => now(),
        ]);

        $status = app(AssinaturaAlertaService::class)->statusBloqueio($oficina);

        $this->assertTrue($status['suspensa']);
        $this->assertFalse($status['voto_confianca_disponivel']);
    }

    public function test_bloqueio_sem_fatura_relevante(): void
    {
        $oficina = $this->criarOficina(['status' => 'ATIVA']);

        $status = app(AssinaturaAlertaService::class)->statusBloqueio($oficina);

        $this->assertFalse($status['suspensa']);
        $this->assertFalse($status['voto_confianca_disponivel']);
    }

    public function test_statusBloqueio_nao_incrementa_contador_de_exibicao(): void
    {
        SaasConfig::get()->update(['alerta_cobranca_vezes_dia' => 1]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
        ]);

        app(AssinaturaAlertaService::class)->statusBloqueio($oficina);
        app(AssinaturaAlertaService::class)->statusBloqueio($oficina);
        app(AssinaturaAlertaService::class)->statusBloqueio($oficina);

        $oficina->refresh();
        $this->assertSame(0, $oficina->alerta_cobranca_exibicoes_hoje);
    }

    public function test_bloqueio_com_oficina_ja_suspensa_usa_mensagem_de_bloqueio(): void
    {
        $oficina = $this->criarOficina(); // helper already defaults status to SUSPENSA in this file
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(30)->toDateString(),
        ]);

        $status = app(AssinaturaAlertaService::class)->statusBloqueio($oficina);

        $this->assertStringContainsString('está suspensa', $status['mensagem']);
        $this->assertStringNotContainsString('pode ser suspensa a qualquer momento', $status['mensagem']);
    }
}
