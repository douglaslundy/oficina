<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Services\NfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReconciliarNotasProcessandoTest extends TestCase
{
    use RefreshDatabase;

    private function notaProcessando(Oficina $of, int $minutosAtras): NotaFiscal
    {
        $cliente = Cliente::create(['nome' => 'C', 'cpf_cnpj' => uniqid(), 'oficina_id' => $of->id]);
        $nota = NotaFiscal::create([
            'cliente_id' => $cliente->id, 'oficina_id' => $of->id,
            'modelo' => 'NFS-e', 'natureza_operacao' => 'Prestação de Serviços',
            'subtotal' => 100, 'valor_total' => 100, 'status' => 'PROCESSANDO',
            'provedor' => 'SPEDY', 'referencia_externa' => 'ref-' . uniqid(),
        ]);
        NotaFiscal::where('id', $nota->id)->update(['criado_em' => now()->subMinutes($minutosAtras)]);
        return $nota->fresh();
    }

    public function test_nota_processando_antiga_e_reconciliada_com_o_status_do_provedor(): void
    {
        $of = Oficina::create(['nome' => 'O', 'slug' => 'o-' . uniqid(), 'cnpj' => uniqid('c'), 'status' => 'ATIVA']);
        $nota = $this->notaProcessando($of, 20);

        $this->mock(NfeService::class, function ($m) {
            $m->shouldReceive('consultarStatus')->once()->andReturn([
                'status' => 'AUTORIZADA', 'chave' => 'CH123', 'protocolo' => 'P1',
                'xml_retorno' => '<xml/>', 'numero' => 55,
            ]);
        });

        $this->artisan('nfe:reconciliar-processando')->assertSuccessful();

        $this->assertDatabaseHas('notas_fiscais', ['id' => $nota->id, 'status' => 'AUTORIZADA', 'numero' => 55]);
    }

    public function test_nota_processando_recente_e_ignorada(): void
    {
        $of = Oficina::create(['nome' => 'O', 'slug' => 'o-' . uniqid(), 'cnpj' => uniqid('c'), 'status' => 'ATIVA']);
        $nota = $this->notaProcessando($of, 3);

        $this->mock(NfeService::class, function ($m) {
            $m->shouldNotReceive('consultarStatus');
        });

        $this->artisan('nfe:reconciliar-processando')->assertSuccessful();

        $this->assertDatabaseHas('notas_fiscais', ['id' => $nota->id, 'status' => 'PROCESSANDO']);
    }

    public function test_falha_na_consulta_nao_derruba_o_comando_nem_muda_a_nota(): void
    {
        $of = Oficina::create(['nome' => 'O', 'slug' => 'o-' . uniqid(), 'cnpj' => uniqid('c'), 'status' => 'ATIVA']);
        $nota = $this->notaProcessando($of, 20);

        $this->mock(NfeService::class, function ($m) {
            $m->shouldReceive('consultarStatus')->once()->andThrow(new \RuntimeException('timeout'));
        });

        $this->artisan('nfe:reconciliar-processando')->assertSuccessful();

        $this->assertDatabaseHas('notas_fiscais', ['id' => $nota->id, 'status' => 'PROCESSANDO']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
