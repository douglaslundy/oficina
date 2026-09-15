<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\Configuracao;
use App\Models\NotaEntrada;
use App\Models\Oficina;
use App\Services\AlertaDispatchService;
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use App\Services\Fiscal\FiscalProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pedido explícito do usuário (2026-09-14): alerta automático dentro do
 * sistema quando uma nota nova for emitida pro CNPJ da oficina, sem
 * reconsultar sempre as mesmas. Mesmo padrão de mock de
 * ReconciliarNotasProcessandoTest — `FiscalProviderManager::forTenant()` é
 * trocado por um provider fake que só implementa listarNotasRecebidas() de
 * verdade (os outros métodos das interfaces nunca são chamados aqui).
 */
class VerificarNotasTerceirosRecebidasTest extends TestCase
{
    use RefreshDatabase;

    private function oficinaComCnpj(): Oficina
    {
        $of = Oficina::create(['nome' => 'O', 'slug' => 'o-' . uniqid(), 'cnpj' => uniqid('c'), 'status' => 'ATIVA']);
        Configuracao::create(['oficina_id' => $of->id, 'cnpj' => '11222333000181']);
        return $of;
    }

    private function fakeProvider(array $resumos): FiscalProvider
    {
        return new class($resumos) implements FiscalProvider, ConsultaNotaTerceiroProvider {
            public function __construct(private readonly array $resumos) {}
            public function registrarEmissor(EmissorData $e): RegistroResultado { throw new \LogicException('não usado neste teste'); }
            public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void { throw new \LogicException('não usado neste teste'); }
            public function emitir(NotaFiscalData $nota): EmissaoResultado { throw new \LogicException('não usado neste teste'); }
            public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado { throw new \LogicException('não usado neste teste'); }
            public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado { throw new \LogicException('não usado neste teste'); }
            public function consultarNotaRecebida(string $chaveAcesso): \App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado { throw new \LogicException('não usado neste teste'); }
            public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array { return $this->resumos; }
        };
    }

    private function resumo(string $chave): ConsultaNotaTerceiroResumo
    {
        return new ConsultaNotaTerceiroResumo(
            chaveAcesso: $chave, fornecedorNome: 'Fornecedor X', fornecedorCnpj: '99887766000155',
            dataEmissao: '2026-09-14', valorTotal: 150.0, completa: true,
        );
    }

    public function test_nota_nova_gera_notificacao_e_dispara_alerta(): void
    {
        $this->oficinaComCnpj();
        $provider = $this->fakeProvider([$this->resumo(str_repeat('1', 44))]);

        $this->mock(FiscalProviderManager::class, fn ($m) => $m->shouldReceive('forTenant')->andReturn($provider));
        $this->mock(AlertaDispatchService::class, fn ($m) => $m->shouldReceive('dispatch')->once()
            ->with('NOTA_TERCEIRO_RECEBIDA', Mockery::on(fn ($vars) => $vars['fornecedor'] === 'Fornecedor X')));

        $this->artisan('nfe:verificar-notas-recebidas')->assertSuccessful();

        $this->assertDatabaseHas('notas_terceiro_notificadas', ['chave_acesso' => str_repeat('1', 44)]);
    }

