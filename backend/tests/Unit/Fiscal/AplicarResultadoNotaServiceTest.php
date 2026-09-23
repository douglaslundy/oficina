<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Models\Plano;
use App\Services\Fiscal\AplicarResultadoNotaService;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achados 2026-09-23 (auditoria fiscal completa):
 *
 * 1. aplicar() disparava e-mail "NF Autorizada" + registrarNotaSeExcedente
 *    sempre que $resultado['status'] === 'AUTORIZADA', sem checar se a nota
 *    JÁ estava AUTORIZADA antes — este método é chamado tanto pelo job de
 *    emissão quanto pelo polling de status quanto pelo cron de
 *    reconciliação, então duas chamadas observando a mesma transição
 *    disparavam os efeitos colaterais duas vezes.
 * 2. registrarNotaSeExcedente() (PlanLimitService) em si não era idempotente
 *    — cada chamada criava uma Cobranca NOTA_EXCEDENTE nova, então mesmo
 *    corrigindo (1), uma corrida verdadeiramente concorrente (não
 *    reproduzível num único processo PHPUnit) ainda cobraria duas vezes.
 *    Corrigido com uma checagem "já existe Cobranca pra esta nota" antes de
 *    criar.
 */
class AplicarResultadoNotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $plano = Plano::create([
            'nome' => 'Básico', 'preco_mensal' => 99,
            'limite_usuarios' => 5, 'limite_os_mes' => 100, 'limite_produtos' => 500,
            'limite_clientes' => 500,
            // limite=0: QUALQUER nota AUTORIZADA no mês já é excedente —
            // simplifica o cenário de teste.
            'limite_notas_mes' => 0, 'preco_nota_excedente' => 10,
            'ativo' => true,
        ]);

        $oficina = Oficina::create([
            'nome' => 'Oficina', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
            'plano_id' => $plano->id,
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);

        Configuracao::create([
            'oficina_id' => $oficina->id, 'ambiente_fiscal' => 'PRODUCAO',
            'uf' => 'MG', 'regime_tributario' => 'Simples Nacional',
        ]);

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
        parent::tearDown();
    }

    private function resultadoAutorizada(): array
    {
        return [
            'status' => 'AUTORIZADA', 'chave' => 'CHAVE1', 'protocolo' => 'P1',
            'numero' => '10', 'xml_retorno' => '<xml/>', 'qrcode_url' => null,
            'mensagem_erro' => null,
        ];
    }

    public function test_duas_chamadas_para_a_mesma_transicao_geram_so_uma_cobranca_de_excedente(): void
    {
        [, $nota] = $this->cenario();
        $service = app(AplicarResultadoNotaService::class);

        // Primeira chamada: nota sai de PROCESSANDO -> AUTORIZADA. Dispara
        // os efeitos colaterais (billing de excedente, já que o plano de
        // teste tem limite=0).
        $service->aplicar($nota, $this->resultadoAutorizada(), 'PRODUCAO');
        $this->assertSame(1, Cobranca::where('nota_fiscal_id', $nota->id)->count());

        // Segunda chamada "simulando" o cron de reconciliação observando a
        // MESMA nota já AUTORIZADA (ex: o polling de status rodou entre a
        // primeira chamada e a reconciliação, ambos viram AUTORIZADA vindo
        // do provedor). Não pode gerar uma segunda cobrança.
        $service->aplicar($nota->fresh(), $this->resultadoAutorizada(), 'PRODUCAO');
        $this->assertSame(
            1, Cobranca::where('nota_fiscal_id', $nota->id)->count(),
            'Uma segunda chamada pra nota já AUTORIZADA não pode duplicar a cobrança de excedente.',
        );
    }

    public function test_primeira_transicao_para_autorizada_persiste_dados_da_nota(): void
    {
        [, $nota] = $this->cenario();

        app(AplicarResultadoNotaService::class)->aplicar($nota, $this->resultadoAutorizada(), 'PRODUCAO');

        $fresca = $nota->fresh();
        $this->assertSame('AUTORIZADA', $fresca->status);
        $this->assertSame('CHAVE1', $fresca->chave_acesso);
        $this->assertSame(10, $fresca->numero);
        $this->assertNotNull($fresca->emitido_em);
    }

    public function test_registrar_nota_se_excedente_e_idempotente_mesmo_chamado_diretamente_duas_vezes(): void
    {
        [, $nota] = $this->cenario();
        $nota->update(['status' => 'AUTORIZADA', 'emitido_em' => now()]);

        $planLimit = app(\App\Services\PlanLimitService::class);
        $planLimit->registrarNotaSeExcedente($nota->fresh());
        $planLimit->registrarNotaSeExcedente($nota->fresh());

        $this->assertSame(
            1, Cobranca::where('nota_fiscal_id', $nota->id)->where('tipo', 'NOTA_EXCEDENTE')->count(),
            'registrarNotaSeExcedente() precisa ser idempotente por si só, não só via o guard do status anterior.',
        );
    }
}
