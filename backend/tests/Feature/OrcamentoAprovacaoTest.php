<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Oficina;
use App\Models\Orcamento;
use App\Models\OrdemServico;
use App\Models\OsItem;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrcamentoAprovacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $oficina = Oficina::create(['nome' => 'Oficina Teste', 'cnpj' => '12.345.678/0001-90', 'slug' => 'oficina-teste']);
        TenancyContext::set($oficina->id, $oficina->slug);
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        parent::tearDown();
    }

    /**
     * Cria OS com 1 serviço (R$100) e 1 peça (R$50) + orçamento pendente.
     * @return array{token:string, os:OrdemServico, servico:OsItem, peca:OsItem}
     */
    private function cenario(): array
    {
        $cliente = Cliente::create(['nome' => 'Cliente Teste', 'cpf_cnpj' => '87748248800']);
        $os = OrdemServico::create([
            'cliente_id' => $cliente->id,
            'status'     => 'ORCAMENTO_ENVIADO',
        ]);
        $servico = OsItem::create([
            'os_id' => $os->id, 'tipo' => 'SERVICO',
            'descricao' => 'Mão de obra', 'quantidade' => 1, 'valor_unitario' => 100,
        ]);
        $peca = OsItem::create([
            'os_id' => $os->id, 'tipo' => 'PECA',
            'descricao' => 'Filtro', 'quantidade' => 1, 'valor_unitario' => 50,
        ]);
        $orcamento = Orcamento::create([
            'os_id'      => $os->id,
            'token'      => Orcamento::gerarToken(),
            'status'     => 'PENDENTE',
            'enviado_em' => now(),
        ]);

        return ['token' => $orcamento->token, 'os' => $os, 'servico' => $servico, 'peca' => $peca];
    }

    public function test_aprovar_servico_e_peca_resulta_em_aprovado(): void
    {
        $c = $this->cenario();

        $this->postJson("/api/orcamento/{$c['token']}/responder", [
            'servicos_aprovados' => [$c['servico']->id],
            'pecas_aprovadas'    => [$c['peca']->id],
        ])->assertOk()->assertJsonFragment(['status' => 'APROVADO']);

        $this->assertEquals(150, $c['os']->fresh()->valor_total);
        $this->assertTrue($c['peca']->fresh()->aprovado);
    }

    public function test_recusar_peca_resulta_em_parcial_e_exclui_do_total(): void
    {
        $c = $this->cenario();

        $this->postJson("/api/orcamento/{$c['token']}/responder", [
            'servicos_aprovados' => [$c['servico']->id],
            'pecas_aprovadas'    => [],
        ])->assertOk()->assertJsonFragment(['status' => 'PARCIAL']);

        // Total = só o serviço aprovado; a peça recusada sai do total.
        $this->assertEquals(100, $c['os']->fresh()->valor_total);
        $this->assertTrue($c['servico']->fresh()->aprovado);
        $this->assertFalse($c['peca']->fresh()->aprovado);
    }

    public function test_recusar_tudo_resulta_em_recusado(): void
    {
        $c = $this->cenario();

        $this->postJson("/api/orcamento/{$c['token']}/responder", [
            'servicos_aprovados' => [],
            'pecas_aprovadas'    => [],
        ])->assertOk()->assertJsonFragment(['status' => 'RECUSADO']);

        $this->assertEquals(0, $c['os']->fresh()->valor_total);
    }

    /**
     * Tarefa 2026-09-22: um desconto pode já ter sido aplicado à OS antes
     * do cliente responder o orçamento (ex.: sinal pago com desconto). Se
     * só parte dos itens for aprovada, o subtotal aprovado pode ficar menor
     * que o desconto já registrado — precisa reclampar, senão valor_total
     * ficaria negativo.
     */
    public function test_desconto_ja_aplicado_e_reclampado_quando_aprovacao_parcial_reduz_o_subtotal(): void
    {
        $c = $this->cenario();
        // Desconto de R$120 cabia no total original (100 serviço + 50 peça = 150).
        $c['os']->update(['desconto' => 120]);

        $this->postJson("/api/orcamento/{$c['token']}/responder", [
            'servicos_aprovados' => [$c['servico']->id],
            'pecas_aprovadas'    => [], // peça recusada — sobra só o serviço de R$100.
        ])->assertOk()->assertJsonFragment(['status' => 'PARCIAL']);

        $fresh = $c['os']->fresh();
        // Subtotal aprovado (100) é menor que o desconto (120) — reclampa pra 100.
        $this->assertSame(100.0, (float) $fresh->desconto);
        $this->assertSame(0.0, (float) $fresh->valor_total);
    }
}
