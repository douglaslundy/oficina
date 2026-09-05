<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Jobs\EmitirNotaFiscalJob;
use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Services\Fiscal\AplicarResultadoNotaService;
use App\Services\NfeService;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmitirNotaFiscalJobTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $oficina = Oficina::create([
            'nome' => 'Oficina', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);

        $cliente = Cliente::create(['nome' => 'Cliente', 'cpf_cnpj' => '52998224725', 'oficina_id' => $oficina->id]);
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'oficina_id' => $oficina->id,
            'modelo' => 'NFS-e', 'natureza_operacao' => 'Prestação de Serviços',
            'subtotal' => 100, 'valor_total' => 100, 'status' => 'PROCESSANDO',
        ]);

        return [$oficina, $nota];
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        Mockery::close();
        parent::tearDown();
    }

    public function test_emite_e_aplica_o_resultado(): void
    {
        [$oficina, $nota] = $this->cenario();

        $nfe = Mockery::mock(NfeService::class);
        $nfe->shouldReceive('emitir')->once()->andReturn([
            'status' => 'AUTORIZADA', 'chave' => 'CHAVE1', 'protocolo' => 'P1',
            'numero' => '10', 'xml_retorno' => '<xml/>', 'pdf_url' => null,
            'qrcode_url' => null, 'mensagem_erro' => null, 'referencia_externa' => 'nf-x',
        ]);

        (new EmitirNotaFiscalJob($nota->id, $oficina->id, $oficina->slug, 'HOMOLOGACAO'))
            ->handle($nfe, app(AplicarResultadoNotaService::class));

        $this->assertSame('AUTORIZADA', $nota->fresh()->status);
        $this->assertSame('CHAVE1', $nota->fresh()->chave_acesso);
    }

    public function test_excecao_na_emissao_marca_rejeitada_com_mensagem(): void
    {
        [$oficina, $nota] = $this->cenario();

        $nfe = Mockery::mock(NfeService::class);
        $nfe->shouldReceive('emitir')->once()->andThrow(new \RuntimeException('SEFAZ fora do ar'));

        (new EmitirNotaFiscalJob($nota->id, $oficina->id, $oficina->slug, 'HOMOLOGACAO'))
            ->handle($nfe, app(AplicarResultadoNotaService::class));

        $fresca = $nota->fresh();
        $this->assertSame('REJEITADA', $fresca->status);
        $this->assertStringContainsString('SEFAZ fora do ar', $fresca->mensagem_erro);
    }

    public function test_nota_que_ja_saiu_de_processando_e_ignorada(): void
    {
        [$oficina, $nota] = $this->cenario();
        $nota->update(['status' => 'AUTORIZADA']);

        $nfe = Mockery::mock(NfeService::class);
        $nfe->shouldNotReceive('emitir');

        (new EmitirNotaFiscalJob($nota->id, $oficina->id, $oficina->slug, 'HOMOLOGACAO'))
            ->handle($nfe, app(AplicarResultadoNotaService::class));

        $this->assertSame('AUTORIZADA', $nota->fresh()->status);
    }
}
