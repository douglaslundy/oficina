# Etapa B — Refactor NotaFiscalData + NF-e no Spedy/Focus — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao MecânicaPro a capacidade de emitir NF-e (venda de mercadoria/peça) via Spedy e Focus NFe, além da NFS-e que já emitem, mantendo o comportamento de NFS-e 100% intocado.

**Architecture:** `NotaFiscalData` ganha campos aditivos (`modelo`, `itens[]`); um novo `CfopSaidaResolver` e `TributacaoIcmsSaidaResolver` derivam CFOP e CST/CSOSN de saída a partir de UF/origem/tributação; a interface `FiscalProvider` não muda de assinatura — cada provider ramifica internamente por `$nota->modelo` dentro de `emitir()`. Uma nova tabela `notas_fiscais_itens` (mesmo padrão de `os_itens`) guarda os itens quando `modelo=NF-e`; NFS-e continua usando as colunas flat existentes.

**Tech Stack:** Laravel 11 / PHP 8.3 (`declare(strict_types=1)` em todo arquivo PHP), Next.js 14 + TypeScript strict (frontend), PostgreSQL 16, PHPUnit.

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo ou modificado.
- Interface `App\Services\Fiscal\Contracts\FiscalProvider` **não pode mudar de assinatura** — trava herdada do spec já aprovado da Etapa C (NFePHP), que assume os métodos atuais.
- `NotaFiscalData` só recebe campos **aditivos** — nenhum campo existente muda de nome ou tipo. NFS-e não pode regredir.
- Nunca cair num `default`/fallback silencioso em decisão fiscal (CFOP, CST/CSOSN, status de emissão) — combinação não coberta lança exceção ou loga warning, nunca um valor chutado.
- Sem Postgres/Docker disponível localmente nesta máquina de desenvolvimento — Feature tests (que precisam de banco) devem ser **escritos** mas não podem ser **executados** aqui; só testes `tests/Unit` (lógica pura, sem `RefreshDatabase`) rodam localmente. Nunca rodar `php artisan test` contra o banco de produção (`RefreshDatabase` dropa o banco).
- TypeScript: sem `any` explícito no frontend.
- Este é um monorepo com `frontend/AGENTS.md` avisando que o Next.js instalado tem breaking changes vs. a versão de treinamento — checar `frontend/node_modules/next/dist/docs/` antes de qualquer coisa específica do framework (App Router, etc.) que pareça não bater com o esperado.
- Nunca commitar com `--no-verify`. Rodar `php -l` em todo arquivo PHP tocado e `npx tsc --noEmit` + `npm run build` no frontend antes de considerar uma task pronta.

---

### Task 1: `CfopSaidaResolver`

**Files:**
- Create: `backend/app/Services/Fiscal/CfopSaidaResolver.php`
- Test: `backend/tests/Unit/Fiscal/CfopSaidaResolverTest.php`

**Interfaces:**
- Produces: `App\Services\Fiscal\CfopSaidaResolver::resolver(string $ufOrigem, string $ufDestino, bool $substituicaoTributaria): string` — retorna o CFOP de 4 dígitos, ou lança `\InvalidArgumentException` se as UFs forem inválidas (string vazia ou não 2 letras).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\CfopSaidaResolver;
use PHPUnit\Framework\TestCase;

class CfopSaidaResolverTest extends TestCase
{
    public function test_dentro_do_estado_normal(): void
    {
        $this->assertSame('5102', CfopSaidaResolver::resolver('MG', 'MG', false));
    }

    public function test_dentro_do_estado_com_st(): void
    {
        $this->assertSame('5405', CfopSaidaResolver::resolver('MG', 'MG', true));
    }

    public function test_fora_do_estado_normal(): void
    {
        $this->assertSame('6102', CfopSaidaResolver::resolver('MG', 'SP', false));
    }

    public function test_fora_do_estado_com_st(): void
    {
        $this->assertSame('6404', CfopSaidaResolver::resolver('MG', 'SP', true));
    }

