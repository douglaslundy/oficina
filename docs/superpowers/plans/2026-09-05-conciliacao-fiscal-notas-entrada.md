# Conciliação fiscal de notas de entrada já importadas — Design + Plano

> **For agentic workers:** implemente task por task, TDD (RED→GREEN),
> commit a cada task. Rode a suíte Unit (`php vendor/bin/phpunit tests/Unit`)
> depois de cada task backend pra checar regressão. Feature tests
> (`tests/Feature/...RefreshDatabase`) NÃO rodam nesta máquina local — sem
> Postgres. Escreva-os corretamente, rode `php -l`, e diga claramente no
> relatório final quais Feature tests não puderam ser executados e por quê
> — o controlador confirma depois via CI ou túnel SSH pra Postgres.

**Goal:** o usuário já tem notas de entrada (`NotaEntrada`) importadas
anteriormente cujos produtos podem ter ficado com dados fiscais
incompletos. Construir uma rotina que reconsulta cada nota na SEFAZ (via
os provedores já integrados) e atualiza SÓ os campos fiscais dos produtos
já vinculados — nunca estoque — marcando a nota como "conferida" quando
todos os itens ficarem fiscalmente completos.

**Architecture:** reaproveita 100% de infraestrutura já existente:
`ConsultaNotaTerceiroProvider::consultarNotaRecebida()` (Spedy/Focus, já
implementado) pra reconsultar; `ProdutoFiscalService::aplicarDoXml()` (já
implementado, nunca mexe em estoque, já abre divergência em vez de
sobrescrever) pra aplicar; um Job novo em fila (Redis) por nota, seguindo
o padrão de `ReconciliarNotasProcessando` pra `TenancyContext` manual fora
do ciclo de request. Estende a tela já existente "Histórico de Entrada de
NF" — nenhuma tela nova.

**Tech Stack:** Laravel 12, PHPUnit, Next.js/TypeScript (mesmo stack do
resto do backend/frontend).

## Contexto técnico confirmado (não repesquisar)

- `NotaEntrada` (`backend/app/Models/NotaEntrada.php`) usa `HasTenantScope`
  — toda query já filtra pela oficina do `TenancyContext` atual.
- `NotaEntradaItem.produto_id` é **NOT NULL** (toda linha de item sempre
  tem um produto vinculado — nunca fica solto). Colunas relevantes já
  existentes: `codigo_barras_xml`, `descricao_xml`, `ncm_xml`, `cfop_xml`,
  `cest_xml`, `origem_xml`, `cst_csosn_xml`, `unidade_xml`.
- `ProdutoFiscalService::CAMPOS = ['ncm', 'cest', 'origem', 'tributacao_icms']`
  — são exatamente os 4 campos que definem "produto fiscalmente completo":
  um produto está completo quando `ncm !== null && tributacao_icms !== null`
  (mesma regra que `EntradaNfController::montarPreview()` já usa pra
  `fiscal_pendente`, não reinventar).
- `ConsultaNotaTerceiroResultado::completa($dados)->dados['itens']` tem o
  mesmo shape que `NotaEntradaXmlParser::parse()['itens']` — cada item tem
  `codigo_barras`, `descricao`, `ncm`, `cfop`, `cest`, `origem`,
  `cst_csosn`, `tributacao_icms` — é exatamente o array que
  `ProdutoFiscalService::aplicarDoXml(Produto $produto, array $fiscalXml, ?string $notaEntradaId)`
  já espera receber.
- `FiscalProviderManager::forTenant(): FiscalProvider` resolve o provider
  certo pra oficina atual. Checar `instanceof ConsultaNotaTerceiroProvider`
  antes de chamar `consultarNotaRecebida()` (hoje só Spedy/Focus
  implementam — NfePhpProvider pode passar a implementar em paralelo,
  Task 3 de outro plano; este aqui não depende disso, é
  provider-agnóstico via a interface).

## Global Constraints

- **Nunca chamar `EstoqueService` em nenhum momento desta feature** —
  é a regra mais importante pedida pelo usuário. Escrever um teste
  explícito provando que `qty_atual` do produto não muda antes/depois.