    /**
     * Corrigido 2026-09-14 (mesmo dia): a nota já lançada CONTINUA sendo
     * espelhada em `notas_terceiro_notificadas` — a tela "Notas Recebidas"
     * (`EntradaNfController::recebidas()`) precisa dela ali pra mostrar o
     * flag `ja_lancada` (comportamento já existente antes desta feature,
     * ver `EntradaNfConsultaTest::test_recebidas_lista_com_ja_lancada_
     * calculado`). Só o ALERTA (que significa "nota nova, ainda não
     * importada") é que não dispara pra ela.
     */
    public function test_nota_ja_lancada_e_espelhada_mas_nao_gera_alerta(): void
    {
        $of = $this->oficinaComCnpj();
        $chave = str_repeat('2', 44);
        NotaEntrada::create([
            'chave_acesso' => $chave, 'fornecedor_nome' => 'X', 'valor_total' => 10,
            'data_emissao' => '2026-09-01', 'oficina_id' => $of->id,
        ]);
        $provider = $this->fakeProvider([$this->resumo($chave)]);

        $this->mock(FiscalProviderManager::class, fn ($m) => $m->shouldReceive('forTenant')->andReturn($provider));
        $this->mock(AlertaDispatchService::class, fn ($m) => $m->shouldNotReceive('dispatch'));

        $this->artisan('nfe:verificar-notas-recebidas')->assertSuccessful();

        $this->assertDatabaseHas('notas_terceiro_notificadas', ['chave_acesso' => $chave]);
    }

    public function test_nota_ja_notificada_anteriormente_nao_alerta_de_novo(): void
    {
        $this->oficinaComCnpj();
        $chave = str_repeat('3', 44);
        \App\Models\NotaTerceiroNotificada::create(['chave_acesso' => $chave, 'fornecedor_nome' => 'X', 'valor_total' => 10]);
        $provider = $this->fakeProvider([$this->resumo($chave)]);

        $this->mock(FiscalProviderManager::class, fn ($m) => $m->shouldReceive('forTenant')->andReturn($provider));
        $this->mock(AlertaDispatchService::class, fn ($m) => $m->shouldNotReceive('dispatch'));

        $this->artisan('nfe:verificar-notas-recebidas')->assertSuccessful();

        $this->assertDatabaseCount('notas_terceiro_notificadas', 1);
    }

    /**
     * Achado AO VIVO em produção (2026-09-14, primeira execução real): a
     * Distribuição DFe pode devolver a MESMA chave duas vezes no mesmo lote
     * (ex.: resNFe resumido numa página de NSU e procNFe completo em outra)
     * — sem dedup dentro do próprio loop, a 2ª tentativa de INSERT violava
     * unique(oficina_id, chave_acesso) e derrubava o comando pra aquela
     * oficina inteira.
     */
    public function test_mesma_chave_duplicada_no_mesmo_lote_nao_quebra_o_comando(): void
    {
        $this->oficinaComCnpj();
        $chave = str_repeat('4', 44);
        $provider = $this->fakeProvider([$this->resumo($chave), $this->resumo($chave)]);

        $this->mock(FiscalProviderManager::class, fn ($m) => $m->shouldReceive('forTenant')->andReturn($provider));
        $this->mock(AlertaDispatchService::class, fn ($m) => $m->shouldReceive('dispatch')->once());

        $this->artisan('nfe:verificar-notas-recebidas')->assertSuccessful();

        $this->assertDatabaseCount('notas_terceiro_notificadas', 1);
    }

    public function test_falha_do_provedor_nao_derruba_o_comando(): void
    {
        $this->oficinaComCnpj();
        $provider = new class implements FiscalProvider, ConsultaNotaTerceiroProvider {
            public function registrarEmissor(EmissorData $e): RegistroResultado { throw new \LogicException('não usado'); }
            public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void { throw new \LogicException('não usado'); }
            public function emitir(NotaFiscalData $nota): EmissaoResultado { throw new \LogicException('não usado'); }
            public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado { throw new \LogicException('não usado'); }
            public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado { throw new \LogicException('não usado'); }
            public function consultarNotaRecebida(string $chaveAcesso): \App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado { throw new \LogicException('não usado'); }
            public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array { throw new \RuntimeException('Falha simulada de provedor.'); }
        };

        $this->mock(FiscalProviderManager::class, fn ($m) => $m->shouldReceive('forTenant')->andReturn($provider));
        $this->mock(AlertaDispatchService::class, fn ($m) => $m->shouldNotReceive('dispatch'));

        $this->artisan('nfe:verificar-notas-recebidas')->assertSuccessful();

        $this->assertDatabaseCount('notas_terceiro_notificadas', 0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
