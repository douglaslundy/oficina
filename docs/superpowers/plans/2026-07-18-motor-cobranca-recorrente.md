# Motor de Cobrança Recorrente Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir a subscription nativa do gateway (Asaas/Mercado Pago) por um calendário de cobrança controlado localmente: cada oficina tem `proximo_vencimento` + `ciclo_cobranca`, um job diário gera cobrança avulsa X dias antes do vencimento, e o pagamento é reconciliado via webhook por `payment_id` (não mais por `subscription_id`).

**Architecture:** Novo serviço `CobrancaRecorrenteService` roda diariamente via `Schedule::command`, gera `Cobranca` (tipo `ASSINATURA`) usando a rota de cobrança avulsa já existente (Asaas/MP, corrigida numa sessão anterior). Webhooks dos dois gateways passam a reconciliar por `asaas_payment_id`/`mp_payment_id` em vez de `subscription`. `TenantProvisionService` para de criar subscription — só cria `customer` + `proximo_vencimento` inicial.

**Tech Stack:** Laravel 12 / PHP 8.2 / PostgreSQL 16 (backend), Next.js 16 / TypeScript (frontend SaaS admin).

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo/editado.
- UUID PKs, `$incrementing = false`, `$timestamps = false` com `criado_em`/`atualizado_em` manuais — siga o padrão dos models existentes (`Oficina`, `Cobranca`).
- Datas expostas em JSON como `toDateString()` (`YYYY-MM-DD`) — é o formato que o frontend já consome pra `Cobranca.vencimento`.
- Sem `Docker`/Postgres neste ambiente de desenvolvimento: `php artisan test tests/Unit/...` roda localmente; testes de Feature (usam `RefreshDatabase`, exigem Postgres) devem ser escritos e revisados aqui, mas só rodam de fato num ambiente com banco (CI/VPS) — isso já é a convenção estabelecida no projeto. Cada task deixa claro qual dos dois casos se aplica.
- Nunca rodar migration/feature test contra o banco de produção.

---

### Task 1: Migrations + fillable/casts em `Oficina` e `SaasConfig`

**Files:**
- Create: `backend/database/migrations/2026_07_18_000001_add_cobranca_fields_to_oficinas_table.php`
- Create: `backend/database/migrations/2026_07_18_000002_add_cobranca_fields_to_saas_config_table.php`
- Modify: `backend/app/Models/Oficina.php`
- Modify: `backend/app/Models/SaasConfig.php`

**Interfaces:**
- Produces: `oficinas.ciclo_cobranca` (string, `MENSAL`|`ANUAL`), `oficinas.proximo_vencimento` (date, nullable), `oficinas.dias_antecedencia_cobranca` (int, nullable), `oficinas.dias_suspensao_vencido` (int, nullable); `saas_config.cobranca_dias_antecedencia_padrao` (int, default 5), `saas_config.cobranca_dias_suspensao_padrao` (int, default 10), `saas_config.desconto_anual_pct` (numeric, default 0). `Oficina` model exposes these via `$fillable`/`$casts` for later tasks.

- [ ] **Step 1: Criar a migration de `oficinas`**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('oficinas', function (Blueprint $table) {
            $table->string('ciclo_cobranca', 10)->default('MENSAL')->after('gateway');
            $table->date('proximo_vencimento')->nullable()->after('ciclo_cobranca');
            $table->integer('dias_antecedencia_cobranca')->nullable()->after('proximo_vencimento');
            $table->integer('dias_suspensao_vencido')->nullable()->after('dias_antecedencia_cobranca');
        });
    }

    public function down(): void
    {
        Schema::table('oficinas', function (Blueprint $table) {
            $table->dropColumn(['ciclo_cobranca', 'proximo_vencimento', 'dias_antecedencia_cobranca', 'dias_suspensao_vencido']);
        });
    }
};
```

- [ ] **Step 2: Criar a migration de `saas_config`**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->integer('cobranca_dias_antecedencia_padrao')->default(5);
            $table->integer('cobranca_dias_suspensao_padrao')->default(10);
            $table->decimal('desconto_anual_pct', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn(['cobranca_dias_antecedencia_padrao', 'cobranca_dias_suspensao_padrao', 'desconto_anual_pct']);
        });
    }
};
```

- [ ] **Step 3: Verificar sintaxe (sem banco local disponível)**

Run: `cd backend && php -l database/migrations/2026_07_18_000001_add_cobranca_fields_to_oficinas_table.php && php -l database/migrations/2026_07_18_000002_add_cobranca_fields_to_saas_config_table.php`
Expected: `No syntax errors detected` nos dois arquivos.

- [ ] **Step 4: Atualizar `Oficina.php`**

Em `backend/app/Models/Oficina.php`, adicionar ao `$fillable` (depois de `'mp_subscription_id',`):

```php
        'ciclo_cobranca',
        'proximo_vencimento',
        'dias_antecedencia_cobranca',
        'dias_suspensao_vencido',
```

E ao `$casts` (substituir o array inteiro):

```php
    protected $casts = [
        'criado_em'                  => 'datetime',
        'atualizado_em'              => 'datetime',
        'proximo_vencimento'         => 'date',
        'dias_antecedencia_cobranca' => 'integer',
        'dias_suspensao_vencido'     => 'integer',
    ];
```

- [ ] **Step 5: Adicionar `'id'` ao `$fillable` de `Cobranca`**

Tasks futuras (5 e 8) precisam pré-gerar o UUID da `Cobranca` *antes* de chamar o
gateway (pra usar como `externalReference`) e depois criar a linha com esse
mesmo id via `Cobranca::create(['id' => $cobrancaId, ...])`. Hoje `id` não está
em `$fillable`, então esse valor seria silenciosamente ignorado e o hook
`creating()` geraria outro UUID — quebrando a reconciliação por referência.

Em `backend/app/Models/Cobranca.php`, adicionar `'id',` como primeiro item do
`$fillable` (o hook em `boot()` continua funcionando normalmente para quem não
passar `id` — só entra em ação quando `empty($model->id)`).

- [ ] **Step 6: Verificar sintaxe**

Run: `cd backend && php -l app/Models/Cobranca.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Atualizar `SaasConfig.php`**

Em `backend/app/Models/SaasConfig.php`, adicionar ao `$fillable` (depois de `'focus_master_token_producao',`):

```php
        'cobranca_dias_antecedencia_padrao',
        'cobranca_dias_suspensao_padrao',
        'desconto_anual_pct',
```

E ao `$casts` (adicionar bloco novo, já que hoje só tem `smtp_port`/`smtp_ativo`):

```php
    protected $casts = [
        'smtp_port'                         => 'integer',
        'smtp_ativo'                        => 'boolean',
        'cobranca_dias_antecedencia_padrao' => 'integer',
        'cobranca_dias_suspensao_padrao'    => 'integer',
        'desconto_anual_pct'                => 'decimal:2',
    ];
```

- [ ] **Step 8: Verificar sintaxe dos models**

Run: `cd backend && php -l app/Models/Oficina.php && php -l app/Models/SaasConfig.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 9: Commit**

```bash
git add backend/database/migrations/2026_07_18_000001_add_cobranca_fields_to_oficinas_table.php backend/database/migrations/2026_07_18_000002_add_cobranca_fields_to_saas_config_table.php backend/app/Models/Oficina.php backend/app/Models/SaasConfig.php backend/app/Models/Cobranca.php
git commit -m "feat(saas): campos de ciclo/vencimento de cobranca em oficinas e saas_config"
```

---

### Task 2: `Oficina::calcularProximoVencimento()` + `avancarVencimento()`

**Files:**
- Modify: `backend/app/Models/Oficina.php`
- Test: `backend/tests/Unit/OficinaVencimentoTest.php` (novo)

