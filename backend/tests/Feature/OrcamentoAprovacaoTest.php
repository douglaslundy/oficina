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
}
