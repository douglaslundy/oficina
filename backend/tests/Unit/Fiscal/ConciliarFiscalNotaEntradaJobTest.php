<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Jobs\ConciliarFiscalNotaEntradaJob;
use App\Models\NotaEntrada;
use App\Models\NotaEntradaItem;
use App\Models\Oficina;
use App\Models\Produto;
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\FiscalProviderManager;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ConciliarFiscalNotaEntradaJobTest extends TestCase
{
    use RefreshDatabase;

    private function montarCenario(): array
    {
        $oficina = Oficina::create([
            'nome' => 'Oficina Teste', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);

        $produto = Produto::create([
            'nome' => 'Filtro de oleo', 'sku' => 'SKU1', 'categoria' => 'Filtros',
            'unidade' => 'Un', 'qty_atual' => 10, 'qty_minima' => 2,
            'preco_custo' => 10, 'preco_venda' => 15,
        ]);

        $nota = NotaEntrada::create([
            'oficina_id' => $oficina->id, 'chave_acesso' => '35260712345678000199550010000012340000000001',
            'valor_total' => 100,
        ]);
        NotaEntradaItem::create([
            'nota_entrada_id' => $nota->id, 'produto_id' => $produto->id,
            'codigo_barras_xml' => '7891234567890', 'descricao_xml' => 'Filtro de oleo',
            'quantidade' => 5, 'valor_unitario' => 20,
        ]);

        return [$oficina, $nota, $produto];
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        Mockery::close();
        parent::tearDown();
    }

    public function test_conciliacao_completa_aplica_fiscal_e_marca_conferida_sem_mexer_em_estoque(): void
    {
        [$oficina, $nota, $produto] = $this->montarCenario();
        $qtyAntes = $produto->qty_atual;

        $providerFake = Mockery::mock(ConsultaNotaTerceiroProvider::class);
        $providerFake->shouldReceive('consultarNotaRecebida')
            ->with('35260712345678000199550010000012340000000001')
            ->andReturn(ConsultaNotaTerceiroResultado::completa([
                'itens' => [[
                    'codigo_barras' => '7891234567890', 'descricao' => 'Filtro de oleo',
                    'ncm' => '84212300', 'cfop' => '5102', 'cest' => null,
                    'origem' => 0, 'cst_csosn' => '00', 'tributacao_icms' => 'NORMAL',
                ]],
            ]));

        $this->mock(FiscalProviderManager::class, function ($mock) use ($providerFake) {
            $mock->shouldReceive('forTenant')->andReturn($providerFake);
        });

        (new ConciliarFiscalNotaEntradaJob($nota->id, $oficina->id, $oficina->slug))->handle(
            app(FiscalProviderManager::class),
            app(\App\Services\Fiscal\ProdutoFiscalService::class),
        );

        $produto->refresh();
        $nota->refresh();

        $this->assertSame('84212300', $produto->ncm);
        $this->assertSame('NORMAL', $produto->tributacao_icms);
        $this->assertSame($qtyAntes, $produto->qty_atual, 'Conciliação fiscal nunca pode mudar quantidade de estoque.');
        $this->assertNotNull($nota->fiscal_conferida_em);
        $this->assertNotNull($nota->fiscal_ultima_consulta_em);
        $this->assertNull($nota->fiscal_erro_consulta);
    }

    public function test_nota_sem_chave_de_acesso_marca_erro_sem_chamar_provider(): void
    {
        [$oficina, $nota, $produto] = $this->montarCenario();
        $nota->update(['chave_acesso' => null]);

        $mock = Mockery::mock(FiscalProviderManager::class);
        $mock->shouldNotReceive('forTenant');
        $this->app->instance(FiscalProviderManager::class, $mock);

        (new ConciliarFiscalNotaEntradaJob($nota->id, $oficina->id, $oficina->slug))->handle(
            $mock, app(\App\Services\Fiscal\ProdutoFiscalService::class),
        );

        $nota->refresh();
        $this->assertNull($nota->fiscal_conferida_em);
        $this->assertStringContainsString('sem chave de acesso', $nota->fiscal_erro_consulta);
    }

    public function test_motor_nao_suportado_marca_erro(): void
    {
        [$oficina, $nota, $produto] = $this->montarCenario();

        $providerSemSuporte = Mockery::mock(\App\Services\Fiscal\Contracts\FiscalProvider::class);
        $this->mock(FiscalProviderManager::class, function ($mock) use ($providerSemSuporte) {
            $mock->shouldReceive('forTenant')->andReturn($providerSemSuporte);
        });

        (new ConciliarFiscalNotaEntradaJob($nota->id, $oficina->id, $oficina->slug))->handle(
            app(FiscalProviderManager::class), app(\App\Services\Fiscal\ProdutoFiscalService::class),
        );

        $nota->refresh();
        $this->assertStringContainsString('não suporta', $nota->fiscal_erro_consulta);
    }

    public function test_erro_do_provedor_marca_mensagem_e_nao_conclui(): void
    {
        [$oficina, $nota, $produto] = $this->montarCenario();

        $providerFake = Mockery::mock(ConsultaNotaTerceiroProvider::class);
        $providerFake->shouldReceive('consultarNotaRecebida')
            ->andReturn(ConsultaNotaTerceiroResultado::erro('Chave de API inválida.'));
        $this->mock(FiscalProviderManager::class, function ($mock) use ($providerFake) {
            $mock->shouldReceive('forTenant')->andReturn($providerFake);
        });

        (new ConciliarFiscalNotaEntradaJob($nota->id, $oficina->id, $oficina->slug))->handle(
            app(FiscalProviderManager::class), app(\App\Services\Fiscal\ProdutoFiscalService::class),
        );

        $nota->refresh();
        $this->assertNull($nota->fiscal_conferida_em);
        $this->assertSame('Chave de API inválida.', $nota->fiscal_erro_consulta);
    }
}