- Fila (Redis), nunca síncrono/bloqueante no request HTTP — mesma
  convenção já usada em `GerarBackupJob`.
- `declare(strict_types=1)` em todo arquivo PHP novo.
- Reaproveitar `ProdutoFiscalService::aplicarDoXml()` tal como está — não
  duplicar a lógica de PREENCHER/DIVERGÊNCIA/NADA em nenhum lugar novo.

---

### Task 1: Migração — colunas de conciliação em `notas_entrada`

**Files:**
- Create: `backend/database/migrations/2026_09_05_000001_add_conciliacao_fiscal_to_notas_entrada.php`

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_entrada', function (Blueprint $table) {
            $table->timestampTz('fiscal_conferida_em')->nullable();
            $table->timestampTz('fiscal_ultima_consulta_em')->nullable();
            $table->text('fiscal_erro_consulta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notas_entrada', function (Blueprint $table) {
            $table->dropColumn(['fiscal_conferida_em', 'fiscal_ultima_consulta_em', 'fiscal_erro_consulta']);
        });
    }
};
```

- [ ] **Step 1: Criar a migração** (código acima).
- [ ] **Step 2: Adicionar os 3 campos a `$fillable` e `$casts` em `backend/app/Models/NotaEntrada.php`**

```php
protected $fillable = [
    'numero_nf', 'serie', 'chave_acesso', 'fornecedor_nome', 'fornecedor_cnpj',
    'valor_total', 'data_emissao', 'xml_original', 'usuario_id', 'oficina_id',
    'fiscal_conferida_em', 'fiscal_ultima_consulta_em', 'fiscal_erro_consulta',
];

protected $casts = [
    'criado_em'                 => 'datetime',
    'data_emissao'              => 'date',
    'valor_total'               => 'float',
    'fiscal_conferida_em'       => 'datetime',
    'fiscal_ultima_consulta_em' => 'datetime',
];
```

- [ ] **Step 3: Rodar a migração** (não dá pra rodar localmente sem Postgres —
  só `php -l` no arquivo da migração e no model. A migração real roda em CI/produção.)
- [ ] **Step 4: Commit**

```bash
git add backend/database/migrations/2026_09_05_000001_add_conciliacao_fiscal_to_notas_entrada.php backend/app/Models/NotaEntrada.php
git commit -m "feat(fiscal): colunas de conciliacao fiscal em notas_entrada"
```

---

### Task 2: `ConciliarFiscalNotaEntradaJob`

**Files:**
- Create: `backend/app/Jobs/ConciliarFiscalNotaEntradaJob.php`
- Test: `backend/tests/Unit/Fiscal/ConciliarFiscalNotaEntradaJobTest.php`

**Interfaces:**
- Consumes: `FiscalProviderManager::forTenant()`, `ConsultaNotaTerceiroProvider::consultarNotaRecebida()`,
  `ProdutoFiscalService::aplicarDoXml()`, `App\Tenancy\TenancyContext::set()/clear()`.
- Produces: job dispatchável como `ConciliarFiscalNotaEntradaJob::dispatch($notaEntradaId, $oficinaId, $oficinaSlug)`.

O Job (não é fácil de testar via `Http::fake()` porque instancia providers
reais via `FiscalProviderManager` dentro do `handle()` — em vez disso,
estruture a lógica de decisão como um método público separado e puro,
testável por injeção direta, e mantenha `handle()` fino, chamando esse
método com um provider já resolvido). Estrutura:

```php
<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotaEntrada;
use App\Models\Produto;
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\Fiscal\ProdutoFiscalService;
use App\Tenancy\TenancyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reconsulta uma NotaEntrada já importada no provedor fiscal e atualiza
 * SÓ os campos fiscais dos produtos já vinculados (ProdutoFiscalService::
 * aplicarDoXml() nunca mexe em estoque nem cria produto). Marca a nota
 * como fiscal_conferida_em quando todos os itens ficam completos.
 */
class ConciliarFiscalNotaEntradaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 1;

    public function __construct(
        private readonly string $notaEntradaId,
        private readonly string $oficinaId,
        private readonly string $oficinaSlug,
    ) {}

    public function handle(FiscalProviderManager $providerManager, ProdutoFiscalService $fiscalService): void
    {
        TenancyContext::set($this->oficinaId, $this->oficinaSlug);

        try {
            $nota = NotaEntrada::with('itens')->find($this->notaEntradaId);
            if (!$nota) {
                return;
            }

            if (empty($nota->chave_acesso)) {
                $nota->update([
                    'fiscal_ultima_consulta_em' => now(),
                    'fiscal_erro_consulta'      => 'Nota sem chave de acesso — não é possível conciliar automaticamente.',
                ]);
                return;
            }

            $provider = $providerManager->forTenant();
            if (!$provider instanceof ConsultaNotaTerceiroProvider) {
                $nota->update([
                    'fiscal_ultima_consulta_em' => now(),
                    'fiscal_erro_consulta'      => 'O motor fiscal desta oficina ainda não suporta essa consulta.',
                ]);
                return;
            }

            $resultado = $provider->consultarNotaRecebida($nota->chave_acesso);
            $this->aplicarResultado($nota, $resultado, $fiscalService);
        } finally {
            TenancyContext::clear();
        }
    }

    private function aplicarResultado(NotaEntrada $nota, ConsultaNotaTerceiroResultado $resultado, ProdutoFiscalService $fiscalService): void
    {
        if ($resultado->status !== 'COMPLETA') {
            $nota->update([
                'fiscal_ultima_consulta_em' => now(),
                'fiscal_erro_consulta'      => $resultado->mensagemErro
                    ?? match ($resultado->status) {
                        'AGUARDANDO_MANIFESTACAO' => 'Ciência da operação enviada — tente novamente em instantes.',
                        'NAO_ENCONTRADA'          => 'Nota não encontrada no provedor ainda.',
                        default                    => 'Falha ao consultar a nota.',
                    },
            ]);
            return;
        }

        $itensFrescos = $resultado->dados['itens'] ?? [];
        $todosCompletos = true;

        foreach ($nota->itens as $item) {
            $produto = Produto::find($item->produto_id);
            if (!$produto) {
                continue;
            }

            $itemFresco = $this->casarItem($item, $itensFrescos);
            if ($itemFresco === null) {
                $todosCompletos = $todosCompletos && ($produto->ncm !== null && $produto->tributacao_icms !== null);
                continue;
            }

            $fiscalService->aplicarDoXml($produto, $itemFresco, $nota->id);
            $produto->refresh();

            if ($produto->ncm === null || $produto->tributacao_icms === null) {
                $todosCompletos = false;
            }
        }

        $nota->update([
            'fiscal_ultima_consulta_em' => now(),
            'fiscal_erro_consulta'      => null,
            'fiscal_conferida_em'       => $todosCompletos ? now() : null,
        ]);
    }

    /**
     * Casa um NotaEntradaItem já salvo com o item fresco correspondente na
     * resposta do provedor. Por código de barras primeiro (chave exata);
     * se o item salvo não tinha código de barras, cai pra descrição igual.
     * Sem match seguro, devolve null — o item não é atualizado (não
     * quebra a nota inteira, só fica sem conciliar esse item específico).
     */
    private function casarItem($itemSalvo, array $itensFrescos): ?array
    {
        if ($itemSalvo->codigo_barras_xml !== null) {
            foreach ($itensFrescos as $fresco) {
                if (($fresco['codigo_barras'] ?? null) === $itemSalvo->codigo_barras_xml) {
                    return $fresco;
                }
            }
            return null;
        }

        foreach ($itensFrescos as $fresco) {
            if (($fresco['descricao'] ?? null) === $itemSalvo->descricao_xml) {
                return $fresco;
            }
        }
        return null;
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ConciliarFiscalNotaEntradaJob falhou: ' . $e->getMessage(), ['nota_entrada_id' => $this->notaEntradaId]);
    }
}
```

- [ ] **Step 1: Write the failing tests**

```php
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
```

- [ ] **Step 2: Run to verify RED** — `DB_HOST=127.0.0.1 DB_PORT=<tunel> php artisan test tests/Unit/Fiscal/ConciliarFiscalNotaEntradaJobTest.php`
  (este teste usa `RefreshDatabase`, então tecnicamente é Feature apesar do namespace `Tests\Unit` —
  mantenha o `use RefreshDatabase` mesmo assim, é o mesmo padrão que `SpedyProviderTest`/etc. já usam
  quando precisam de Eloquent real. Sem Postgres local: rode via túnel SSH se disponível, senão
  documente que não pôde rodar e siga pra implementação, confiando na leitura cuidadosa do código.)
- [ ] **Step 3: Implementar** o Job (código já dado acima).
- [ ] **Step 4: Run to verify GREEN.**
- [ ] **Step 5: Commit**

```bash
git add backend/app/Jobs/ConciliarFiscalNotaEntradaJob.php backend/tests/Unit/Fiscal/ConciliarFiscalNotaEntradaJobTest.php
git commit -m "feat(fiscal): job de conciliacao fiscal de nota de entrada, nunca mexe em estoque"
```

---

### Task 3: Endpoints — disparar conciliação (individual e em lote)

**Files:**
- Modify: `backend/app/Http/Controllers/EntradaNfController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Http/Resources/NotaEntradaResource.php`
- Test: `backend/tests/Feature/Fiscal/ConciliacaoFiscalEntradaNfTest.php`

**Interfaces:**
- Produces: `POST entradas-nf/{id}/conciliar` (dispara 1 job), `POST entradas-nf/conciliar-pendentes`
  (dispara 1 job por nota elegível — tem `chave_acesso`, não tem `fiscal_conferida_em`), ambos 202.
  `NotaEntradaResource` ganha `fiscal_conferida_em`, `fiscal_ultima_consulta_em`, `fiscal_erro_consulta`
  (formatados como as outras datas do resource) + `status_fiscal` computado
  (`'CONFERIDA'|'PENDENTE'|'ERRO'|'SEM_CHAVE'`).

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\NotaEntrada;
use App\Models\Oficina;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConciliacaoFiscalEntradaNfTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): array
    {
        $oficina = Oficina::create([
            'nome' => 'Oficina Teste', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
        ]);
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $oficina->id,
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        return [$user->createToken('t')->plainTextToken, $oficina];
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        parent::tearDown();
    }

    public function test_conciliar_uma_nota_despacha_o_job(): void
    {
        Bus::fake();
        [$token, $oficina] = $this->loginAdmin();
        $nota = NotaEntrada::create(['oficina_id' => $oficina->id, 'chave_acesso' => str_repeat('1', 44), 'valor_total' => 10]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/entradas-nf/{$nota->id}/conciliar")
            ->assertStatus(202);

        Bus::assertDispatched(\App\Jobs\ConciliarFiscalNotaEntradaJob::class);
    }

    public function test_conciliar_pendentes_despacha_1_job_por_nota_elegivel(): void
    {
        Bus::fake();
        [$token, $oficina] = $this->loginAdmin();
        NotaEntrada::create(['oficina_id' => $oficina->id, 'chave_acesso' => str_repeat('1', 44), 'valor_total' => 10]);
        NotaEntrada::create(['oficina_id' => $oficina->id, 'chave_acesso' => str_repeat('2', 44), 'valor_total' => 10, 'fiscal_conferida_em' => now()]);
        NotaEntrada::create(['oficina_id' => $oficina->id, 'chave_acesso' => null, 'valor_total' => 10]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/conciliar-pendentes')
            ->assertStatus(202)
            ->assertJsonPath('notas_enfileiradas', 1);

        Bus::assertDispatchedTimes(\App\Jobs\ConciliarFiscalNotaEntradaJob::class, 1);
    }
}
```