    public function test_uf_vazia_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CfopSaidaResolver::resolver('', 'SP', false);
    }

    public function test_uf_invalida_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CfopSaidaResolver::resolver('MG', 'XX', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=CfopSaidaResolverTest`
Expected: FAIL — `Class "App\Services\Fiscal\CfopSaidaResolver" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * CFOP de saída é da OPERAÇÃO, não da mercadoria (mesma regra que a Etapa A
 * já aplicou pra entrada) — depende de UF origem/destino e se o item tem
 * ICMS já recolhido por substituição tributária.
 *
 * Fonte: Convênio s/nº de 15/12/1970 (Tabela CFOP). Combinação fora das 4
 * linhas cobertas lança exceção — nunca um CFOP chutado.
 */
final class CfopSaidaResolver
{
    private const UFS_VALIDAS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
    ];

    public static function resolver(string $ufOrigem, string $ufDestino, bool $substituicaoTributaria): string
    {
        $ufOrigem  = strtoupper($ufOrigem);
        $ufDestino = strtoupper($ufDestino);

        if (!in_array($ufOrigem, self::UFS_VALIDAS, true) || !in_array($ufDestino, self::UFS_VALIDAS, true)) {
            throw new \InvalidArgumentException("UF inválida para cálculo de CFOP: origem={$ufOrigem} destino={$ufDestino}");
        }

        $dentroDoEstado = $ufOrigem === $ufDestino;

        return match (true) {
            $dentroDoEstado && !$substituicaoTributaria  => '5102',
            $dentroDoEstado && $substituicaoTributaria   => '5405',
            !$dentroDoEstado && !$substituicaoTributaria => '6102',
            !$dentroDoEstado && $substituicaoTributaria  => '6404',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=CfopSaidaResolverTest`
Expected: PASS (6 testes)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/CfopSaidaResolver.php backend/tests/Unit/Fiscal/CfopSaidaResolverTest.php
git commit -m "feat(fiscal): CfopSaidaResolver deriva CFOP de venda por UF e substituicao tributaria"
```

---

### Task 2: `TributacaoIcmsSaidaResolver` (CST/CSOSN)

**Contexto:** produtos (Etapa A) só guardam a classificação simplificada `tributacao_icms` (`NORMAL`|`ST`) — não o código CST/CSOSN exato, que depende também do regime tributário da oficina (`Configuracao.regime_tributario`, string livre tipo "Simples Nacional"/"Lucro Presumido"/"Lucro Real" — mesmo padrão já usado em `FocusNfeProvider::mapRegime()`).

Base legal confirmada nesta sessão (mesmo rigor da Etapa A pra CST/CSOSN):
- Simples Nacional + normal → **CSOSN 102** (tributada pelo Simples Nacional sem permissão de crédito — uso padrão em venda a consumidor final).
- Simples Nacional + ST → **CSOSN 500** (já verificado na Etapa A).
- Regime Normal (Presumido/Real) + normal → **CST 00** (tributada integralmente).
- Regime Normal + ST → **CST 60** (já verificado na Etapa A).

**Files:**
- Create: `backend/app/Services/Fiscal/TributacaoIcmsSaidaResolver.php`
- Test: `backend/tests/Unit/Fiscal/TributacaoIcmsSaidaResolverTest.php`

**Interfaces:**
- Produces: `App\Services\Fiscal\TributacaoIcmsSaidaResolver::resolver(string $regimeTributario, string $tributacaoIcms): string` — retorna o código CST/CSOSN. `$tributacaoIcms` deve ser `'NORMAL'` ou `'ST'`; qualquer outro valor lança `\InvalidArgumentException`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\TributacaoIcmsSaidaResolver;
use PHPUnit\Framework\TestCase;

class TributacaoIcmsSaidaResolverTest extends TestCase
{
    public function test_simples_nacional_normal(): void
    {
        $this->assertSame('102', TributacaoIcmsSaidaResolver::resolver('Simples Nacional', 'NORMAL'));
    }

    public function test_simples_nacional_st(): void
    {
        $this->assertSame('500', TributacaoIcmsSaidaResolver::resolver('Simples Nacional', 'ST'));
    }

    public function test_lucro_presumido_normal(): void
    {
        $this->assertSame('00', TributacaoIcmsSaidaResolver::resolver('Lucro Presumido', 'NORMAL'));
    }

    public function test_lucro_real_st(): void
    {
        $this->assertSame('60', TributacaoIcmsSaidaResolver::resolver('Lucro Real', 'ST'));
    }

    public function test_tributacao_invalida_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TributacaoIcmsSaidaResolver::resolver('Simples Nacional', 'ISENTO');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=TributacaoIcmsSaidaResolverTest`
Expected: FAIL — classe não existe

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Deriva o código CST (Regime Normal) ou CSOSN (Simples Nacional) de saída
 * a partir da classificação simplificada NORMAL/ST já gravada no produto
 * (Etapa A) + o regime tributário da oficina. Base legal confirmada:
 * CSOSN 102/500 (Ajuste SINIEF 03/2010, Anexo Único, Tabela B), CST 00/60
 * (Tabela B do Anexo do Convênio SINIEF s/nº de 15/12/1970) — mesmas fontes
 * já usadas pela Etapa A.
 */
final class TributacaoIcmsSaidaResolver
{
    public static function resolver(string $regimeTributario, string $tributacaoIcms): string
    {
        if (!in_array($tributacaoIcms, ['NORMAL', 'ST'], true)) {
            throw new \InvalidArgumentException("tributacao_icms inválida: {$tributacaoIcms}");
        }

        $simplesNacional = str_contains(strtolower($regimeTributario), 'simples');
        $st              = $tributacaoIcms === 'ST';

        return match (true) {
            $simplesNacional && !$st  => '102',
            $simplesNacional && $st   => '500',
            !$simplesNacional && !$st => '00',
            !$simplesNacional && $st  => '60',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=TributacaoIcmsSaidaResolverTest`
Expected: PASS (5 testes)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/TributacaoIcmsSaidaResolver.php backend/tests/Unit/Fiscal/TributacaoIcmsSaidaResolverTest.php
git commit -m "feat(fiscal): TributacaoIcmsSaidaResolver deriva CST/CSOSN por regime tributario"
```

---

### Task 3: `NotaFiscalData` — campos aditivos `modelo` e `itens`

**Files:**
- Modify: `backend/app/Services/Fiscal/Data/NotaFiscalData.php`
- Test: `backend/tests/Unit/Fiscal/NotaFiscalDataTest.php` (novo)

**Interfaces:**
- Produces: `NotaFiscalData` com dois parâmetros novos, ambos com default (não quebra nenhum call site existente): `string $modelo = 'NFSE'`, `array $itens = []`.
- Consumido pelos Tasks 6/7 (providers) e Task 9 (`NfeService`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\NotaFiscalData;
use PHPUnit\Framework\TestCase;

class NotaFiscalDataTest extends TestCase
{
    public function test_construcao_nfse_sem_novos_campos_mantem_default(): void
    {
        $nota = new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente'],
            descricao: 'Serviço',
            valorServicos: 100.0,
            aliquotaIss: 5.0,
            issRetido: false,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços',
            referenciaExterna: 'ref-1',
        );

        $this->assertSame('NFSE', $nota->modelo);
        $this->assertSame([], $nota->itens);
    }

    public function test_construcao_nfe_com_itens(): void
    {
        $itens = [[
            'produto_id' => 'prod-1', 'descricao' => 'Filtro de óleo',
            'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
            'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
            'quantidade' => 2, 'valor_unitario' => 35.0,
        ]];

        $nota = new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente'],
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'ref-2',
            modelo: 'NFE',
            itens: $itens,
        );

        $this->assertSame('NFE', $nota->modelo);
        $this->assertSame($itens, $nota->itens);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=NotaFiscalDataTest`
Expected: FAIL — `Unknown named parameter $modelo`

- [ ] **Step 3: Write minimal implementation**

Modifique `backend/app/Services/Fiscal/Data/NotaFiscalData.php` para:

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class NotaFiscalData
{
    /**
     * @param array<int, array{
     *   produto_id: string, descricao: string, ncm: string, cfop: string,
     *   origem: int, tributacao_icms: string, cst_csosn: string,
     *   quantidade: float, valor_unitario: float,
     * }> $itens Só populado quando $modelo === 'NFE'.
     */
    public function __construct(
        public readonly string $tipo,
        public readonly array $tomador,
        public readonly string $descricao,
        public readonly float $valorServicos,
        public readonly float $aliquotaIss,
        public readonly bool $issRetido,
        public readonly string $codigoServicoFederal,
        public readonly string $codigoServicoMunicipal,
        public readonly string $naturezaOperacao,
        public readonly string $referenciaExterna,
        public readonly string $modelo = 'NFSE',
        public readonly array $itens = [],
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=NotaFiscalDataTest`
Expected: PASS (2 testes). Rode também `php artisan test --filter=FocusNfeProviderTest --filter=SpedyProviderTest` pra confirmar que nenhum call site existente quebrou.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Data/NotaFiscalData.php backend/tests/Unit/Fiscal/NotaFiscalDataTest.php
git commit -m "feat(fiscal): NotaFiscalData ganha modelo e itens de forma aditiva"
```

---

### Task 4: Tabela `notas_fiscais_itens` + model `NotaFiscalItem`

**Files:**
- Create: `backend/database/migrations/2026_08_02_000001_create_notas_fiscais_itens_table.php`
- Create: `backend/app/Models/NotaFiscalItem.php`
- Modify: `backend/app/Models/NotaFiscal.php:24-31,52-53` (fillable + relação `itens()`)

**Interfaces:**
- Produces: model `App\Models\NotaFiscalItem` com fillable `nota_fiscal_id, produto_id, descricao, ncm, cfop, origem, tributacao_icms, cst_csosn, quantidade, valor_unitario, oficina_id`; relação `NotaFiscal::itens(): HasMany`.

- [ ] **Step 1: Criar a migration**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notas_fiscais_itens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('nota_fiscal_id')->references('id')->on('notas_fiscais')->onDelete('cascade');
            $table->uuid('produto_id')->nullable();
            $table->uuid('oficina_id')->nullable();
            $table->string('descricao', 200);
            $table->string('ncm', 8)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable();
            $table->string('cst_csosn', 4)->nullable();
            $table->decimal('quantidade', 8, 2)->default(1);
            $table->decimal('valor_unitario', 10, 2);
            $table->decimal('valor_total', 10, 2)->storedAs('quantidade * valor_unitario');
        });
    }

    public function down(): void { Schema::dropIfExists('notas_fiscais_itens'); }
};
```

Nomeie o arquivo `2026_08_02_000001_create_notas_fiscais_itens_table.php` (o timestamp precisa ser posterior a todas as migrations existentes — confira com `ls backend/database/migrations | tail -5` antes de escolher o prefixo final).

- [ ] **Step 2: Criar o model**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotaFiscalItem extends Model
{
    use HasTenantScope;

    protected $table = 'notas_fiscais_itens';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nota_fiscal_id', 'produto_id', 'oficina_id', 'descricao',
        'ncm', 'cfop', 'origem', 'tributacao_icms', 'cst_csosn',
        'quantidade', 'valor_unitario',
    ];

    protected $casts = [
        'origem'         => 'integer',
        'quantidade'     => 'float',
        'valor_unitario' => 'float',
        'valor_total'    => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function produto(): BelongsTo { return $this->belongsTo(Produto::class, 'produto_id'); }
}
```

- [ ] **Step 3: Adicionar a relação em `NotaFiscal`**

Em `backend/app/Models/NotaFiscal.php`, adicione ao final da classe (junto de `cliente()`/`ordemServico()`):

```php
    public function itens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotaFiscalItem::class, 'nota_fiscal_id');
    }
```

- [ ] **Step 4: Verificar sintaxe**

Run: `cd backend && php -l database/migrations/2026_08_02_000001_create_notas_fiscais_itens_table.php && php -l app/Models/NotaFiscalItem.php && php -l app/Models/NotaFiscal.php`
Expected: `No syntax errors detected` nos 3 arquivos.

Não há como rodar a migration nesta máquina (sem Postgres local) — a validação real acontece no Task 12 (feature tests, CI/banco dedicado) e na validação manual pós-deploy.

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_08_02_000001_create_notas_fiscais_itens_table.php backend/app/Models/NotaFiscalItem.php backend/app/Models/NotaFiscal.php
git commit -m "feat(fiscal): tabela notas_fiscais_itens e model NotaFiscalItem"
```

---

### Task 5: Corrigir defeitos #2, #3 e #5 nos dois provedores (NFS-e existente)

**Contexto:** três dos cinco defeitos catalogados na rodada 16 são independentes de NF-e e afetam o fluxo de NFS-e que já está em produção — corrija-os isolados, protegidos pelos testes existentes, antes de empilhar a complexidade de NF-e em cima.

**Files:**
- Modify: `backend/app/Services/Fiscal/Providers/FocusNfeProvider.php:15-24,58-71,122-151,153-162`
- Modify: `backend/app/Services/Fiscal/Providers/SpedyProvider.php:13-20,50-63,120-152,154-162`
- Modify: `backend/app/Services/Fiscal/FiscalProviderManager.php:62-82`
- Modify: `backend/tests/Unit/Fiscal/FocusNfeProviderTest.php`
- Modify: `backend/tests/Unit/Fiscal/SpedyProviderTest.php`

**Interfaces:**
- Produces: construtor de `FocusNfeProvider` ganha `string $ambiente` como 3º parâmetro posicional (antes de `?string $emissorToken = null`). `FiscalProviderManager::build()` passa esse valor (já tem `$ambiente` disponível, só não repassava). **`SpedyProvider` não muda neste task** — código já lido nesta sessão confirma que ela não tem nenhuma lógica que infira ambiente por substring de URL nem que dependa de ambiente de nenhuma outra forma; adicionar o parâmetro lá seria um parâmetro morto (YAGNI). O defeito #2 é específico da Focus.

- [ ] **Step 1: Write the failing tests (defeito #2 — ambiente explícito)**

Em `backend/tests/Unit/Fiscal/FocusNfeProviderTest.php`, adicione:

```php
    public function test_ambiente_e_explicito_nao_inferido_por_url(): void
    {
        // URL de homologação, mas ambiente PRODUCAO passado explicitamente —
        // o provider deve confiar no parâmetro, não no substring da URL.
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'PRODUCAO', 'tok');
        $this->assertTrue($p->ambienteProducao());
    }
```

Não crie um teste equivalente em `SpedyProviderTest.php` — este defeito não existe na Spedy (ver nota em Interfaces acima).

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=FocusNfeProviderTest --filter=SpedyProviderTest`
Expected: FAIL — `ambienteProducao()` não existe como método público, e o construtor não aceita esse número de argumentos ainda.

- [ ] **Step 3: Implementar nos dois providers**

Em `FocusNfeProvider.php`, troque:

```php
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $masterToken,
        private readonly ?string $emissorToken = null,
    ) {}

    private function ambienteProducao(): bool
    {
        return str_contains($this->baseUrl, 'api.focusnfe.com.br');
    }
```

por:

```php
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $masterToken,
        private readonly string $ambiente,
        private readonly ?string $emissorToken = null,
    ) {}

    public function ambienteProducao(): bool
    {
        return $this->ambiente === 'PRODUCAO';
    }
```

**Não mexa em `SpedyProvider.php` neste step** — ver nota em Interfaces acima.

Em `FiscalProviderManager.php::build()`, troque:

```php
            return new FocusNfeProvider($baseUrl, $master, $emissorToken);
```
por
```php
            return new FocusNfeProvider($baseUrl, $master, $ambiente, $emissorToken);
```

A chamada de `new SpedyProvider(...)` logo abaixo, no mesmo método, **não muda**.

**Atenção:** isso muda a assinatura posicional do construtor da `FocusNfeProvider`. Busque TODOS os outros call sites antes de seguir:

Run: `cd backend && grep -rn "new FocusNfeProvider" app tests`

Atualize cada instanciação encontrada (inclusive as que já existem em `FocusNfeProviderTest.php` nos testes que não foram tocados neste step) pra incluir o novo parâmetro `$ambiente` na posição certa — use `'HOMOLOGACAO'` nos testes existentes que não estão testando ambiente especificamente.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=FocusNfeProviderTest --filter=SpedyProviderTest`
Expected: PASS em todos, incluindo os testes antigos que tiveram a chamada do construtor atualizada.

- [ ] **Step 5: Commit (defeito #2)**

```bash
git add backend/app/Services/Fiscal/Providers/FocusNfeProvider.php backend/app/Services/Fiscal/Providers/SpedyProvider.php backend/app/Services/Fiscal/FiscalProviderManager.php backend/tests/Unit/Fiscal/FocusNfeProviderTest.php backend/tests/Unit/Fiscal/SpedyProviderTest.php
git commit -m "fix(fiscal): ambiente de producao/homologacao explicito, nao inferido por substring de URL"
```

- [ ] **Step 6: Write failing test (defeito #3 — status desconhecido loga warning)**

Em `FocusNfeProviderTest.php`:

```php
    public function test_status_desconhecido_loga_warning(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::pattern('/status desconhecido/i'), \Mockery::any());

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $status = $p->mapStatus('status_nunca_visto_antes');

        $this->assertSame('PROCESSANDO', $status);
    }
