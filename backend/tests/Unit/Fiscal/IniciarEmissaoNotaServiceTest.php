<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Services\Fiscal\IniciarEmissaoNotaService;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Achado 2026-09-23 (auditoria fiscal completa): iniciar() checava
 * $nota->status em memória e só depois travava a linha pra alocar número —
 * duas chamadas concorrentes (duplo clique, retry do frontend) podiam
 * passar as duas pela checagem antes de qualquer uma persistir
 * PROCESSANDO, cada uma alocar um número e cada uma disparar um job de
 * emissão real — nota fiscal duplicada de verdade. Corrigido envolvendo a
 * checagem+alocação+update em DB::transaction() com lockForUpdate().
 *
 * Concorrência real não dá pra testar num processo PHPUnit único — estes
 * testes cobrem o que É testável nesse regime: a segunda chamada
 * SEQUENCIAL (já cobria o caso feliz antes da correção) continua correta,
 * e a guarda dentro do lock não regride o comportamento de retorno.
 */
class IniciarEmissaoNotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $oficina = Oficina::create([
            'nome' => 'Oficina', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
            'provedor_fiscal' => 'FOCUS',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);

        Configuracao::create([
            'oficina_id' => $oficina->id, 'ambiente_fiscal' => 'HOMOLOGACAO',
            'uf' => 'MG', 'regime_tributario' => 'Simples Nacional',
            'serie_nf' => '1', 'serie_nfce' => '1',
        ]);

        $cliente = Cliente::create(['nome' => 'Cliente', 'cpf_cnpj' => '52998224725', 'oficina_id' => $oficina->id]);
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'oficina_id' => $oficina->id,
            'modelo' => 'NFS-e', 'natureza_operacao' => 'Prestação de Serviços',
            'subtotal' => 100, 'valor_total' => 100, 'status' => 'RASCUNHO',
        ]);

        return [$oficina, $nota];
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        parent::tearDown();
    }

    public function test_iniciar_marca_processando_aloca_numero_e_despacha_job(): void
    {
        Queue::fake();
        [, $nota] = $this->cenario();

        $resultado = app(IniciarEmissaoNotaService::class)->iniciar($nota);

        $this->assertTrue($resultado);
        $fresca = $nota->fresh();
        $this->assertSame('PROCESSANDO', $fresca->status);
        $this->assertNotNull($fresca->numero);
        Queue::assertPushed(\App\Jobs\EmitirNotaFiscalJob::class, 1);
    }

    public function test_segunda_chamada_sequencial_nao_reprocessa_nem_despacha_job_de_novo(): void
    {
        Queue::fake();
        [, $nota] = $this->cenario();

        $service = app(IniciarEmissaoNotaService::class);

        $primeira = $service->iniciar($nota);
        // Recarrega o mesmo registro (o service opera sobre a linha travada
        // no banco, não sobre o objeto em memória) — simula o segundo
        // request lendo o estado já persistido pela primeira chamada.
        $segunda = $service->iniciar($nota->fresh());

        $this->assertTrue($primeira);
        $this->assertFalse($segunda, 'Uma nota já PROCESSANDO não pode iniciar emissão de novo.');
        Queue::assertPushed(\App\Jobs\EmitirNotaFiscalJob::class, 1, 'Só pode haver UM job de emissão pra mesma nota.');
    }

    public function test_nota_ja_autorizada_nao_inicia_emissao(): void
    {
        Queue::fake();
        [, $nota] = $this->cenario();
        $nota->update(['status' => 'AUTORIZADA']);

        $resultado = app(IniciarEmissaoNotaService::class)->iniciar($nota);

        $this->assertFalse($resultado);
        Queue::assertNotPushed(\App\Jobs\EmitirNotaFiscalJob::class);
    }

    public function test_nota_rejeitada_pode_iniciar_nova_tentativa(): void
    {
        Queue::fake();
        [, $nota] = $this->cenario();
        $nota->update(['status' => 'REJEITADA']);

        $resultado = app(IniciarEmissaoNotaService::class)->iniciar($nota);

        $this->assertTrue($resultado);
        $this->assertSame('PROCESSANDO', $nota->fresh()->status);
    }
}