- [ ] **Step 2: Run RED.**
- [ ] **Step 3: Implementar.** Em `EntradaNfController.php`:

```php
public function conciliar(string $id): JsonResponse
{
    $nota = NotaEntrada::findOrFail($id);
    \App\Jobs\ConciliarFiscalNotaEntradaJob::dispatch($nota->id, (string) TenancyContext::get(), $this->slugAtual());
    return response()->json(['message' => 'Conciliação enfileirada.'], 202);
}

public function conciliarPendentes(): JsonResponse
{
    $notas = NotaEntrada::whereNotNull('chave_acesso')->whereNull('fiscal_conferida_em')->get();
    foreach ($notas as $nota) {
        \App\Jobs\ConciliarFiscalNotaEntradaJob::dispatch($nota->id, (string) TenancyContext::get(), $this->slugAtual());
    }
    return response()->json(['message' => 'Conciliação enfileirada.', 'notas_enfileiradas' => $notas->count()], 202);
}

private function slugAtual(): string
{
    return \App\Models\Oficina::find(TenancyContext::get())?->slug ?? '';
}
```

Adicionar `use App\Tenancy\TenancyContext;` no topo se ainda não importado.

Em `routes/api.php`, no mesmo grupo `role:ADMIN,ATENDENTE` de `entradas-nf/consultar`:

```php
Route::post('entradas-nf/conciliar-pendentes', [EntradaNfController::class, 'conciliarPendentes']);
Route::post('entradas-nf/{id}/conciliar', [EntradaNfController::class, 'conciliar']);
```

**Atenção de ordem**: `conciliar-pendentes` precisa vir ANTES de `{id}/conciliar` no arquivo,
mesmo motivo já documentado pra `entradas-nf/recebidas` vs `entradas-nf/{id}`.

Em `NotaEntradaResource.php`, acrescentar ao array:

```php
'fiscal_conferida_em'       => $this->fiscal_conferida_em?->format('d/m/Y H:i'),
'fiscal_ultima_consulta_em' => $this->fiscal_ultima_consulta_em?->format('d/m/Y H:i'),
'fiscal_erro_consulta'      => $this->fiscal_erro_consulta,
'status_fiscal'             => match (true) {
    empty($this->chave_acesso)          => 'SEM_CHAVE',
    $this->fiscal_conferida_em !== null => 'CONFERIDA',
    $this->fiscal_erro_consulta !== null => 'ERRO',
    default                              => 'PENDENTE',
},
```

- [ ] **Step 4: Run GREEN.**
- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/EntradaNfController.php backend/routes/api.php backend/app/Http/Resources/NotaEntradaResource.php backend/tests/Feature/Fiscal/ConciliacaoFiscalEntradaNfTest.php
git commit -m "feat(fiscal): endpoints de conciliacao fiscal (individual e em lote)"
```

---

### Task 4: Frontend — estender "Histórico de Entrada de NF"

**Files:**
- Modify: `frontend/app/(dashboard)/produtos/entrada-nf/historico/page.tsx`

- [ ] **Step 1**: adicionar ao tipo `NotaEntradaListItem`:
```ts
status_fiscal: 'CONFERIDA' | 'PENDENTE' | 'ERRO' | 'SEM_CHAVE'
fiscal_erro_consulta: string | null
```
- [ ] **Step 2**: adicionar uma coluna "Status Fiscal" na tabela (pill colorido:
  verde=CONFERIDA, âmbar=PENDENTE, vermelho=ERRO com tooltip do `fiscal_erro_consulta`,
  cinza/muted=SEM_CHAVE) + botão "Conciliar" por linha (desabilitado se `SEM_CHAVE`),
  chamando `POST /entradas-nf/{id}/conciliar` e mostrando toast "Enfileirado."
- [ ] **Step 3**: botão "Conciliar todas pendentes" no topo da tela, chamando
  `POST /entradas-nf/conciliar-pendentes`, toast com `notas_enfileiradas`.
- [ ] **Step 4**: `npx tsc --noEmit` limpo.
- [ ] **Step 5: Commit**

```bash
git add "frontend/app/(dashboard)/produtos/entrada-nf/historico/page.tsx"
git commit -m "feat(entrada-nf): tela de historico ganha status fiscal e conciliacao"
```

---

### Task 5: Atualizar `PROGRESSO.md` e `TAREFAS.md`

Registrar a rodada, marcar item concluído em `TAREFAS.md`, commit.