```

Repita o equivalente em `SpedyProviderTest.php` usando um status inventado que não bata com nenhum `match()` existente.

- [ ] **Step 7: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=test_status_desconhecido_loga_warning`
Expected: FAIL — `Log::warning` nunca foi chamado.

- [ ] **Step 8: Implementar o log no `default` do `mapStatus()` dos dois providers**

Em `FocusNfeProvider::mapStatus()`, troque:

```php
    public function mapStatus(string $focusStatus): string
    {
        return match ($focusStatus) {
            'autorizado'              => 'AUTORIZADA',
            'cancelado'               => 'CANCELADA',
            'erro_autorizacao',
            'denegado'                => 'REJEITADA',
            default                   => 'PROCESSANDO', // processando_autorizacao
        };
    }
```

por:

```php
    public function mapStatus(string $focusStatus): string
    {
        return match ($focusStatus) {
            'autorizado'              => 'AUTORIZADA',
            'cancelado'               => 'CANCELADA',
            'erro_autorizacao',
            'denegado'                => 'REJEITADA',
            'processando_autorizacao' => 'PROCESSANDO',
            default                   => $this->statusDesconhecido($focusStatus),
        };
    }

    private function statusDesconhecido(string $status): string
    {
        \Illuminate\Support\Facades\Log::warning("Focus NFe: status desconhecido recebido, tratando como PROCESSANDO: {$status}");
        return 'PROCESSANDO';
    }
```

Aplique o mesmo padrão em `SpedyProvider::mapStatus()` (o `default` atual comenta `// enqueued, processing, etc.` — mantenha `enqueued`/`processing` explícitos no `match` como casos conhecidos que retornam `PROCESSANDO` sem log, e só logue quando cair fora de todos os casos conhecidos).

- [ ] **Step 9: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=FocusNfeProviderTest --filter=SpedyProviderTest`
Expected: PASS.

- [ ] **Step 10: Commit (defeito #3)**

```bash
git add backend/app/Services/Fiscal/Providers/FocusNfeProvider.php backend/app/Services/Fiscal/Providers/SpedyProvider.php backend/tests/Unit/Fiscal/FocusNfeProviderTest.php backend/tests/Unit/Fiscal/SpedyProviderTest.php
git commit -m "fix(fiscal): status desconhecido do provedor loga warning em vez de sumir no default"
```

- [ ] **Step 11: Write failing test (defeito #5 — naturezaOperacao usada no payload de NFS-e)**

Em `FocusNfeProviderTest.php::test_payload_nfse_usa_campos_focus`, adicione a asserção:

```php
        $this->assertSame('Prestação de Serviços', $payload['natureza_operacao']);