**Interfaces:**
- Consumes: `Oficina.$ciclo_cobranca`, `Oficina.$proximo_vencimento` (de Task 1).
- Produces: `Oficina::calcularProximoVencimento(): \Carbon\Carbon` (pura, sem I/O — soma 1 ou 12 meses ao `proximo_vencimento` atual conforme `ciclo_cobranca`), `Oficina::avancarVencimento(): void` (persiste o resultado). Usados pela reconciliação de pagamento (Task 7) e pelo motor (Task 5).

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Oficina;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OficinaVencimentoTest extends TestCase
{
    public function test_ciclo_mensal_soma_um_mes(): void
    {
        $oficina = new Oficina();
        $oficina->ciclo_cobranca = 'MENSAL';
        $oficina->proximo_vencimento = Carbon::parse('2026-01-15');

        $this->assertSame('2026-02-15', $oficina->calcularProximoVencimento()->toDateString());
    }

    public function test_ciclo_anual_soma_doze_meses(): void
    {
        $oficina = new Oficina();
        $oficina->ciclo_cobranca = 'ANUAL';
        $oficina->proximo_vencimento = Carbon::parse('2026-01-15');

        $this->assertSame('2027-01-15', $oficina->calcularProximoVencimento()->toDateString());
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Unit/OficinaVencimentoTest.php`
Expected: FAIL — `Call to undefined method App\Models\Oficina::calcularProximoVencimento()`

- [ ] **Step 3: Implementar**

Em `backend/app/Models/Oficina.php`, adicionar os métodos (depois de `cobrancas()`):

```php
    public function calcularProximoVencimento(): \Illuminate\Support\Carbon
    {
        $meses = $this->ciclo_cobranca === 'ANUAL' ? 12 : 1;
        return $this->proximo_vencimento->copy()->addMonths($meses);
    }

    public function avancarVencimento(): void
    {
        $this->update(['proximo_vencimento' => $this->calcularProximoVencimento()]);
    }
```

- [ ] **Step 4: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Unit/OficinaVencimentoTest.php`
Expected: PASS — 2 testes.

- [ ] **Step 5: Rodar toda a suíte Unit (regressão)**

Run: `cd backend && php artisan test tests/Unit`
Expected: PASS — todos os testes (32 anteriores + 2 novos = 34).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Models/Oficina.php backend/tests/Unit/OficinaVencimentoTest.php
git commit -m "feat(saas): Oficina::calcularProximoVencimento/avancarVencimento"
```

---

### Task 3: `AsaasService::criarCobrancaAvulsa` aceita `externalReference`

**Files:**
- Modify: `backend/app/Services/AsaasService.php`
- Test: `backend/tests/Feature/Saas/AsaasServiceCobrancaTest.php` (novo)

**Interfaces:**
- Produces: `AsaasService::criarCobrancaAvulsa(string $customerId, float $value, string $dueDate, ?string $externalReference = null): array` — mesma assinatura de hoje + 4º parâmetro opcional, retrocompatível.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Services\AsaasService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsaasServiceCobrancaTest extends TestCase
{
    public function test_cobranca_avulsa_envia_external_reference_quando_informado(): void
    {
        config(['services.asaas.url' => 'https://sandbox.asaas.com/api/v3', 'services.asaas.api_key' => 'chave-teste']);

        Http::fake([
            '*/payments' => Http::response(['id' => 'pay_123'], 200),
        ]);

        $service = app(AsaasService::class);
        $result  = $service->criarCobrancaAvulsa('cus_abc', 199.90, '2026-08-01', 'cobranca-uuid-xyz');

        $this->assertSame('pay_123', $result['id']);
        Http::assertSent(fn($request) => $request['externalReference'] === 'cobranca-uuid-xyz');
    }

    public function test_cobranca_avulsa_sem_external_reference_nao_quebra(): void
    {
        config(['services.asaas.url' => 'https://sandbox.asaas.com/api/v3', 'services.asaas.api_key' => 'chave-teste']);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_456'], 200)]);

        $service = app(AsaasService::class);
        $result  = $service->criarCobrancaAvulsa('cus_abc', 50, '2026-08-01');

        $this->assertSame('pay_456', $result['id']);
        Http::assertSent(fn($request) => !array_key_exists('externalReference', $request->data()));
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/AsaasServiceCobrancaTest.php`
Expected: FAIL no primeiro teste — `externalReference` nunca é enviado pela implementação atual. `AsaasService` não toca o banco (só lê `config()`), então este teste roda mesmo neste sandbox sem Postgres.

- [ ] **Step 3: Implementar**

Em `backend/app/Services/AsaasService.php`, substituir o método `criarCobrancaAvulsa`:

```php
    public function criarCobrancaAvulsa(string $customerId, float $value, string $dueDate, ?string $externalReference = null): array
    {
        $payload = [
            'customer'    => $customerId,
            'billingType' => 'BOLETO',
            'value'       => $value,
            'dueDate'     => $dueDate,
            'description' => 'Cobrança avulsa — MecânicaPro',
        ];

        if ($externalReference !== null) {
            $payload['externalReference'] = $externalReference;
        }

        $response = Http::withHeaders(['access_token' => $this->apiKey])
            ->post("{$this->baseUrl}/payments", $payload);

        $this->throwIfFailed($response, 'criar cobrança avulsa');
        return $response->json();
    }
```

- [ ] **Step 4: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/AsaasServiceCobrancaTest.php`
Expected: PASS — 2 testes (não precisa de Postgres — roda neste sandbox).

- [ ] **Step 5: Lint de sintaxe local (sempre roda, sem banco)**

Run: `cd backend && php -l app/Services/AsaasService.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AsaasService.php backend/tests/Feature/Saas/AsaasServiceCobrancaTest.php
git commit -m "feat(saas): AsaasService::criarCobrancaAvulsa aceita externalReference"
```

---

### Task 4: `MercadoPagoService::criarCobrancaAvulsa` aceita `externalReference`

**Files:**
- Modify: `backend/app/Services/MercadoPagoService.php`
- Test: `backend/tests/Feature/Saas/MercadoPagoServiceCobrancaTest.php` (novo)

**Interfaces:**
- Produces: `MercadoPagoService::criarCobrancaAvulsa(string $customerId, float $valor, string $vencimento, ?string $externalReference = null): array` — se `$externalReference` for `null`, mantém o comportamento atual de usar `$customerId` como referência (retrocompatível).

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoServiceCobrancaTest extends TestCase
{
    public function test_cobranca_avulsa_usa_external_reference_informado(): void
    {
        Http::fake([
            '*/checkout/preferences' => Http::response(['id' => 'pref_123', 'init_point' => 'https://mp.test/checkout/pref_123'], 200),
        ]);

        $service = app(MercadoPagoService::class);
        $result  = $service->criarCobrancaAvulsa('cus_mp_1', 149.90, '2026-08-01', 'cobranca-uuid-xyz');

        $this->assertSame('pref_123', $result['id']);
        $this->assertSame('https://mp.test/checkout/pref_123', $result['init_point']);
        Http::assertSent(fn($request) => $request['external_reference'] === 'cobranca-uuid-xyz');
    }

    public function test_cobranca_avulsa_sem_referencia_usa_customer_id(): void
    {
        Http::fake(['*/checkout/preferences' => Http::response(['id' => 'pref_456'], 200)]);

        $service = app(MercadoPagoService::class);
        $service->criarCobrancaAvulsa('cus_mp_2', 50, '2026-08-01');

        Http::assertSent(fn($request) => $request['external_reference'] === 'cus_mp_2');
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/MercadoPagoServiceCobrancaTest.php`
Expected: FAIL no primeiro teste — a implementação atual sempre usa `$customerId`, ignorando qualquer referência específica. Ao contrário do Asaas, `MercadoPagoService::__construct()` chama `SaasConfig::get()` (Eloquent), então este teste precisa de Postgres.

- [ ] **Step 3: Implementar**

Em `backend/app/Services/MercadoPagoService.php`, substituir o método `criarCobrancaAvulsa`:

```php
    public function criarCobrancaAvulsa(string $customerId, float $valor, string $vencimento, ?string $externalReference = null): array
    {
        $response = $this->http()->post('/checkout/preferences', [
            'items' => [[
                'title'       => 'Cobrança avulsa — MecânicaPro',
                'quantity'    => 1,
                'currency_id' => 'BRL',
                'unit_price'  => $valor,
            ]],
            'external_reference' => $externalReference ?? $customerId,
            'expires'             => true,
            'expiration_date_to'  => $vencimento . 'T23:59:59.000-03:00',
        ]);

        $this->throwIfFailed($response, 'criar cobrança avulsa');
        $data = $response->json();

        return [
            'id'         => $data['id'] ?? null,
            'init_point' => $data['init_point'] ?? null,
        ];
    }
```

- [ ] **Step 4: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/MercadoPagoServiceCobrancaTest.php`
Expected: PASS — 2 testes (requer Postgres).

- [ ] **Step 5: Lint de sintaxe local**

Run: `cd backend && php -l app/Services/MercadoPagoService.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/MercadoPagoService.php backend/tests/Feature/Saas/MercadoPagoServiceCobrancaTest.php
git commit -m "feat(saas): MercadoPagoService::criarCobrancaAvulsa aceita externalReference"
```

---

### Task 5: `CobrancaRecorrenteService::gerarPendentes()`

**Files:**
- Create: `backend/app/Services/CobrancaRecorrenteService.php`
- Test: `backend/tests/Feature/Saas/CobrancaRecorrenteServiceTest.php` (novo)

**Interfaces:**
- Consumes: `Oficina::$proximo_vencimento/$ciclo_cobranca/$dias_antecedencia_cobranca/$gateway/$asaas_customer_id/$mp_customer_id` (Task 1), `SaasConfig::$cobranca_dias_antecedencia_padrao/$desconto_anual_pct`, `AsaasService::criarCobrancaAvulsa(...)` / `MercadoPagoService::criarCobrancaAvulsa(...)` (Tasks 3-4).
- Produces: `CobrancaRecorrenteService::gerarPendentes(): int` (retorna quantidade de cobranças criadas). Consumida pelo Command (Task 6).

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Services\CobrancaRecorrenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CobrancaRecorrenteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 199.90]);

        return Oficina::create(array_merge([
            'nome'               => 'Oficina Teste',
            'cnpj'               => '11222333000181',
            'slug'               => 'oficina-teste-' . uniqid(),
            'plano_id'           => $plano->id,
            'status'             => 'ATIVA',
            'gateway'            => 'ASAAS',
            'asaas_customer_id'  => 'cus_123',
            'ciclo_cobranca'     => 'MENSAL',
            'proximo_vencimento' => now()->addDays(3)->toDateString(),
        ], $overrides))->load('plano');
    }

    public function test_gera_cobranca_quando_dentro_da_antecedencia(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5]);
        $oficina = $this->criarOficina(); // vence em 3 dias, antecedência padrão 5 -> deve gerar

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        $geradas = app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertSame(1, $geradas);
        $this->assertDatabaseHas('cobrancas', [
            'oficina_id' => $oficina->id,
            'tipo'       => 'ASSINATURA',
            'status'     => 'PENDENTE',
            'valor'      => '199.90',
        ]);
    }

    public function test_nao_gera_cobranca_fora_da_antecedencia(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5]);
        $this->criarOficina(['proximo_vencimento' => now()->addDays(20)->toDateString()]);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        $geradas = app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertSame(0, $geradas);
        $this->assertDatabaseCount('cobrancas', 0);
    }

    public function test_nao_duplica_cobranca_ja_existente_para_o_mesmo_vencimento(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5]);
        $oficina = $this->criarOficina();

        Cobranca::create([
            'oficina_id'     => $oficina->id,
            'tipo'           => 'ASSINATURA',
            'valor'          => 199.90,
            'status'         => 'PENDENTE',
            'vencimento'     => $oficina->proximo_vencimento,
            'mes_referencia' => $oficina->proximo_vencimento->copy()->startOfMonth(),
        ]);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        $geradas = app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertSame(0, $geradas);
        $this->assertDatabaseCount('cobrancas', 1);
    }

    public function test_calcula_valor_anual_com_desconto(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5, 'desconto_anual_pct' => 10]);
        $oficina = $this->criarOficina(['ciclo_cobranca' => 'ANUAL']);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        app(CobrancaRecorrenteService::class)->gerarPendentes();

        // 199.90 * 12 * 0.9 = 2158.92
        $this->assertDatabaseHas('cobrancas', [
            'oficina_id' => $oficina->id,
            'valor'      => '2158.92',
        ]);
    }

    public function test_pula_oficina_sem_customer_id_sem_quebrar(): void
    {
        SaasConfig::get()->update(['cobranca_dias_antecedencia_padrao' => 5]);
        $this->criarOficina(['asaas_customer_id' => null]);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_abc'], 200)]);

        $geradas = app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertSame(0, $geradas);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/CobrancaRecorrenteServiceTest.php`
Expected: FAIL — `Class "App\Services\CobrancaRecorrenteService" not found` (requer Postgres).

- [ ] **Step 3: Implementar**

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CobrancaRecorrenteService
{
    private const MESES_PT = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
    ];

    public function __construct(
        private readonly AsaasService $asaas,
        private readonly MercadoPagoService $mercadoPago,
    ) {}

    public function gerarPendentes(): int
    {
        $cfg = SaasConfig::get();
        $geradas = 0;

        $oficinas = Oficina::with('plano')
            ->whereIn('status', ['ATIVA', 'INADIMPLENTE'])
            ->whereNotNull('proximo_vencimento')
            ->get();

        foreach ($oficinas as $oficina) {
            if (!$oficina->plano || (float) $oficina->plano->preco_mensal <= 0) {
                continue;
            }

            $dias = $oficina->dias_antecedencia_cobranca ?? $cfg->cobranca_dias_antecedencia_padrao;
            $geraApartirDe = $oficina->proximo_vencimento->copy()->subDays($dias);

            if (now()->toDateString() < $geraApartirDe->toDateString()) {
                continue;
            }

            $jaExiste = Cobranca::where('oficina_id', $oficina->id)
                ->where('tipo', 'ASSINATURA')
                ->where('vencimento', $oficina->proximo_vencimento->toDateString())
                ->where('status', '!=', 'CANCELADA')
                ->exists();

            if ($jaExiste) {
                continue;
            }

            if ($this->criarCobranca($oficina, $cfg)) {
                $geradas++;
            }
        }

        return $geradas;
    }

    private function criarCobranca(Oficina $oficina, SaasConfig $cfg): bool
    {
        $gateway    = $oficina->gateway ?: ($cfg->gateway_preferido ?? 'ASAAS');
        $customerId = $gateway === 'MERCADOPAGO' ? $oficina->mp_customer_id : $oficina->asaas_customer_id;

        if (!$customerId) {
            Log::warning("CobrancaRecorrente: oficina {$oficina->id} sem customer no {$gateway}, pulando.");
            return false;
        }

        $valor = $oficina->ciclo_cobranca === 'ANUAL'
            ? round((float) $oficina->plano->preco_mensal * 12 * (1 - (float) $cfg->desconto_anual_pct / 100), 2)
            : (float) $oficina->plano->preco_mensal;

        $cobrancaId = (string) Str::uuid();
        $vencimento = $oficina->proximo_vencimento->toDateString();

        try {
            $payment = $gateway === 'MERCADOPAGO'
                ? $this->mercadoPago->criarCobrancaAvulsa($customerId, $valor, $vencimento, $cobrancaId)
                : $this->asaas->criarCobrancaAvulsa($customerId, $valor, $vencimento, $cobrancaId);
        } catch (\Throwable $e) {
            Log::warning("CobrancaRecorrente: falha ao gerar cobrança para oficina {$oficina->id} ({$gateway}): {$e->getMessage()}");
            return false;
        }

        $descricao = $oficina->ciclo_cobranca === 'ANUAL'
            ? sprintf('Assinatura anual %d–%d', $oficina->proximo_vencimento->year, $oficina->proximo_vencimento->year + 1)
            : sprintf('Mensalidade %s/%d', self::MESES_PT[$oficina->proximo_vencimento->month], $oficina->proximo_vencimento->year);

        Cobranca::create([
            'id'               => $cobrancaId,
            'oficina_id'       => $oficina->id,
            'tipo'             => 'ASSINATURA',
            'descricao'        => $descricao,
            'mes_referencia'   => $oficina->proximo_vencimento->copy()->startOfMonth(),
            'valor'            => $valor,
            'status'           => 'PENDENTE',
            'gateway'          => $gateway,
            'asaas_payment_id' => $gateway === 'ASAAS' ? ($payment['id'] ?? null) : null,
            'mp_payment_id'    => $gateway === 'MERCADOPAGO' ? ($payment['id'] ?? null) : null,
            'vencimento'       => $vencimento,
        ]);

        return true;
    }
}
```

- [ ] **Step 4: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/CobrancaRecorrenteServiceTest.php`
Expected: PASS — 5 testes (requer Postgres).

- [ ] **Step 5: Lint de sintaxe local**

Run: `cd backend && php -l app/Services/CobrancaRecorrenteService.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/CobrancaRecorrenteService.php backend/tests/Feature/Saas/CobrancaRecorrenteServiceTest.php
git commit -m "feat(saas): CobrancaRecorrenteService::gerarPendentes"
```

---

### Task 6: `marcarVencidas()` + Command `cobrancas:gerar` + schedule

**Files:**
- Modify: `backend/app/Services/CobrancaRecorrenteService.php`
- Create: `backend/app/Console/Commands/GerarCobrancasRecorrentes.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Saas/GerarCobrancasRecorrentesCommandTest.php` (novo)

**Interfaces:**
- Consumes: `CobrancaRecorrenteService::gerarPendentes()` (Task 5).
- Produces: `CobrancaRecorrenteService::marcarVencidas(): int`. Command `php artisan cobrancas:gerar`, agendado diariamente às 06:00.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GerarCobrancasRecorrentesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_gera_pendentes_e_marca_vencidas(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);

        $oficinaAVencer = Oficina::create([
            'nome' => 'A Vencer', 'cnpj' => '11222333000181', 'slug' => 'a-vencer-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'ASAAS',
            'asaas_customer_id' => 'cus_1', 'proximo_vencimento' => now()->addDays(2)->toDateString(),
        ]);

        $oficinaVencida = Oficina::create([
            'nome' => 'Vencida', 'cnpj' => '11222333000271', 'slug' => 'vencida-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'ASAAS',
            'asaas_customer_id' => 'cus_2', 'proximo_vencimento' => now()->addMonth()->toDateString(),
        ]);

        Cobranca::create([
            'oficina_id' => $oficinaVencida->id, 'tipo' => 'ASSINATURA',
            'valor' => 100, 'status' => 'PENDENTE', 'vencimento' => now()->subDays(3)->toDateString(),
        ]);

        Http::fake(['*/payments' => Http::response(['id' => 'pay_x'], 200)]);

        $this->artisan('cobrancas:gerar')->assertExitCode(0);

        $this->assertDatabaseHas('cobrancas', ['oficina_id' => $oficinaAVencer->id, 'status' => 'PENDENTE']);
        $this->assertDatabaseHas('oficinas', ['id' => $oficinaVencida->id, 'status' => 'INADIMPLENTE']);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/GerarCobrancasRecorrentesCommandTest.php`
Expected: FAIL — comando `cobrancas:gerar` não existe (requer Postgres).

- [ ] **Step 3: Implementar `marcarVencidas()`**

Em `backend/app/Services/CobrancaRecorrenteService.php`, adicionar (depois de `gerarPendentes()`):

```php
    public function marcarVencidas(): int
    {
        $vencidas = Cobranca::where('tipo', 'ASSINATURA')
            ->where('status', 'PENDENTE')
            ->whereDate('vencimento', '<', now()->toDateString())
            ->get();

        foreach ($vencidas as $cobranca) {
            $cobranca->update(['status' => 'VENCIDA']);

            $oficina = Oficina::find($cobranca->oficina_id);
            if ($oficina && $oficina->status === 'ATIVA') {
                $oficina->update(['status' => 'INADIMPLENTE']);
            }
        }

        return $vencidas->count();
    }
```

- [ ] **Step 4: Criar o Command**

```php
<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CobrancaRecorrenteService;
use Illuminate\Console\Command;

class GerarCobrancasRecorrentes extends Command
{
    protected $signature   = 'cobrancas:gerar';
    protected $description = 'Gera cobrancas de assinatura pendentes e marca cobrancas vencidas como VENCIDA';

    public function __construct(private readonly CobrancaRecorrenteService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $geradas  = $this->service->gerarPendentes();
        $vencidas = $this->service->marcarVencidas();

        $this->info("Cobranças geradas: {$geradas}. Cobranças marcadas como vencidas: {$vencidas}.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Agendar no scheduler**

Em `backend/routes/console.php`, adicionar depois da linha `Schedule::command('alertas:verificar')->dailyAt('07:00');`:

```php
Schedule::command('cobrancas:gerar')->dailyAt('06:00');
```

- [ ] **Step 6: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/GerarCobrancasRecorrentesCommandTest.php`
Expected: PASS — 1 teste (requer Postgres).

- [ ] **Step 7: Lint de sintaxe local**

Run: `cd backend && php -l app/Services/CobrancaRecorrenteService.php && php -l app/Console/Commands/GerarCobrancasRecorrentes.php && php -l routes/console.php`
Expected: `No syntax errors detected` nos 3.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Services/CobrancaRecorrenteService.php backend/app/Console/Commands/GerarCobrancasRecorrentes.php backend/routes/console.php backend/tests/Feature/Saas/GerarCobrancasRecorrentesCommandTest.php
git commit -m "feat(saas): marcarVencidas + comando cobrancas:gerar agendado diariamente"
```

---

### Task 7: Reconciliação de pagamento por `payment_id` (Asaas + MP) — remove código de subscription

**Files:**
- Modify: `backend/app/Http/Controllers/SaaS/WebhookController.php`
- Test: `backend/tests/Feature/Saas/WebhookReconciliacaoTest.php` (novo)

**Interfaces:**
- Consumes: `Oficina::avancarVencimento()` (Task 2), coluna `Cobranca.mp_payment_id`/`asaas_payment_id` (já existentes).
- Produces: `WebhookController::asaas()` reconcilia `PAYMENT_CONFIRMED` por `Cobranca.asaas_payment_id`; `WebhookController::mercadopago()` passa a tratar `type=payment` (além de manter a validação HMAC existente), reconciliando por `Cobranca.mp_payment_id`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookReconciliacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComCobranca(string $gateway, string $paymentIdField, string $paymentId): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'INADIMPLENTE', 'gateway' => $gateway,
            'ciclo_cobranca' => 'MENSAL', 'proximo_vencimento' => '2026-08-01',
        ]);
        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => '2026-08-01', 'gateway' => $gateway,
            $paymentIdField => $paymentId,
        ]);
        return [$oficina, $cobranca];
    }

    public function test_asaas_payment_confirmed_reconcilia_por_payment_id(): void
    {
        config(['services.asaas.webhook_token' => 'token-teste']);
        [$oficina, $cobranca] = $this->criarOficinaComCobranca('ASAAS', 'asaas_payment_id', 'pay_asaas_1');

        $response = $this->withHeaders(['asaas-access-token' => 'token-teste'])
            ->postJson('/api/saas/webhooks/asaas', [
                'event'   => 'PAYMENT_CONFIRMED',
                'payment' => ['id' => 'pay_asaas_1', 'value' => 100],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cobrancas', ['id' => $cobranca->id, 'status' => 'PAGA']);
        $this->assertDatabaseHas('oficinas', ['id' => $oficina->id, 'status' => 'ATIVA', 'proximo_vencimento' => '2026-09-01']);
    }

    public function test_mp_payment_aprovado_reconcilia_por_payment_id(): void
    {
        [$oficina, $cobranca] = $this->criarOficinaComCobranca('MERCADOPAGO', 'mp_payment_id', 'pref_mp_1');

        Http::fake([
            '*/v1/payments/mp_pay_1' => Http::response(['id' => 'mp_pay_1', 'status' => 'approved', 'external_reference' => $cobranca->id], 200),
        ]);

        $response = $this->postJson('/api/saas/webhooks/mercadopago?data_id=mp_pay_1', [
            'type' => 'payment',
            'data' => ['id' => 'mp_pay_1'],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cobrancas', ['id' => $cobranca->id, 'status' => 'PAGA']);
        $this->assertDatabaseHas('oficinas', ['id' => $oficina->id, 'status' => 'ATIVA', 'proximo_vencimento' => '2026-09-01']);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/WebhookReconciliacaoTest.php`
Expected: FAIL — o webhook atual procura por `subscription`, não por `payment_id`/`external_reference`, então nenhuma `Cobranca`/`Oficina` é atualizada (requer Postgres).

- [ ] **Step 3: Reescrever `WebhookController.php`**

Substituir o conteúdo inteiro do arquivo:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class WebhookController extends Controller
{
    public function asaas(Request $request): JsonResponse
    {
        $token = $request->header('asaas-access-token');
        if ($token !== config('services.asaas.webhook_token')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event   = $request->input('event');
        $payment = $request->input('payment', []);

        match ($event) {
            'PAYMENT_CONFIRMED' => $this->reconciliarPagamento('asaas_payment_id', $payment['id'] ?? null),
            'PAYMENT_OVERDUE'   => $this->handlePaymentOverdue($payment),
            default             => null,
        };

        return response()->json(['received' => true]);
    }

    private function handlePaymentOverdue(array $payment): void
    {
        $cobranca = Cobranca::where('asaas_payment_id', $payment['id'] ?? null)->first();
        if (!$cobranca) return;

        $cobranca->update(['status' => 'VENCIDA']);

        $oficina = Oficina::find($cobranca->oficina_id);
        if (!$oficina) return;

        $oficina->update(['status' => 'INADIMPLENTE']);

        try {
            Mail::raw(
                "A oficina {$oficina->nome} está inadimplente. Pagamento vencido.",
                fn($m) => $m->to($oficina->admin_email ?? config('mail.from.address'))
                             ->subject("MecânicaPro — Pagamento vencido: {$oficina->nome}")
            );
        } catch (\Throwable) {}
    }

    // ─── Mercado Pago ─────────────────────────────────────────────────────────

    public function mercadopago(Request $request): JsonResponse
    {
        $cfg    = SaasConfig::get();
        $secret = $cfg->getRawOriginal('mp_webhook_secret') ?? '';

        $xSignature = $request->header('x-signature', '');
        $xRequestId = $request->header('x-request-id', '');
        $dataId     = $request->query('data_id', '');

        if ($secret && $xSignature) {
            $manifest = "id:{$dataId};request-id:{$xRequestId};ts:" . $this->extractTs($xSignature) . ';';
            $expected = hash_hmac('sha256', $manifest, $secret);
            $received = $this->extractV1($xSignature);

            if (!hash_equals($expected, $received)) {
                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        $type = $request->input('type', '');
        if ($type !== 'payment') {
            return response()->json(['received' => true]);
        }

        $paymentId = $request->input('data.id');
        if (!$paymentId) return response()->json(['received' => true]);

        $mpToken  = $cfg->getRawOriginal('mp_access_token') ?? '';
        $mpData   = Http::withToken($mpToken)->get("https://api.mercadopago.com/v1/payments/{$paymentId}")->json();
        $mpStatus = $mpData['status'] ?? null;

        if ($mpStatus === 'approved') {
            $cobrancaId = $mpData['external_reference'] ?? null;
            $cobranca   = $cobrancaId ? Cobranca::find($cobrancaId) : null;

            if ($cobranca) {
                $cobranca->update(['mp_payment_id' => $paymentId]);
                $this->reconciliarPagamento('mp_payment_id', $paymentId);
            }
        }

        return response()->json(['received' => true]);
    }

    /** Marca a Cobranca (localizada pelo id de pagamento do gateway) como PAGA e avança o vencimento da oficina. */
    private function reconciliarPagamento(string $campoPaymentId, ?string $paymentId): void
    {
        if (!$paymentId) return;

        $cobranca = Cobranca::where($campoPaymentId, $paymentId)->first();
        if (!$cobranca || $cobranca->status === 'PAGA') return;

        $cobranca->update(['status' => 'PAGA', 'pago_em' => now()]);

        $oficina = Oficina::find($cobranca->oficina_id);
        if (!$oficina) return;

        if ($oficina->proximo_vencimento) {
            $oficina->avancarVencimento();
        }
        if ($oficina->status !== 'ATIVA') {
            $oficina->update(['status' => 'ATIVA']);
        }
    }

    private function extractTs(string $signature): string
    {
        preg_match('/ts=(\d+)/', $signature, $m);
        return $m[1] ?? '';
    }

    private function extractV1(string $signature): string
    {
        preg_match('/v1=([a-f0-9]+)/', $signature, $m);
        return $m[1] ?? '';
    }
}
```

Nota: `PAYMENT_DELETED`/`SUBSCRIPTION_DELETED` (Asaas) e o branch `subscription_preapproval` (MP) foram removidos — nenhuma oficina nova gera subscription (Task 9), então esse código ficaria inalcançável.

- [ ] **Step 4: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/WebhookReconciliacaoTest.php`
Expected: PASS — 2 testes (requer Postgres).

- [ ] **Step 5: Lint de sintaxe local**

Run: `cd backend && php -l app/Http/Controllers/SaaS/WebhookController.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/WebhookController.php backend/tests/Feature/Saas/WebhookReconciliacaoTest.php
git commit -m "fix(saas): webhooks reconciliam pagamento por payment_id em vez de subscription"
```

---

### Task 8: `OficinaController` — cobrança avulsa com id pré-gerado, overrides e `mudarCiclo`

**Files:**
- Modify: `backend/app/Http/Controllers/SaaS/OficinaController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Saas/OficinaCobrancaEndpointsTest.php` (novo)

**Interfaces:**
- Consumes: `AsaasService::criarCobrancaAvulsa(..., ?string $externalReference)` / `MercadoPagoService::criarCobrancaAvulsa(..., ?string $externalReference)` (Tasks 3-4), `Oficina::calcularProximoVencimento()` (Task 2).
- Produces: `PUT /saas/oficinas/{id}` aceita agora `proximo_vencimento`, `dias_antecedencia_cobranca`, `dias_suspensao_vencido`; novo `POST /saas/oficinas/{id}/mudar-ciclo` (`{ciclo: MENSAL|ANUAL}`).

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OficinaCobrancaEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 199.90]);
        return Oficina::create(array_merge([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'MERCADOPAGO',
            'mp_customer_id' => 'cus_mp_1', 'ciclo_cobranca' => 'MENSAL',
            'proximo_vencimento' => now()->addMonth()->toDateString(),
        ], $overrides));
    }

    public function test_atualizar_oficina_aceita_overrides_de_cobranca(): void
    {
        $this->autenticarSuperAdmin();
        $oficina = $this->criarOficina();

        $response = $this->putJson("/api/saas/oficinas/{$oficina->id}", [
            'proximo_vencimento'         => '2026-09-10',
            'dias_antecedencia_cobranca' => 7,
            'dias_suspensao_vencido'     => 15,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('oficinas', [
            'id' => $oficina->id, 'proximo_vencimento' => '2026-09-10',
            'dias_antecedencia_cobranca' => 7, 'dias_suspensao_vencido' => 15,
        ]);
    }

    public function test_mudar_ciclo_para_anual_recalcula_vencimento_e_cancela_pendente(): void
    {
        $this->autenticarSuperAdmin();
        $oficina = $this->criarOficina();

        $cobrancaAntiga = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'PENDENTE', 'vencimento' => $oficina->proximo_vencimento,
        ]);

        $response = $this->postJson("/api/saas/oficinas/{$oficina->id}/mudar-ciclo", ['ciclo' => 'ANUAL']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('oficinas', ['id' => $oficina->id, 'ciclo_cobranca' => 'ANUAL']);
        $this->assertDatabaseHas('cobrancas', ['id' => $cobrancaAntiga->id, 'status' => 'CANCELADA']);

        $oficina->refresh();
        $this->assertSame(now()->addMonths(12)->toDateString(), $oficina->proximo_vencimento->toDateString());
    }

    public function test_gerar_cobranca_avulsa_usa_id_pre_gerado_como_referencia(): void
    {
        $this->autenticarSuperAdmin();
        $oficina = $this->criarOficina();

        Http::fake(['*/checkout/preferences' => Http::response(['id' => 'pref_999', 'init_point' => 'https://mp.test/x'], 200)]);

        $response = $this->postJson("/api/saas/oficinas/{$oficina->id}/gerar-cobranca", [
            'valor' => 199.90, 'vencimento' => now()->addDays(5)->toDateString(),
        ]);

        $response->assertStatus(201);
        $cobrancaId = $response->json('cobranca.id');

        Http::assertSent(fn($request) => $request['external_reference'] === $cobrancaId);
        $this->assertDatabaseHas('cobrancas', ['id' => $cobrancaId, 'mp_payment_id' => 'pref_999']);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/OficinaCobrancaEndpointsTest.php`
Expected: FAIL — `update()` rejeita os campos novos e `mudar-ciclo` não existe (requer Postgres).

- [ ] **Step 3: Atualizar `OficinaController::update()`**

Em `backend/app/Http/Controllers/SaaS/OficinaController.php`, no método `update`, o `validate()` passa a incluir (adicionar dentro do array existente, depois de `'admin_senha'`):

```php
            'proximo_vencimento'         => 'sometimes|date',
            'dias_antecedencia_cobranca' => 'sometimes|nullable|integer|min:0',
            'dias_suspensao_vencido'     => 'sometimes|nullable|integer|min:0',
```

E a linha `$oficinaFields = array_intersect_key(...)` passa a incluir os novos campos na lista de flip:

```php
        $oficinaFields = array_intersect_key($validated, array_flip([
            'nome', 'plano_id', 'status', 'admin_email',
            'proximo_vencimento', 'dias_antecedencia_cobranca', 'dias_suspensao_vencido',
        ]));
```

- [ ] **Step 4: Adicionar `mudarCiclo()`**

Ainda em `OficinaController.php`, adicionar o método (logo abaixo de `sincronizarAssinatura`):

```php
    public function mudarCiclo(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['ciclo' => 'required|in:MENSAL,ANUAL']);
        $oficina   = Oficina::findOrFail($id);

        Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->where('status', 'PENDENTE')
            ->update(['status' => 'CANCELADA']);

        $meses = $validated['ciclo'] === 'ANUAL' ? 12 : 1;
        $oficina->update([
            'ciclo_cobranca'     => $validated['ciclo'],
            'proximo_vencimento' => now()->addMonths($meses)->toDateString(),
        ]);

        return response()->json(['message' => 'Ciclo de cobrança atualizado.', 'data' => [
            'ciclo_cobranca'     => $oficina->ciclo_cobranca,
            'proximo_vencimento' => $oficina->proximo_vencimento->toDateString(),
        ]]);
    }
```

- [ ] **Step 5: Atualizar `gerarCobrancaAvulsa()` para usar id pré-gerado**

Substituir o bloco de criação (o `try`/`catch` + `Cobranca::create`) por:

```php
        $cobrancaId = (string) \Illuminate\Support\Str::uuid();

        try {
            $payment = $gateway === 'MERCADOPAGO'
                ? $this->mercadoPago->criarCobrancaAvulsa($customerId, (float) $validated['valor'], $validated['vencimento'], $cobrancaId)
                : $this->asaas->criarCobrancaAvulsa($customerId, (float) $validated['valor'], $validated['vencimento'], $cobrancaId);
        } catch (\Throwable $e) {
            return response()->json(['message' => "Falha ao criar cobrança no {$nomeGateway}: " . $e->getMessage()], 502);
        }

        $cobranca = Cobranca::create([
            'id'               => $cobrancaId,
            'oficina_id'       => $oficina->id,
            'mes_referencia'   => Carbon::parse($validated['vencimento'])->startOfMonth(),
            'valor'            => $validated['valor'],
            'status'           => 'PENDENTE',
            'gateway'          => $gateway,
            'asaas_payment_id' => $gateway === 'ASAAS' ? ($payment['id'] ?? null) : null,
            'mp_payment_id'    => $gateway === 'MERCADOPAGO' ? ($payment['id'] ?? null) : null,
            'vencimento'       => $validated['vencimento'],
        ]);
```

(o restante do método — cálculo de `$linkPagamento` e o `return response()->json(...)` — permanece igual.)

- [ ] **Step 6: Adicionar a rota**

Em `backend/routes/api.php`, logo abaixo de `Route::post('oficinas/{id}/gerar-cobranca', ...)`:

```php
        Route::post('oficinas/{id}/mudar-ciclo',                [SaaSOficinaController::class, 'mudarCiclo']);
```

- [ ] **Step 7: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/OficinaCobrancaEndpointsTest.php`
Expected: PASS — 3 testes (requer Postgres).

- [ ] **Step 8: Rodar toda a suíte de Saas criada até aqui (regressão)**

Run: `cd backend && php artisan test tests/Feature/Saas`
Expected: PASS — todos os testes das Tasks 3, 4, 5, 6, 7, 8 juntos.

- [ ] **Step 9: Lint de sintaxe local**

Run: `cd backend && php -l app/Http/Controllers/SaaS/OficinaController.php && php -l routes/api.php`
Expected: `No syntax errors detected` nos 2.

- [ ] **Step 10: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/OficinaController.php backend/routes/api.php backend/tests/Feature/Saas/OficinaCobrancaEndpointsTest.php
git commit -m "feat(saas): overrides de cobranca, mudanca de ciclo e cobranca avulsa com id pre-gerado"
```

---

### Task 9: `TenantProvisionService` para de criar subscription

**Files:**
- Modify: `backend/app/Services/TenantProvisionService.php`
- Test: `backend/tests/Feature/Saas/TenantProvisionCobrancaTest.php` (novo)

**Interfaces:**
- Consumes: `AsaasService::criarCustomer(...)` / `MercadoPagoService::criarCustomer(...)` (já existentes, sem mudança de assinatura).
- Produces: oficina provisionada nasce com `ciclo_cobranca = 'MENSAL'` e `proximo_vencimento = hoje->addMonth()`; nenhuma chamada a `criarSubscription`/`preapproval`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Plano;
use App\Services\TenantProvisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantProvisionCobrancaTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisionar_define_ciclo_e_vencimento_sem_criar_subscription(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 199.90]);

        Http::fake([
            '*/customers' => Http::response(['id' => 'cus_novo'], 200),
        ]);

        $oficina = app(TenantProvisionService::class)->provisionar([
            'nome' => 'Nova Oficina', 'cnpj' => '11222333000181', 'slug' => 'nova-oficina-' . uniqid(),
            'plano_id' => $plano->id, 'admin_nome' => 'Admin', 'admin_email' => 'admin@nova.com',
            'admin_cpf' => '52998224725',
        ]);

        $this->assertSame('MENSAL', $oficina->ciclo_cobranca);
        $this->assertSame(now()->addMonth()->toDateString(), $oficina->proximo_vencimento->toDateString());
        Http::assertNotSent(fn($request) => str_contains($request->url(), 'subscriptions') || str_contains($request->url(), 'preapproval'));
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/TenantProvisionCobrancaTest.php`
Expected: FAIL — `ciclo_cobranca`/`proximo_vencimento` continuam nulos e uma chamada a `subscriptions` é feita (requer Postgres).

- [ ] **Step 3: Implementar**

Em `backend/app/Services/TenantProvisionService.php`, substituir o bloco `// 4. Call payment gateway for paid plans (non-blocking)` inteiro por:

```php
            // 4. Call payment gateway for paid plans (non-blocking) — só cria o customer;
            // a cobrança recorrente é gerada pelo CobrancaRecorrenteService a partir do
            // proximo_vencimento, não por subscription nativa do gateway.
            $gateway = SaasConfig::get()->gateway_preferido ?? 'ASAAS';
            $oficina->update([
                'ciclo_cobranca'     => 'MENSAL',
                'proximo_vencimento' => now()->addMonth()->toDateString(),
            ]);

            if ((float) $plano->preco_mensal > 0) {
                try {
                    if ($gateway === 'MERCADOPAGO') {
                        $customer = $this->mercadoPago->criarCustomer(
                            $data['admin_nome'],
                            $data['admin_email'],
                            $data['cnpj'],
                        );
                        $oficina->update([
                            'gateway'        => 'MERCADOPAGO',
                            'mp_customer_id' => $customer['id'],
                        ]);
                    } else {
                        $customer = $this->asaas->criarCustomer(
                            $data['admin_nome'],
                            $data['cnpj'],
                            $data['admin_email']
                        );
                        $oficina->update([
                            'gateway'           => 'ASAAS',
                            'asaas_customer_id' => $customer['id'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Gateway provisioning skipped ({$gateway}): {$e->getMessage()}");
                }
            }
```

- [ ] **Step 4: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/TenantProvisionCobrancaTest.php`
Expected: PASS — 1 teste (requer Postgres).

- [ ] **Step 5: Lint de sintaxe local**

Run: `cd backend && php -l app/Services/TenantProvisionService.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/TenantProvisionService.php backend/tests/Feature/Saas/TenantProvisionCobrancaTest.php
git commit -m "feat(saas): provisionamento de oficina para de criar subscription no gateway"
```

---

### Task 10: `SaasConfigController` — campos globais de cobrança

**Files:**
- Modify: `backend/app/Http/Controllers/SaaS/SaasConfigController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Saas/SaasConfigCobrancaTest.php` (novo)

**Interfaces:**
- Produces: `GET /saas/config` inclui `cobranca_dias_antecedencia_padrao`, `cobranca_dias_suspensao_padrao`, `desconto_anual_pct`. Novo `PUT /saas/config/cobranca`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SaasConfigCobrancaTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_show_inclui_campos_de_cobranca(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->getJson('/api/saas/config');

        $response->assertStatus(200)->assertJsonStructure([
            'data' => ['cobranca_dias_antecedencia_padrao', 'cobranca_dias_suspensao_padrao', 'desconto_anual_pct'],
        ]);
    }

    public function test_update_cobranca_salva_valores(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->putJson('/api/saas/config/cobranca', [
            'cobranca_dias_antecedencia_padrao' => 7,
            'cobranca_dias_suspensao_padrao'    => 12,
            'desconto_anual_pct'                => 15,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('saas_config', [
            'cobranca_dias_antecedencia_padrao' => 7,
            'cobranca_dias_suspensao_padrao'    => 12,
        ]);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/SaasConfigCobrancaTest.php`
Expected: FAIL — `show()` não retorna os campos e `PUT /saas/config/cobranca` não existe (requer Postgres).

- [ ] **Step 3: Atualizar `show()`**

Em `backend/app/Http/Controllers/SaaS/SaasConfigController.php`, dentro do array retornado por `show()`, adicionar (depois de `'smtp_ativo' => (bool) $cfg->smtp_ativo,`):

```php
                'cobranca_dias_antecedencia_padrao' => $cfg->cobranca_dias_antecedencia_padrao,
                'cobranca_dias_suspensao_padrao'    => $cfg->cobranca_dias_suspensao_padrao,
                'desconto_anual_pct'                => (float) $cfg->desconto_anual_pct,
```

- [ ] **Step 4: Adicionar `updateCobranca()`**

Adicionar o método (depois de `updateMercadoPago`):

```php
    public function updateCobranca(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cobranca_dias_antecedencia_padrao' => ['required', 'integer', 'min:1', 'max:60'],
            'cobranca_dias_suspensao_padrao'    => ['required', 'integer', 'min:1', 'max:90'],
            'desconto_anual_pct'                => ['required', 'numeric', 'min:0', 'max:90'],
        ]);

        SaasConfig::get()->update($validated);

        return response()->json(['message' => 'Configurações de cobrança salvas.', 'data' => $validated]);
    }
```

- [ ] **Step 5: Adicionar a rota**

Em `backend/routes/api.php`, logo abaixo de `Route::put('config/mercadopago', ...)`:

```php
        Route::put('config/cobranca', [SaasConfigController::class, 'updateCobranca']);
```

- [ ] **Step 6: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/SaasConfigCobrancaTest.php`
Expected: PASS — 2 testes (requer Postgres).

- [ ] **Step 7: Lint de sintaxe local**

Run: `cd backend && php -l app/Http/Controllers/SaaS/SaasConfigController.php && php -l routes/api.php`
Expected: `No syntax errors detected` nos 2.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/SaasConfigController.php backend/routes/api.php backend/tests/Feature/Saas/SaasConfigCobrancaTest.php
git commit -m "feat(saas): configuracoes globais de cobranca (antecedencia, suspensao, desconto anual)"
```

---

### Task 11: Frontend — Configurações (3 campos de cobrança)

**Files:**
- Modify: `frontend/app/saas-admin/(protected)/configuracoes/page.tsx`

**Interfaces:**
- Consumes: `GET /saas/config` (campos novos de Task 10), `PUT /saas/config/cobranca`.

- [ ] **Step 1: Adicionar os campos ao `interface SaasConfigData`**

Em `frontend/app/saas-admin/(protected)/configuracoes/page.tsx`, dentro de `SaasConfigData` (depois de `focus_master_token_producao: string | null`):

```typescript
  cobranca_dias_antecedencia_padrao: number
  cobranca_dias_suspensao_padrao: number
  desconto_anual_pct: number
```

- [ ] **Step 2: Adicionar estado e carregamento**

Depois do bloco `// Fiscal` (variáveis `provedorFiscal` etc.), adicionar:

```typescript
  // Cobrança
  const [diasAntecedencia, setDiasAntecedencia] = useState('5')
  const [diasSuspensao, setDiasSuspensao] = useState('10')
  const [descontoAnual, setDescontoAnual] = useState('0')
  const [savingCobranca, setSavingCobranca] = useState(false)
```

No `useEffect` que carrega `/saas/config`, adicionar depois de `setFocusProducao(...)`:

```typescript
        setDiasAntecedencia(String(d.cobranca_dias_antecedencia_padrao ?? 5))
        setDiasSuspensao(String(d.cobranca_dias_suspensao_padrao ?? 10))
        setDescontoAnual(String(d.desconto_anual_pct ?? 0))
```

- [ ] **Step 3: Adicionar a função de salvar**

Depois de `salvarProvedorFiscal`, adicionar:

```typescript
  async function salvarCobranca() {
    setSavingCobranca(true)
    try {
      await saasApi.put('/saas/config/cobranca', {
        cobranca_dias_antecedencia_padrao: parseInt(diasAntecedencia, 10) || 5,
        cobranca_dias_suspensao_padrao: parseInt(diasSuspensao, 10) || 10,
        desconto_anual_pct: parseFloat(descontoAnual) || 0,
      })
      showToast('Configurações de cobrança salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar configurações de cobrança.', 'danger')
    } finally {
      setSavingCobranca(false)
    }
  }
```

- [ ] **Step 4: Adicionar a seção visual**

Logo antes de `{/* ── Seção 6 — Evolution API (WhatsApp) ── */}`, adicionar:

```tsx
      {/* ── Seção 5.5 — Cobrança Recorrente ─────────────────────────────── */}
      <SectionCard
        title="Cobrança Recorrente"
        subtitle="Regras padrão de geração de cobrança, suspensão por atraso e desconto anual — cada oficina pode sobrescrever antecedência e suspensão individualmente"
      >
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Dias de antecedência p/ gerar cobrança
            </label>
            <input value={diasAntecedencia} onChange={e => setDiasAntecedencia(e.target.value)} type="number" min={1} max={60}
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Dias para suspensão após vencimento
            </label>
            <input value={diasSuspensao} onChange={e => setDiasSuspensao(e.target.value)} type="number" min={1} max={90}
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
        </div>
        <div style={{ marginBottom: 16, maxWidth: 260 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
            Desconto pagamento anual (%)
          </label>
          <input value={descontoAnual} onChange={e => setDescontoAnual(e.target.value)} type="number" min={0} max={90} step="0.5"
            style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
        </div>
        <SaveButton loading={savingCobranca} onClick={salvarCobranca} label="Salvar Cobrança" />
      </SectionCard>

```

- [ ] **Step 5: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p tsconfig.json`
Expected: sem erros no arquivo `configuracoes/page.tsx` (nenhuma linha desse arquivo na saída).

- [ ] **Step 6: Commit**

```bash
git add "frontend/app/saas-admin/(protected)/configuracoes/page.tsx"
git commit -m "feat(saas): tela de configuracoes globais de cobranca recorrente"
```

---

### Task 12: Frontend — Detalhe da oficina (ciclo, vencimento, overrides)

**Files:**
- Modify: `frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx`

**Interfaces:**
- Consumes: `PUT /saas/oficinas/{id}` com `proximo_vencimento`/`dias_antecedencia_cobranca`/`dias_suspensao_vencido` (Task 8), `POST /saas/oficinas/{id}/mudar-ciclo` (Task 8). Backend `formatOficina()` precisa expor esses campos — ver Step 1.

- [ ] **Step 1: Expor os campos no backend `formatOficina()`**

Em `backend/app/Http/Controllers/SaaS/OficinaController.php`, no método `formatOficina`, adicionar ao array retornado (depois de `'emissao_fiscal_modo' => $oficina->emissao_fiscal_modo,`):

```php
            'ciclo_cobranca'             => $oficina->ciclo_cobranca,
            'proximo_vencimento'         => $oficina->proximo_vencimento?->toDateString(),
            'dias_antecedencia_cobranca' => $oficina->dias_antecedencia_cobranca,
            'dias_suspensao_vencido'     => $oficina->dias_suspensao_vencido,
```

Run: `cd backend && php -l app/Http/Controllers/SaaS/OficinaController.php`
Expected: `No syntax errors detected`

- [ ] **Step 2: Atualizar a interface `Oficina` no frontend**

Em `frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx`, dentro de `interface Oficina` (depois de `emissao_fiscal_modo?: 'MANUAL' | 'AUTOMATICO' | null`):

```typescript
  ciclo_cobranca?: 'MENSAL' | 'ANUAL'
  proximo_vencimento?: string | null
  dias_antecedencia_cobranca?: number | null
  dias_suspensao_vencido?: number | null
```

- [ ] **Step 3: Adicionar estado e sincronização**

Depois do bloco `const [provFiscal, setProvFiscal] = useState<string>('')` etc., adicionar:

```typescript
  const [proximoVencimento, setProximoVencimento] = useState('')
  const [diasAntecedenciaOverride, setDiasAntecedenciaOverride] = useState('')
  const [diasSuspensaoOverride, setDiasSuspensaoOverride] = useState('')
  const [savingCobranca, setSavingCobranca] = useState(false)
  const [changingCiclo, setChangingCiclo] = useState(false)
```

No `fetchOficina`, depois de `setModoFiscal(res.data.data.emissao_fiscal_modo ?? '')`, adicionar:

```typescript
      setProximoVencimento(res.data.data.proximo_vencimento ?? '')
      setDiasAntecedenciaOverride(res.data.data.dias_antecedencia_cobranca != null ? String(res.data.data.dias_antecedencia_cobranca) : '')
      setDiasSuspensaoOverride(res.data.data.dias_suspensao_vencido != null ? String(res.data.data.dias_suspensao_vencido) : '')
```

- [ ] **Step 4: Adicionar as funções de ação**

Depois de `salvarFiscal`, adicionar:

```typescript
  async function salvarCobranca() {
    setSavingCobranca(true)
    try {
      const payload: Record<string, string | number | null> = {}
      if (proximoVencimento) payload.proximo_vencimento = proximoVencimento
      payload.dias_antecedencia_cobranca = diasAntecedenciaOverride ? parseInt(diasAntecedenciaOverride, 10) : null
      payload.dias_suspensao_vencido = diasSuspensaoOverride ? parseInt(diasSuspensaoOverride, 10) : null

      await saasApi.put(`/saas/oficinas/${id}`, payload)
      showToast('Configurações de cobrança salvas.')
      fetchOficina()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao salvar.'
      showToast(msg, 'err')
    } finally {
      setSavingCobranca(false)
    }
  }

  async function mudarCiclo(ciclo: 'MENSAL' | 'ANUAL') {
    if (!confirm(`Mudar o ciclo de cobrança para ${ciclo === 'ANUAL' ? 'ANUAL' : 'MENSAL'}? Isso recalcula o próximo vencimento e cancela cobranças pendentes do ciclo atual.`)) return
    setChangingCiclo(true)
    try {
      await saasApi.post(`/saas/oficinas/${id}/mudar-ciclo`, { ciclo })
      showToast('Ciclo de cobrança atualizado.')
      fetchOficina()
      fetchCobrancas()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao mudar ciclo.'
      showToast(msg, 'err')
    } finally {
      setChangingCiclo(false)
    }
  }
```

- [ ] **Step 5: Adicionar a seção visual**

Logo antes de `{/* ── Últimos Pagamentos Asaas ── */}`, adicionar:

```tsx
        {/* ── Cobrança Recorrente ── */}
        <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 20, marginTop: 20 }}>
          <h2 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: '0 0 4px' }}>Cobrança Recorrente</h2>
          <p style={{ fontSize: 13, color: 'var(--muted)', margin: '0 0 16px' }}>
            Deixe os dias em branco para herdar o padrão global (Configurações).
          </p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
            <span style={{ fontSize: 13, color: 'var(--muted)' }}>Ciclo atual:</span>
            <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: 'rgba(245,166,35,.15)', color: 'var(--accent)' }}>
              {oficina?.ciclo_cobranca ?? 'MENSAL'}
            </span>
            {oficina?.ciclo_cobranca !== 'ANUAL' && (
              <button onClick={() => mudarCiclo('ANUAL')} disabled={changingCiclo}
                style={{ padding: '5px 12px', background: 'none', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 6, fontSize: 12, cursor: 'pointer' }}>
                Mudar para Anual
              </button>
            )}
            {oficina?.ciclo_cobranca === 'ANUAL' && (
              <button onClick={() => mudarCiclo('MENSAL')} disabled={changingCiclo}
                style={{ padding: '5px 12px', background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, fontSize: 12, cursor: 'pointer' }}>
                Voltar para Mensal
              </button>
            )}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16, maxWidth: 700 }}>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Próximo vencimento</label>
              <input type="date" value={proximoVencimento} onChange={e => setProximoVencimento(e.target.value)}
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none', colorScheme: 'dark' }} />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Dias de antecedência (override)</label>
              <input type="number" min={1} max={60} value={diasAntecedenciaOverride} onChange={e => setDiasAntecedenciaOverride(e.target.value)} placeholder="Padrão global"
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none' }} />
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Dias p/ suspensão (override)</label>
              <input type="number" min={1} max={90} value={diasSuspensaoOverride} onChange={e => setDiasSuspensaoOverride(e.target.value)} placeholder="Padrão global"
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none' }} />
            </div>
          </div>
          <button onClick={salvarCobranca} disabled={savingCobranca}
            style={{ marginTop: 16, padding: '8px 18px', background: savingCobranca ? 'var(--border)' : 'var(--accent)', color: savingCobranca ? 'var(--muted)' : '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 13, fontFamily: "'Barlow Condensed', sans-serif", cursor: savingCobranca ? 'not-allowed' : 'pointer' }}>
            {savingCobranca ? 'Salvando…' : 'Salvar Cobrança'}
          </button>
        </div>

```

- [ ] **Step 6: Rotular "Subscription ID" como legado**

No card hoje rotulado "Asaas", trocar o label da linha de subscription (`InfoRow label="Subscription ID"`) para deixar claro que é histórico:

```tsx
                <InfoRow label="Subscription ID (legado)" value={
                  asaas?.asaas_subscription_id
                    ? <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 12 }}>{asaas.asaas_subscription_id}</span>
                    : <span style={{ color: 'var(--muted)' }}>Não usado (motor de cobrança local)</span>
                } />
```

- [ ] **Step 7: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p tsconfig.json`
Expected: sem erros no arquivo `oficinas/[id]/page.tsx` (nenhuma linha desse arquivo na saída).

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/OficinaController.php "frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx"
git commit -m "feat(saas): tela da oficina exibe e edita ciclo/vencimento/overrides de cobranca"
```

---

## Pós-implementação (não automatizado neste plano)

- Rodar toda a suíte (`php artisan test`) num ambiente com Postgres antes de mergear/deployar — este plano só conseguiu validar sintaxe (`php -l`) e a suíte `tests/Unit` neste sandbox sem banco.
- Migrar as oficinas existentes: para cada uma, cancelar a subscription antiga (`POST /saas/oficinas/{id}/cancelar-assinatura`, já corrigida) e preencher `proximo_vencimento` manualmente na tela (Task 12).
- Confirmar no Mercado Pago que a URL de webhook está registrada para eventos `payment` (não só `subscription_preapproval`).
