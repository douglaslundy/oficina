<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Services\CobrancaRecorrenteService;
use App\Services\PagamentoReconciliacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FaturaUnicaExcedentesTest extends TestCase
{
    use RefreshDatabase;

    private function oficina(): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 299]);

        return Oficina::create([
            'nome' => 'Oficina', 'cnpj' => (string) mt_rand(10000000000000, 99999999999999),
            'slug' => 'of-' . uniqid(), 'plano_id' => $plano->id, 'status' => 'ATIVA',
            'gateway' => 'ASAAS', 'asaas_customer_id' => 'cus_1', 'ciclo_cobranca' => 'MENSAL',
            'proximo_vencimento' => now()->addDays(3)->toDateString(),
        ])->load('plano');
    }

    private function excedente(Oficina $o, float $valor = 4.5): Cobranca
    {
        return Cobranca::create([
            'oficina_id' => $o->id, 'tipo' => 'NOTA_EXCEDENTE', 'valor' => $valor,
            'status' => 'PENDENTE', 'vencimento' => now()->endOfMonth()->toDateString(),
        ]);
    }

    private function gerar(): Cobranca
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5]);
        Http::fake(['*/payments' => Http::response(['id' => 'pay_1', 'invoiceUrl' => 'https://x/1'], 200)]);
        app(CobrancaRecorrenteService::class)->gerarPendentes();

        return Cobranca::where('tipo', 'ASSINATURA')->firstOrFail();
    }

    public function test_mensalidade_soma_excedentes_pendentes_e_os_vincula(): void
    {
        $o = $this->oficina();
        $a = $this->excedente($o);
        $b = $this->excedente($o);

        $fatura = $this->gerar();

        $this->assertEquals(308.0, (float) $fatura->valor);
        $this->assertStringContainsString('+ 2 excedentes', $fatura->descricao);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/payments') && (float) $r['value'] === 308.0);
        foreach ([$a, $b] as $e) {
            $e->refresh();
            $this->assertSame($fatura->id, $e->fatura_id);
            $this->assertSame('FATURADA', $e->status);
        }
    }

    public function test_excedente_gerado_depois_fica_para_a_proxima_fatura(): void
    {
        $o = $this->oficina();
        $fatura = $this->gerar();
        $depois = $this->excedente($o);

        $this->assertEquals(299.0, (float) $fatura->valor);
        $this->assertNull($depois->refresh()->fatura_id);
        $this->assertSame(1, Cobranca::excedentesAFaturar($o->id)->count());
    }

    public function test_pagar_fatura_quita_os_itens(): void
    {
        $o = $this->oficina();
        $e = $this->excedente($o);
        $fatura = $this->gerar();

        app(PagamentoReconciliacaoService::class)->confirmarPagamento($fatura);

        $this->assertSame('PAGA', $e->refresh()->status);
    }

    public function test_apagar_fatura_devolve_itens_para_a_faturar(): void
    {
        $o = $this->oficina();
        $e = $this->excedente($o);
        $fatura = $this->gerar();

        $fatura->delete();

        $e->refresh();
        $this->assertNull($e->fatura_id);
        $this->assertSame('PENDENTE', $e->status);
    }

    public function test_agrupar_em_fatura_ja_aberta_recria_cobranca_com_valor_total(): void
    {
        $o = $this->oficina();
        $fatura = Cobranca::create([
            'oficina_id' => $o->id, 'tipo' => 'ASSINATURA', 'valor' => 299, 'status' => 'PENDENTE',
            'descricao' => 'Mensalidade Dez/2026', 'gateway' => 'ASAAS', 'asaas_payment_id' => 'pay_1',
            'vencimento' => $o->proximo_vencimento->toDateString(),
        ]);
        $this->excedente($o);
        $this->excedente($o);

        Http::fake([
            '*/payments/pay_1/cancel' => Http::response(['deleted' => true], 200),
            '*/payments/pay_1'        => Http::response(['deleted' => true], 200),
            '*/payments'              => Http::response(['id' => 'pay_2', 'invoiceUrl' => 'https://x/2'], 200),
        ]);
        $qtd = app(CobrancaRecorrenteService::class)->agruparExcedentesNaFatura($fatura);

        $fatura->refresh();
        $this->assertSame(2, $qtd);
        $this->assertEquals(308.0, (float) $fatura->valor);
        $this->assertSame('pay_2', $fatura->asaas_payment_id);
        $this->assertSame('https://x/2', $fatura->link_pagamento);
        $this->assertSame(0, Cobranca::excedentesAFaturar($o->id)->count());
    }
}