```

(a fixture `nota()` já tem `naturezaOperacao: 'Prestação de Serviços'`, não precisa mudar). Repita o equivalente em `SpedyProviderTest.php` pro campo correspondente na Spedy (confira o nome exato do campo esperado olhando a estrutura atual do `montarPayloadNfse()` da Spedy antes de escrever — provavelmente algo como `operationNature` ou similar dentro do padrão camelCase já usado pelos outros campos do payload da Spedy).

- [ ] **Step 12: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=test_payload_nfse_usa_campos_focus`
Expected: FAIL — chave `natureza_operacao` não existe no array retornado.

- [ ] **Step 13: Implementar**

Em `FocusNfeProvider::montarPayloadNfse()`, adicione ao array retornado (nível raiz, junto de `data_emissao`):

```php
            'natureza_operacao' => $n->naturezaOperacao,
```

Em `SpedyProvider::montarPayloadNfse()`, adicione o campo equivalente no nível raiz do array (mesma convenção camelCase dos outros campos do payload da Spedy).

- [ ] **Step 14: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=FocusNfeProviderTest --filter=SpedyProviderTest`
Expected: PASS.

- [ ] **Step 15: Commit (defeito #5)**

```bash
git add backend/app/Services/Fiscal/Providers/FocusNfeProvider.php backend/app/Services/Fiscal/Providers/SpedyProvider.php backend/tests/Unit/Fiscal/FocusNfeProviderTest.php backend/tests/Unit/Fiscal/SpedyProviderTest.php
git commit -m "fix(fiscal): naturezaOperacao passa a ser enviada no payload de NFS-e"
```

---

### Task 6: `FocusNfeProvider` — emissão de NF-e + defeitos #1 e #4

**Contexto (confirmado nesta sessão via `doc.focusnfe.com.br/reference/emitir_nfe.md`):**
- Endpoint: `POST {baseUrl}/nfe?ref={referencia}`.
- Payload mínimo por item: `numero_item`, `codigo_produto`, `descricao`, `cfop`, `quantidade_comercial`, `valor_unitario_comercial`, `valor_bruto`, `codigo_ncm`, `icms_origem`, `icms_situacao_tributaria`, `unidade_comercial` (default `"UN"`).
- Campos obrigatórios no corpo: `natureza_operacao`, `data_emissao`, `tipo_documento` (sempre `1` = saída, aqui), `finalidade_emissao` (sempre `1` = normal, aqui).
- Resposta pode ser `201` (`autorizado`, síncrono) ou `202` (`processando_autorizacao`, assíncrono — **é o caso normal**, já coberto por `EmissaoResultado::processando()`).
- Resposta autorizada traz `chave_nfe`, `numero`. **Não confirmado nesta sessão se há um campo de protocolo de autorização SEFAZ distinto de `numero`** — a doc resumida não listou; trate como ausente por padrão (retorne `protocolo: null` se o campo não vier) em vez de reusar `numero` (é exatamente o defeito #4 que estamos corrigindo — nunca reintroduzir).

**Files:**
- Modify: `backend/app/Services/Fiscal/Providers/FocusNfeProvider.php`
- Modify: `backend/tests/Unit/Fiscal/FocusNfeProviderTest.php`

**Interfaces:**
- Consumes: `NotaFiscalData` com `modelo='NFE'` e `itens[]` (Task 3).
- Produces: `FocusNfeProvider::montarPayloadNfe(NotaFiscalData $n): array`, `FocusNfeProvider::emitirNfe(NotaFiscalData $nota): EmissaoResultado`. `emitir()` ramifica por `$nota->modelo`.

- [ ] **Step 1: Write the failing tests**

Em `FocusNfeProviderTest.php`, adicione uma fixture e os testes:

```php
    private function notaNfe(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'email' => 'c@x.com', 'cep' => '01310100', 'logradouro' => 'Av A',
                'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
                'uf' => 'SP', 'codigo_ibge' => '3550308',
            ],
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'os-456',
            modelo: 'NFE',
            itens: [[
                'produto_id' => 'prod-1', 'descricao' => 'Filtro de óleo',
                'ncm' => '84212300', 'cfop' => '6102', 'origem' => 0,
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '00',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
        );
    }

    public function test_payload_nfe_monta_itens_com_dados_fiscais(): void
    {
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $payload = $p->montarPayloadNfe($this->notaNfe());

        $this->assertSame('Venda de Mercadoria', $payload['natureza_operacao']);
        $this->assertSame(1, $payload['tipo_documento']);
        $this->assertSame(1, $payload['finalidade_emissao']);
        $this->assertCount(1, $payload['items']);
        $this->assertSame('84212300', $payload['items'][0]['codigo_ncm']);
        $this->assertSame('6102', $payload['items'][0]['cfop']);
        $this->assertSame(0, $payload['items'][0]['icms_origem']);
        $this->assertSame('00', $payload['items'][0]['icms_situacao_tributaria']);
        $this->assertSame(2.0, $payload['items'][0]['quantidade_comercial']);
        $this->assertSame(35.50, $payload['items'][0]['valor_unitario_comercial']);
        $this->assertSame(71.0, $payload['items'][0]['valor_bruto']);
    }

    public function test_emitir_nfe_processando(): void
    {
        Http::fake([
            '*/nfe?ref=os-456' => Http::response(['status' => 'processando_autorizacao'], 202),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->emitir($this->notaNfe());

        $this->assertSame('PROCESSANDO', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/nfe?ref=os-456'));
    }

    public function test_emitir_nfe_autorizada_baixa_xml_real_e_nao_reusa_numero_como_protocolo(): void
    {
        Http::fake([
            '*/nfe?ref=os-456' => Http::response([
                'status' => 'autorizado',
                'numero' => '999',
                'chave_nfe' => 'CHAVE123',
                'caminho_xml_nota_fiscal' => 'https://focus/xml/os-456.xml',
                'caminho_danfe' => 'https://focus/danfe/os-456.pdf',
            ], 201),
            'https://focus/xml/os-456.xml' => Http::response('<xml>conteudo real da nfe</xml>', 200),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->emitir($this->notaNfe());

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('999', $r->numero);
        $this->assertStringContainsString('<xml>conteudo real da nfe</xml>', $r->xml);
        $this->assertNotSame($r->numero, $r->protocolo);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=FocusNfeProviderTest`
Expected: FAIL — `montarPayloadNfe`/`emitirNfe` não existem, `emitir()` ainda só chama o fluxo de NFS-e.

- [ ] **Step 3: Implementar**

Em `FocusNfeProvider.php`, troque o método `emitir()` existente por um dispatcher e adicione os métodos novos:

```php
    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        return $nota->modelo === 'NFE' ? $this->emitirNfe($nota) : $this->emitirNfse($nota);
    }

    private function emitirNfse(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->post("{$this->baseUrl}/nfse?ref={$nota->referenciaExterna}", $this->montarPayloadNfse($nota));

        if ($resp->status() >= 400) {
            return EmissaoResultado::rejeitada(
                $resp->json('mensagem') ?? ($resp->json('erros.0.mensagem') ?? 'Erro na emissão (Focus).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna);
    }

    private function emitirNfe(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->post("{$this->baseUrl}/nfe?ref={$nota->referenciaExterna}", $this->montarPayloadNfe($nota));

        if ($resp->status() >= 400) {
            return EmissaoResultado::rejeitada(
                $resp->json('mensagem') ?? ($resp->json('erros.0.mensagem') ?? 'Erro na emissão de NF-e (Focus).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoNfeDe($resp->json(), $nota->referenciaExterna);
    }

    public function montarPayloadNfe(NotaFiscalData $n): array
    {
        $docTomador = preg_replace('/\D/', '', $n->tomador['cpf_cnpj']) ?? '';
        $chaveDoc   = strlen($docTomador) > 11 ? 'cnpj_destinatario' : 'cpf_destinatario';

        return [
            'natureza_operacao'  => $n->naturezaOperacao,
            'data_emissao'       => date('Y-m-d'),
            'tipo_documento'     => 1, // saída
            'finalidade_emissao' => 1, // normal
            'nome_destinatario'  => $n->tomador['nome'],
            $chaveDoc            => $docTomador,
            'logradouro_destinatario'   => $n->tomador['logradouro'] ?? '',
            'numero_destinatario'       => $n->tomador['numero'] ?? 'S/N',
            'bairro_destinatario'       => $n->tomador['bairro'] ?? '',
            'municipio_destinatario'    => $n->tomador['cidade'] ?? '',
            'uf_destinatario'           => $n->tomador['uf'] ?? '',
            'cep_destinatario'          => preg_replace('/\D/', '', $n->tomador['cep'] ?? ''),
            'items' => array_map(fn (int $i, array $item) => [
                'numero_item'               => $i + 1,
                'codigo_produto'            => $item['produto_id'],
                'descricao'                 => $item['descricao'],
                'cfop'                      => $item['cfop'],
                'codigo_ncm'                => $item['ncm'],
                'unidade_comercial'         => 'UN',
                'quantidade_comercial'      => (float) $item['quantidade'],
                'valor_unitario_comercial'  => (float) $item['valor_unitario'],
                'valor_bruto'               => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                'icms_origem'               => (int) $item['origem'],
                'icms_situacao_tributaria'  => $item['cst_csosn'],
            ], array_keys($n->itens), $n->itens),
        ];
    }

    private function resultadoNfeDe(array $json, ?string $ref): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'processando_autorizacao'));

        if ($status === 'REJEITADA') {
            return EmissaoResultado::rejeitada(
                $json['mensagem'] ?? ($json['erros'][0]['mensagem'] ?? 'Rejeitada pela SEFAZ.'),
                $ref,
            );
        }
        if ($status === 'PROCESSANDO') {
            return EmissaoResultado::processando($ref);
        }
        if ($status === 'CANCELADA') {
            return EmissaoResultado::cancelada($ref);
        }

        $xmlUrl = $json['caminho_xml_nota_fiscal'] ?? null;
        $xmlConteudo = $xmlUrl ? (Http::get($xmlUrl)->body() ?: null) : null;

        return EmissaoResultado::autorizada(
            chave: $json['chave_nfe'] ?? null,
            protocolo: isset($json['protocolo']) ? (string) $json['protocolo'] : null, // NÃO reusa "numero" (defeito #4)
            numero: isset($json['numero']) ? (string) $json['numero'] : null,
            xml: $xmlConteudo,
            pdfUrl: $json['caminho_danfe'] ?? null,
            ref: $ref,
        );
    }
```

Note que `resultadoNfeDe()` já corrige o defeito #1 pra NF-e (baixa o XML real via `Http::get($xmlUrl)->body()` em vez de guardar o path).

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=FocusNfeProviderTest`
Expected: PASS em todos, incluindo os testes de NFS-e existentes (nenhum deve ter regredido).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/FocusNfeProvider.php backend/tests/Unit/Fiscal/FocusNfeProviderTest.php
git commit -m "feat(fiscal): FocusNfeProvider emite NF-e (POST /nfe) com XML real e protocolo distinto de numero"
```

---

### Task 7: `SpedyProvider` — emissão de NF-e (endpoint a confirmar em sandbox)

**⚠️ Atenção antes de implementar este task:** a doc técnica da Spedy (`docs.spedy.com.br`) bloqueou acesso automatizado (403) nesta sessão de brainstorming — o schema exato do endpoint de NF-e **não foi confirmado**. A central de ajuda (`ajuda.spedy.com.br`) e materiais comerciais confirmam que a Spedy suporta NF-e, e o padrão de nomenclatura da API já observado (`POST /service-invoices` pra NFS-e, JSON camelCase próprio, não nomes de tag SEFAZ) sugere um endpoint irmão do tipo `POST /product-invoices` — **isso é uma hipótese, não um fato confirmado**.

Antes de escrever o Step 3 deste task, o implementador **precisa**:
1. Acessar `docs.spedy.com.br` autenticado (ou pedir ao usuário as credenciais/portal) e localizar o endpoint real de emissão de NF-e, seus campos obrigatórios de item (nome exato dos campos de NCM/CFOP/origem/CST-CSOSN/quantidade/valor) e o formato de resposta (incluindo se existe um campo de protocolo distinto de número).
2. Se não for possível confirmar a doc, testar diretamente contra o sandbox da Spedy (`{sandbox_url}/product-invoices` como primeira tentativa) com uma emissão de teste e documentar o payload de resposta real recebido.
3. Só então escrever `montarPayloadNfe()`/`emitirNfe()` com os nomes de campo confirmados — **não adivinhe nomes de campo pra emissão fiscal real**, é exatamente a mesma disciplina que gerou o `CfopSaidaResolver` nunca cair num default silencioso.

**Files:**
- Modify: `backend/app/Services/Fiscal/Providers/SpedyProvider.php`
- Modify: `backend/tests/Unit/Fiscal/SpedyProviderTest.php`

**Interfaces:**
- Consumes: `NotaFiscalData` com `modelo='NFE'` e `itens[]` (Task 3) — mesmo formato de item que o Task 6 já consome.
- Produces: `SpedyProvider::montarPayloadNfe(NotaFiscalData $n): array`, `SpedyProvider::emitirNfe(NotaFiscalData $nota): EmissaoResultado`. `emitir()` ramifica por `$nota->modelo`, mesmo padrão do Task 6.

- [ ] **Step 1: Write the failing tests (estrutura mínima — nomes de campo podem precisar de ajuste após a confirmação do Step 3)**

```php
    private function notaNfe(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'email' => 'c@x.com', 'cep' => '01310100', 'logradouro' => 'Av A',
                'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
                'uf' => 'SP', 'codigo_ibge' => '3550308',
            ],
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'os-789',
            modelo: 'NFE',
            itens: [[
                'produto_id' => 'prod-1', 'descricao' => 'Filtro de óleo',
                'ncm' => '84212300', 'cfop' => '6102', 'origem' => 0,
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '00',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
        );
    }

    public function test_payload_nfe_monta_itens_com_dados_fiscais(): void
    {
        $p = new SpedyProvider('https://sandbox.spedy.com.br', 'master', 'tok', 'ext-1');
        $payload = $p->montarPayloadNfe($this->notaNfe());

        // Confirme os nomes de campo exatos contra a doc/sandbox real da Spedy
        // (Step 1 da seção de atenção acima) antes de travar estas asserções.
        $this->assertNotEmpty($payload);
    }

    public function test_emitir_nfe_processando(): void
    {
        Http::fake([
            '*/product-invoices' => Http::response(['status' => 'enqueued'], 202),
        ]);

        $p = new SpedyProvider('https://sandbox.spedy.com.br', 'master', 'tok', 'ext-1');
        $r = $p->emitir($this->notaNfe());

        $this->assertSame('PROCESSANDO', $r->status);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=SpedyProviderTest`
Expected: FAIL — `montarPayloadNfe`/`emitirNfe` não existem.

- [ ] **Step 3: Confirmar o schema real (ver seção de atenção acima) e implementar**

Depois de confirmar o endpoint/campos reais, siga exatamente o mesmo padrão do Task 6 (`emitir()` ramifica por `$nota->modelo`; `montarPayloadNfe()` monta o array de itens com NCM/CFOP/origem/CST-CSOSN; `resultadoNfeDe()` — ou o método equivalente já existente `resultadoDe()` estendido — não reusa `numero` como `protocolo` se a Spedy tiver um campo de protocolo distinto; baixa o XML real se a Spedy também devolver só um path). Ajuste as asserções dos testes do Step 1 pros nomes de campo confirmados.

Se a Spedy genuinamente não expõe um "protocolo" distinto de "número" (nem para NFS-e nem para NF-e), documente isso como comentário no código (`// Spedy não expõe protocolo distinto de número — confirmado contra doc/sandbox em <data>`) em vez de deixar ambíguo se foi verificado ou não.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=SpedyProviderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/SpedyProvider.php backend/tests/Unit/Fiscal/SpedyProviderTest.php
git commit -m "feat(fiscal): SpedyProvider emite NF-e"
```

---

### Task 8: `NotaFiscalController::store()` — deriva `modelo`, rejeita Misto, persiste itens

**Files:**
- Modify: `backend/app/Http/Controllers/NotaFiscalController.php:39-66`
- Test: `backend/tests/Feature/NotaFiscalNfeTest.php` (novo — precisa de Postgres, não roda localmente nesta máquina; escreva mesmo assim)

**Interfaces:**
- Consumes: `App\Models\NotaFiscalItem` (Task 4), `App\Models\Produto` (campos `ncm`, `cest`, `origem`, `tributacao_icms` já existentes da Etapa A).
- Produces: `POST /notas-fiscais` aceita `natureza_operacao` restrito a `Prestação de Serviços`/`Venda de Mercadoria` (rejeita `Misto` com 422); quando `Venda de Mercadoria`, aceita `itens[]` (`produto_id`, `quantidade`, `valor_unitario`) e persiste em `notas_fiscais_itens`; `subtotal` passa a ser recalculado a partir de `itens` quando `modelo=NF-e` (nunca confiado do cliente, mesmo padrão já usado em `EntradaNfController::store()`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotaFiscalNfeTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function criarCliente(): Cliente
    {
        return Cliente::create(['nome' => 'Cliente Teste', 'cpf_cnpj' => '87748248800', 'status' => 'REGULAR']);
    }

    private function criarProduto(): Produto
    {
        return Produto::create([
            'nome' => 'Filtro de Óleo', 'sku' => 'FLT-01', 'categoria' => 'Filtros',
            'qty_atual' => 10, 'qty_minima' => 2, 'preco_venda' => 45,
            'ncm' => '84212300', 'origem' => 0, 'tributacao_icms' => 'NORMAL',
            'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);
    }

    public function test_rejeita_natureza_misto(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Misto',
            'subtotal'          => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['natureza_operacao']);
    }

    public function test_venda_de_mercadoria_persiste_itens_com_dados_fiscais_do_produto(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();
        $produto = $this->criarProduto();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
            'itens'             => [[
                'produto_id'     => $produto->id,
                'quantidade'     => 2,
                'valor_unitario' => 45.00,
            ]],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.modelo', 'NF-e');

        $notaId = $response->json('data.id');
        $this->assertDatabaseHas('notas_fiscais_itens', [
            'nota_fiscal_id' => $notaId,
            'produto_id'     => $produto->id,
            'ncm'            => '84212300',
            'origem'         => 0,
        ]);
        $this->assertDatabaseHas('notas_fiscais', ['id' => $notaId, 'subtotal' => 90.00]);
    }

    public function test_venda_de_mercadoria_exige_itens(): void
    {
        $token   = $this->loginAdmin();
        $cliente = $this->criarCliente();

        $response = $this->withToken($token)->postJson('/api/notas-fiscais', [
            'cliente_id'        => $cliente->id,
            'natureza_operacao' => 'Venda de Mercadoria',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['itens']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Não é possível rodar nesta máquina (sem Postgres). Deixe registrado no PROGRESSO.md que este teste precisa rodar em CI/banco dedicado antes de considerar a task validada — mesma situação já documentada pra Etapa A.

- [ ] **Step 3: Implementar**

Em `NotaFiscalController.php`, troque o método `store()` por:

```php
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cliente_id'        => ['required', 'string', 'exists:clientes,id'],
            'os_id'             => ['nullable', 'string', 'exists:ordens_servico,id'],
            'natureza_operacao' => ['required', 'string', 'in:Prestação de Serviços,Venda de Mercadoria'],
            'forma_pagamento'   => ['nullable', 'string', 'max:30'],
            'subtotal'          => ['required_if:natureza_operacao,Prestação de Serviços', 'nullable', 'numeric', 'min:0'],
            'desconto'          => ['nullable', 'numeric', 'min:0'],
            'aliquota_iss'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacoes'       => ['nullable', 'string'],
            'itens'                  => ['required_if:natureza_operacao,Venda de Mercadoria', 'array'],
            'itens.*.produto_id'     => ['required_with:itens', 'uuid', 'exists:produtos,id'],
            'itens.*.quantidade'     => ['required_with:itens', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario' => ['required_with:itens', 'numeric', 'min:0'],
        ]);

        $ehVenda = $validated['natureza_operacao'] === 'Venda de Mercadoria';
        $modelo  = $ehVenda ? 'NF-e' : 'NFS-e';

        $subtotal = $ehVenda
            ? collect($validated['itens'])->sum(fn ($i) => $i['quantidade'] * $i['valor_unitario'])
            : (float) $validated['subtotal'];

        $desconto   = (float) ($validated['desconto'] ?? 0);
        $aliquota   = (float) ($validated['aliquota_iss'] ?? 5.00);
        $valorIss   = $ehVenda ? 0.0 : (($subtotal - $desconto) * $aliquota) / 100;
        $valorTotal = ($subtotal - $desconto) + $valorIss;

        $nota = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $modelo, $subtotal, $desconto, $aliquota, $valorIss, $valorTotal, $ehVenda) {
            $nota = NotaFiscal::create([
                'cliente_id'        => $validated['cliente_id'],
                'os_id'             => $validated['os_id'] ?? null,
                'natureza_operacao' => $validated['natureza_operacao'],
                'forma_pagamento'   => $validated['forma_pagamento'] ?? null,
                'observacoes'       => $validated['observacoes'] ?? null,
                'modelo'            => $modelo,
                'subtotal'          => $subtotal,
                'desconto'          => $desconto,
                'aliquota_iss'      => $aliquota,
                'valor_iss'         => $valorIss,
                'valor_total'       => $valorTotal,
                'status'            => 'RASCUNHO',
            ]);

            if ($ehVenda) {
                $oficinaUf = \App\Models\Configuracao::first()?->uf ?? '';
                $regime    = \App\Models\Configuracao::first()?->regime_tributario ?? 'Simples Nacional';

                foreach ($validated['itens'] as $item) {
                    $produto = \App\Models\Produto::findOrFail($item['produto_id']);
                    $cliente = \App\Models\Cliente::find($validated['cliente_id']);

                    $tributacao = $produto->tributacao_icms ?? 'NORMAL';
                    $cfop = \App\Services\Fiscal\CfopSaidaResolver::resolver(
                        $oficinaUf ?: 'MG',
                        $cliente?->uf ?: ($oficinaUf ?: 'MG'),
                        $tributacao === 'ST',
                    );
                    $cstCsosn = \App\Services\Fiscal\TributacaoIcmsSaidaResolver::resolver($regime, $tributacao);

                    \App\Models\NotaFiscalItem::create([
                        'nota_fiscal_id'  => $nota->id,
                        'produto_id'      => $produto->id,
                        'descricao'       => $produto->nome,
                        'ncm'             => $produto->ncm,
                        'cfop'            => $cfop,
                        'origem'          => $produto->origem,
                        'tributacao_icms' => $tributacao,
                        'cst_csosn'       => $cstCsosn,
                        'quantidade'      => $item['quantidade'],
                        'valor_unitario'  => $item['valor_unitario'],
                    ]);
                }
            }

            return $nota;
        });

        return (new NotaFiscalResource($nota->load(['cliente', 'itens'])))->response()->setStatusCode(201);
    }
```

- [ ] **Step 4: Verificar sintaxe**

Run: `cd backend && php -l app/Http/Controllers/NotaFiscalController.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/NotaFiscalController.php backend/tests/Feature/NotaFiscalNfeTest.php
git commit -m "feat(fiscal): store() de NF deriva modelo, rejeita Misto e persiste itens com CFOP/CST-CSOSN"
```

---

### Task 9: `NfeService::montarNotaData()` — branch NF-e

**Files:**
- Modify: `backend/app/Services/NfeService.php:16-56`

**Interfaces:**
- Consumes: `NotaFiscal::itens` (Task 4 relação), `NotaFiscalData` com `modelo`/`itens` (Task 3).
- Produces: `NfeService::montarNotaData()` monta `NotaFiscalData` com `modelo='NFE'` e `itens` preenchidos quando `$nota->modelo === 'NF-e'`.

- [ ] **Step 1: Write the failing test**

```php
    public function test_montar_nota_data_nfe_inclui_itens(): void
    {
        Configuracao::create([
            'razao_social' => 'Oficina Teste', 'cnpj' => '12.345.678/0001-90',
            'proximo_numero_nf' => 1, 'ambiente_fiscal' => 'HOMOLOGACAO',
            'estoque_limite_padrao' => 5, 'alertas_email' => false,
        ]);
        $cliente = \App\Models\Cliente::create(['nome' => 'Cliente', 'cpf_cnpj' => '87748248800']);
        $nota = \App\Models\NotaFiscal::create([
            'cliente_id' => $cliente->id, 'modelo' => 'NF-e',
            'natureza_operacao' => 'Venda de Mercadoria', 'subtotal' => 90, 'valor_total' => 90,
        ]);
        \App\Models\NotaFiscalItem::create([
            'nota_fiscal_id' => $nota->id, 'descricao' => 'Filtro',
            'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
            'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
            'quantidade' => 2, 'valor_unitario' => 45,
        ]);

        $data = $this->service->montarNotaData($nota->load('itens'));

        $this->assertSame('NFE', $data->modelo);
        $this->assertCount(1, $data->itens);
        $this->assertSame('84212300', $data->itens[0]['ncm']);
    }
```

(Adicione este método a `backend/tests/Feature/NfeServiceTest.php`, dentro da classe existente — precisa de Postgres, não roda localmente.)

- [ ] **Step 2: Run test to verify it fails**

Não roda localmente (sem Postgres) — deixe registrado como pendente de CI/banco dedicado.

- [ ] **Step 3: Implementar**

Em `NfeService::montarNotaData()`, troque o `return new NotaFiscalData(...)` final por:

```php
        $ehNfe = $nota->modelo === 'NF-e';

        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome'        => $cliente?->nome ?? '-',
                'cpf_cnpj'    => $cliente?->cpf_cnpj ?? '',
                'email'       => $cliente?->email,
                'cep'         => $cliente?->cep,
                'logradouro'  => $cliente?->endereco,
                'numero'      => 'S/N',
                'bairro'      => $cliente?->bairro,
                'cidade'      => $cliente?->cidade,
                'uf'          => $cliente?->uf,
                'codigo_ibge' => $codigoIbgeTomador,
            ],
            descricao: $nota->observacoes ?? 'Serviços automotivos',
            valorServicos: (float) $nota->valor_total,
            aliquotaIss: $aliquota,
            issRetido: false,
            codigoServicoFederal: $codigoServicoFederal,
            codigoServicoMunicipal: $codigoServicoMunicipal,
            naturezaOperacao: $nota->natureza_operacao ?? 'Prestação de Serviços',
            referenciaExterna: $nota->referencia_externa ?? ('nf-' . $nota->id),
            modelo: $ehNfe ? 'NFE' : 'NFSE',
            itens: $ehNfe ? $nota->itens->map(fn ($item) => [
                'produto_id'      => $item->produto_id,
                'descricao'       => $item->descricao,
                'ncm'             => $item->ncm,
                'cfop'            => $item->cfop,
                'origem'          => $item->origem,
                'tributacao_icms' => $item->tributacao_icms,
                'cst_csosn'       => $item->cst_csosn,
                'quantidade'      => $item->quantidade,
                'valor_unitario'  => $item->valor_unitario,
            ])->all() : [],
        );
```

Atualize a assinatura do método pra deixar claro que `$nota` precisa vir com `itens` carregado quando for NF-e (`NotaFiscal $nota` já é o tipo — adicione um comentário curto acima do método: `// Quando $nota->modelo === 'NF-e', $nota precisa ter sido carregado com ->load('itens') antes de chamar este método.`).

Confirme em `NotaFiscalController::emitir()` que `$nota` é carregado com `itens` antes de chamar `$this->nfeService->emitir($nota)` — hoje a linha é `NotaFiscal::with('cliente')->findOrFail($id)`; troque para `NotaFiscal::with(['cliente', 'itens'])->findOrFail($id)`.

- [ ] **Step 4: Verificar sintaxe**

Run: `cd backend && php -l app/Services/NfeService.php app/Http/Controllers/NotaFiscalController.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/NfeService.php backend/app/Http/Controllers/NotaFiscalController.php backend/tests/Feature/NfeServiceTest.php
git commit -m "feat(fiscal): NfeService monta itens de NF-e a partir de notas_fiscais_itens"
```

---

### Task 10: `NotaFiscalResource` — expõe `modelo` e `itens`

**Files:**
- Modify: `backend/app/Http/Resources/NotaFiscalResource.php:11-41`

**Interfaces:**
- Produces: resposta JSON de nota fiscal passa a incluir `itens` (array, presente só `whenLoaded`).

- [ ] **Step 1: Implementar (sem teste isolado — coberto pelas asserções de `data.modelo`/`notas_fiscais_itens` do Task 8)**

Em `NotaFiscalResource::toArray()`, adicione após `'modelo' => $this->modelo,`:

```php
            'itens'             => $this->whenLoaded('itens', fn () => $this->itens->map(fn ($i) => [
                'id'              => $i->id,
                'produto_id'      => $i->produto_id,
                'descricao'       => $i->descricao,
                'ncm'             => $i->ncm,
                'cfop'            => $i->cfop,
                'origem'          => $i->origem,
                'tributacao_icms' => $i->tributacao_icms,
                'cst_csosn'       => $i->cst_csosn,
                'quantidade'      => $i->quantidade,
                'valor_unitario'  => $i->valor_unitario,
                'valor_total'     => $i->valor_total,
            ])),
```

- [ ] **Step 2: Verificar sintaxe**

Run: `cd backend && php -l app/Http/Resources/NotaFiscalResource.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/app/Http/Resources/NotaFiscalResource.php
git commit -m "feat(fiscal): NotaFiscalResource expoe itens da nota quando carregados"
```

---

### Task 11: Frontend — `NotaFiscalForm.tsx`

**Files:**
- Modify: `frontend/components/forms/NotaFiscalForm.tsx`

**Interfaces:**
- Consumes: `GET /produtos?per_page=200` (já existe, usado no mesmo padrão de `OSForm.tsx:154`), campos `ncm`/`origem`/`tributacao_icms`/`fiscal_pendente` já expostos por `ProdutoResource` (Etapa A).
- Produces: `POST /notas-fiscais` com `itens[].produto_id` quando natureza = Venda (backend já aceita, Task 8).

- [ ] **Step 1: Antes de editar, leia `frontend/AGENTS.md`**

Este projeto usa uma versão modificada do Next.js — confira `frontend/node_modules/next/dist/docs/` se algo do App Router aqui não bater com o esperado. Esta mudança não mexe em rotas nem data-fetching do servidor, só em um client component existente, mas confira mesmo assim antes de come çar.

- [ ] **Step 2: Adicionar estado e busca de produtos**

Adicione ao topo do arquivo, junto das outras interfaces:

```tsx
interface ProdutoOpt {
  id: string
  nome: string
  sku: string
  ncm: string | null
  origem: number | null
  tributacao_icms: string | null
  fiscal_pendente: boolean
  preco_venda: number | null
}
```

No componente, adicione o estado e o carregamento (junto do `useEffect` existente que já carrega `clientes`/`empresa`/`plano`):

```tsx
  const [produtos, setProdutos] = useState<ProdutoOpt[]>([])

  useEffect(() => {
    if (natureza === 'Venda de Mercadoria' && produtos.length === 0) {
      api.get('/produtos?per_page=200').then(r => setProdutos(r.data.data ?? [])).catch(() => {})
    }
  }, [natureza, produtos.length])
```

- [ ] **Step 3: Bifurcar a lista de itens por natureza**

Troque a interface `ItemNF` (linha 7-11) por uma união que suporte os dois modos:

```tsx
interface ItemNF {
  descricao: string
  quantidade: number
  valor_unitario: number
  produto_id?: string
}
```

Troque o bloco `<select value={natureza} ...>` (linhas 138-142) pra desabilitar "Misto":

```tsx
            <select value={natureza} onChange={e => setNatureza(e.target.value)} style={iStyle}>
              <option>Prestação de Serviços</option>
              <option>Venda de Mercadoria</option>
              <option disabled>Misto (em breve)</option>
            </select>
```

No bloco de itens (linhas 182-216), troque o `<input>` de descrição por um `<select>` de produto quando `natureza === 'Venda de Mercadoria'`:

```tsx
          {itens.map((item, idx) => (
            <div key={idx} style={{ display: 'grid', gridTemplateColumns: '3fr 1fr 1fr auto', gap: 6, marginBottom: 6 }}>
              {natureza === 'Venda de Mercadoria' ? (
                <select
                  value={item.produto_id ?? ''}
                  onChange={e => {
                    const p = produtos.find(x => x.id === e.target.value)
                    setItens(prev => prev.map((it, j) => j === idx ? {
                      ...it,
                      produto_id: p?.id,
                      descricao: p?.nome ?? '',
                      valor_unitario: p?.preco_venda ?? 0,
                    } : it))
                  }}
                  style={iStyle}
                >
                  <option value="">Selecionar produto...</option>
                  {produtos.map(p => (
                    <option key={p.id} value={p.id}>
                      {p.nome} {p.fiscal_pendente ? '⚠ dados fiscais pendentes' : ''}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  value={item.descricao}
                  onChange={e => updateItem(idx, 'descricao', e.target.value)}
                  placeholder="Descrição"
                  style={iStyle}
                />
              )}
              <input
                type="number"
                min={0.01}
                step="0.01"
                value={item.quantidade}
                onChange={e => updateItem(idx, 'quantidade', +e.target.value)}
                style={iStyle}
              />
              <input
                type="number"
                min={0}
                step="0.01"
                value={item.valor_unitario}
                onChange={e => updateItem(idx, 'valor_unitario', +e.target.value)}
                style={iStyle}
              />
              {itens.length > 1 ? (
                <button
                  type="button"
                  onClick={() => setItens(prev => prev.filter((_, j) => j !== idx))}
                  style={{ padding: '0 12px', background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18 }}
                >
                  ×
                </button>
              ) : <div />}
            </div>
          ))}
```

Produto com `fiscal_pendente=true` aparece com o aviso inline na própria `<option>` — não bloqueia a seleção nem o envio (mesma filosofia da Etapa A de nunca travar o fluxo principal por dado fiscal incompleto).

- [ ] **Step 4: Enviar `itens[].produto_id` no POST**

Troque a função `emitir()` (linhas 92-118): quando `natureza === 'Venda de Mercadoria'`, valide que todo item tem `produto_id` (em vez de `descricao`) e envie `itens` com `produto_id`/`quantidade`/`valor_unitario` no lugar de `subtotal`:

```tsx
  async function emitir() {
    if (!clienteId) { toast('Selecione um cliente.', 'danger'); return }
    const ehVenda = natureza === 'Venda de Mercadoria'
    if (ehVenda && itens.every(i => !i.produto_id)) { toast('Adicione pelo menos um produto.', 'danger'); return }
    if (!ehVenda && itens.every(i => !i.descricao)) { toast('Adicione pelo menos um item.', 'danger'); return }
    setLoading(true)
    try {
      const payload: Record<string, unknown> = {
        cliente_id: clienteId,
        natureza_operacao: natureza,
        forma_pagamento: formaPgto || undefined,
        observacoes: obs || undefined,
      }
      if (ehVenda) {
        payload.itens = itens.filter(i => i.produto_id).map(i => ({
          produto_id: i.produto_id, quantidade: i.quantidade, valor_unitario: i.valor_unitario,
        }))
      } else {
        payload.subtotal = subtotal
        payload.desconto = desconto
        payload.aliquota_iss = aliquota
      }
      const nf = await api.post('/notas-fiscais', payload)
      const resultado = await api.post(`/notas-fiscais/${nf.data.data.id}/emitir`)
      toast(`NF #${resultado.data.data.numero} emitida com sucesso!`, 'success')
      setClienteId('')
      setItens([{ descricao: '', quantidade: 1, valor_unitario: 0 }])
      setDesconto(0)
      setObs('')
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao emitir NF.', 'danger')
    } finally {
      setLoading(false)
    }
  }
```

- [ ] **Step 5: Verificar build**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros.

Run: `cd frontend && npm run build`
Expected: build limpo.

- [ ] **Step 6: Commit**

```bash
git add frontend/components/forms/NotaFiscalForm.tsx
git commit -m "feat(fiscal): formulario de NF seleciona produto do cadastro para venda de mercadoria"
```

---

### Task 12: Regressão da suite de NFS-e existente

**Files:**
- Nenhum arquivo novo — só execução e, se necessário, ajuste pontual.

**Interfaces:**
- N/A — task de verificação, não de implementação.

- [ ] **Step 1: Rodar toda a suite Unit localmente**

Run: `cd backend && php artisan test --testsuite=Unit`
Expected: PASS em tudo, incluindo `FocusNfeProviderTest`, `SpedyProviderTest`, `CfopSaidaResolverTest`, `TributacaoIcmsSaidaResolverTest`, `NotaFiscalDataTest` e qualquer teste unitário pré-existente não relacionado a esta etapa.

- [ ] **Step 2: Registrar no `PROGRESSO.md` o que falta rodar em CI/banco dedicado**

Adicione uma entrada de rodada cobrindo: os Feature tests escritos nos Tasks 8 e 9 (`NotaFiscalNfeTest`, o teste novo em `NfeServiceTest`) nunca rodaram contra Postgres real nesta sessão — precisam rodar em CI ou banco de teste dedicado antes de considerar a Etapa B validada; a migration `2026_08_02_000001_create_notas_fiscais_itens_table` precisa ser conferida com `php artisan migrate:status` pós-deploy; e o endpoint da Spedy (Task 7) segue como hipótese até ser confirmado contra sandbox/doc autenticada — reforce isso como bloqueante pra emitir uma NF-e real de peça via Spedy em produção, mesmo que o código compile e os testes unitários passem.

- [ ] **Step 3: Commit**

```bash
git add PROGRESSO.md
git commit -m "docs: registra pendencias de validacao da etapa B (feature tests, migration, endpoint Spedy)"
```

---

## Self-review (feito pelo autor do plano, não uma sub-tarefa do implementador)

- **Cobertura do spec:** Seção 1 (escopo) → Tasks 1-11 implementam exatamente o que ficou dentro; nada do que ficou "fora" (orquestrador, fila, Misto habilitado) foi implementado. Seção 2 (DTO/modelo) → Tasks 1, 3, 4. Correção encontrada durante o planejamento: a coluna `notas_fiscais.modelo` **já existe** (default `'NFS-e'`, nunca setada por nenhum controller) — o plano reaproveita essa coluna em vez de criar uma nova, e usa os valores `'NF-e'`/`'NFS-e'` (com hífen, como já está no banco) na tabela, mantendo `'NFE'`/`'NFSE'` (sem hífen) só como convenção interna do DTO `NotaFiscalData`. Seção 3 (provedores/5 defeitos) → Tasks 5, 6, 7 (Spedy fica com uma ressalva clara de verificação pendente, não um "chute"). Seção 4 (frontend) → Task 11. Seção 5 (testes) → testes unitários em cada task + Task 12 fecha a regressão.
- **Lacuna encontrada e fechada durante o planejamento:** o spec original listava `cst_csosn` como campo do item sem definir de onde vem — produtos (Etapa A) só guardam a classificação simplificada `tributacao_icms` (NORMAL/ST), não o código exato, que depende do regime tributário da oficina. Fechado com o novo `TributacaoIcmsSaidaResolver` (Task 2), com base legal verificada (CSOSN 102/500, CST 00/60) na mesma sessão.
- **Consistência de tipos:** `NotaFiscalData->modelo` é `'NFE'|'NFSE'` (sem hífen) em todo o código PHP novo (Tasks 3, 6, 7, 9); `NotaFiscal->modelo` (coluna do banco) é `'NF-e'|'NFS-e'` (com hífen) em todo o código novo (Tasks 8, 9) — a tradução entre os dois acontece só na Task 9 (`$ehNfe = $nota->modelo === 'NF-e'`). Isso não é inconsistência, é a fronteira DTO-interno vs. coluna-de-banco-legada sendo respeitada de propósito; qualquer subagente que for implementar precisa preservar essa distinção exata, não unificar os dois formatos.
