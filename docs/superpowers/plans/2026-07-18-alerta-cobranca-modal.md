# Alerta de Cobrança + Modal de Pagamento Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modal in-app (não WhatsApp/e-mail) que avisa a oficina quando existe uma
cobrança de assinatura pendente ou vencida, com botão de pagamento (link
hospedado do gateway) e um CTA pra trocar de mensal pra anual com desconto —
tudo controlado pela plataforma (frequência/duração configuráveis pelo SaaS
admin, oficina não pode apagar o alerta), liga/desliga sozinho conforme o
estado real da cobrança.

**Architecture:** Sem tabela de "template" nova — o estado ativo/inativo é
sempre computado a partir da `Cobranca` mais recente (`tipo=ASSINATURA`,
`status in PENDENTE|VENCIDA`) da oficina, reaproveitando 100% do motor de
cobrança já existente. Só 2 números de cadência (vezes/dia, dias de exibição)
ficam configuráveis, guardados em `saas_config`. Throttle de exibição por
oficina guardado direto em 2 colunas de `oficinas` (mesmo padrão já usado).
Endpoint tenant-side novo (`GET /assinatura/alerta`) calcula fase + mensagem +
já registra a exibição numa única chamada. Frontend: componente novo montado
no layout do dashboard, ao lado do `NotificacaoModal` existente, mas com
estado 100% server-side (sem `localStorage`).

**Tech Stack:** Laravel 12 / PHP 8.2 / PostgreSQL 16 (backend), Next.js 16 /
TypeScript (frontend, área da oficina — `app/(dashboard)/`).

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo/editado.
- UUID PKs, `$incrementing = false`, `$timestamps = false` — siga o padrão
  já usado em `Oficina`/`Cobranca`/`SaasConfig`.
- Sem `Docker`/Postgres neste ambiente de desenvolvimento: `php artisan test
  tests/Unit/...` roda localmente; testes de Feature (usam `RefreshDatabase`)
  exigem Postgres — cada task deixa claro qual caso se aplica.
- Datas expostas em JSON como `toDateString()` (`YYYY-MM-DD`).
- Os dois botões de pagamento ("Pagar com PIX" / "Pagar com Cartão") abrem a
  MESMA URL (`link_pagamento`, o checkout hospedado do gateway) — não existe
  fluxo nativo de PIX/cartão nesta spec.
- O CTA de troca de ciclo só aparece pra usuário com `role === 'ADMIN'`
  (frontend) e o endpoint correspondente exige `role:ADMIN` (backend).

---

### Task 1: Middleware — `INADIMPLENTE` não bloqueia mais o tenant

**Contexto (bug pré-existente descoberto ao planejar esta feature, já em
produção):** `InitializeTenancyByHeader` retorna `402` pra QUALQUER rota
tenant assim que `oficina.status === 'INADIMPLENTE'`. Como o motor de
cobrança recorrente (feature anterior, já deployada) marca a oficina como
`INADIMPLENTE` automaticamente no primeiro dia de atraso
(`CobrancaRecorrenteService::marcarVencidas()`), isso bloqueia 100% da API —
incluindo o próprio `GET /assinatura/alerta` que esta feature está criando —
no exato momento em que o aviso "sua fatura venceu, você será suspensa em N
dias" deveria começar a aparecer. Sem este fix, o modal desta spec nunca
seria alcançável no estado vencido.

`SUSPENSA` continua bloqueando (é o estado que o próximo subsistema —
suspensão automática — vai efetivamente usar). `INADIMPLENTE` passa a
significar "cobrança em aberto, oficina nagueada pelo alerta, mas
funcionando normalmente" — exatamente o período de carência que o usuário
pediu ("avisa X dias antes de suspender").

**Files:**
- Modify: `backend/app/Http/Middleware/InitializeTenancyByHeader.php`
- Test: `backend/tests/Feature/TenancyInadimplenteTest.php` (novo)

**Interfaces:**
- Produces: oficina `INADIMPLENTE` deixa de retornar `402` em rotas tenant;
  `SUSPENSA`/`CANCELADA` continuam bloqueando exatamente como hoje.

- [ ] **Step 1: Escrever o teste**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenancyInadimplenteTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComUsuario(string $status): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => $status,
        ]);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
            'oficina_id' => $oficina->id,
        ]);

        return [$oficina, $usuario];
    }

    public function test_oficina_inadimplente_nao_e_bloqueada(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('INADIMPLENTE');

        $response = $this->withHeaders(['X-Tenant' => $oficina->slug])
            ->actingAs($usuario)
            ->getJson('/api/dashboard');

        $response->assertStatus(200);
    }

    public function test_oficina_suspensa_continua_bloqueada(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('SUSPENSA');

        $response = $this->withHeaders(['X-Tenant' => $oficina->slug])
            ->actingAs($usuario)
            ->getJson('/api/dashboard');

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/TenancyInadimplenteTest.php`
Expected: `test_oficina_inadimplente_nao_e_bloqueada` FAIL com `402` em vez de
`200` (requer Postgres pra rodar de verdade; sem banco neste sandbox,
confirme por leitura do middleware atual que o bloco `INADIMPLENTE` existe
e retorna 402 antes de chegar no controller).

- [ ] **Step 3: Remover o bloco `INADIMPLENTE`**

Em `backend/app/Http/Middleware/InitializeTenancyByHeader.php`, remover
inteiramente este bloco (mantendo os de `CANCELADA` e `SUSPENSA` intactos):

```php
            if ($oficina->status === 'INADIMPLENTE') {
                return response()->json(['message' => 'Pagamento pendente. Regularize sua assinatura para continuar.'], 402);
            }
```

- [ ] **Step 4: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/TenancyInadimplenteTest.php`
Expected: PASS — 2 testes (requer Postgres pra rodar de verdade; sem banco
aqui, confirme por leitura que só o bloco `SUSPENSA` restou no arquivo).

- [ ] **Step 5: Sintaxe**

Run: `cd backend && php -l app/Http/Middleware/InitializeTenancyByHeader.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Middleware/InitializeTenancyByHeader.php backend/tests/Feature/TenancyInadimplenteTest.php
git commit -m "fix(tenancy): oficina inadimplente nao bloqueia mais a API inteira (so suspensa bloqueia)"
```

---

### Task 2: Migrations + persistência do `link_pagamento`

**Files:**
- Create: `backend/database/migrations/2026_07_18_000003_add_alerta_cobranca_fields_to_saas_config_table.php`
- Create: `backend/database/migrations/2026_07_18_000004_add_alerta_cobranca_fields_to_oficinas_table.php`
- Create: `backend/database/migrations/2026_07_18_000005_add_link_pagamento_to_cobrancas_table.php`
- Modify: `backend/app/Models/SaasConfig.php`
- Modify: `backend/app/Models/Oficina.php`
- Modify: `backend/app/Models/Cobranca.php`
- Modify: `backend/app/Services/CobrancaRecorrenteService.php`
- Modify: `backend/app/Http/Controllers/SaaS/OficinaController.php`
- Test: `backend/tests/Feature/Saas/CobrancaLinkPagamentoTest.php` (novo)

**Interfaces:**
- Produces: `saas_config.alerta_cobranca_vezes_dia` (int, default 1),
  `saas_config.alerta_cobranca_dias_exibicao` (int, default 30);
  `oficinas.alerta_cobranca_exibicoes_hoje` (int, default 0),
  `oficinas.alerta_cobranca_ultima_exibicao_em` (date, nullable);
  `cobrancas.link_pagamento` (string, nullable) — passa a ser preenchido em
  toda `Cobranca` criada por `CobrancaRecorrenteService` e por
  `OficinaController::gerarCobrancaAvulsa`.

- [ ] **Step 1: Migration `saas_config`**

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
            $table->integer('alerta_cobranca_vezes_dia')->default(1);
            $table->integer('alerta_cobranca_dias_exibicao')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn(['alerta_cobranca_vezes_dia', 'alerta_cobranca_dias_exibicao']);
        });
    }
};
```

- [ ] **Step 2: Migration `oficinas`**

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
            $table->integer('alerta_cobranca_exibicoes_hoje')->default(0);
            $table->date('alerta_cobranca_ultima_exibicao_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oficinas', function (Blueprint $table) {
            $table->dropColumn(['alerta_cobranca_exibicoes_hoje', 'alerta_cobranca_ultima_exibicao_em']);
        });
    }
};
```

- [ ] **Step 3: Migration `cobrancas`**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->string('link_pagamento', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->dropColumn('link_pagamento');
        });
    }
};
```

- [ ] **Step 4: Sintaxe das 3 migrations**

Run: `cd backend && php -l database/migrations/2026_07_18_000003_add_alerta_cobranca_fields_to_saas_config_table.php && php -l database/migrations/2026_07_18_000004_add_alerta_cobranca_fields_to_oficinas_table.php && php -l database/migrations/2026_07_18_000005_add_link_pagamento_to_cobrancas_table.php`
Expected: `No syntax errors detected` nas 3.

- [ ] **Step 5: Atualizar `SaasConfig.php`**

Adicionar ao `$fillable` (depois de `'desconto_anual_pct',`):
```php
        'alerta_cobranca_vezes_dia',
        'alerta_cobranca_dias_exibicao',
```
E ao `$casts`:
```php
        'alerta_cobranca_vezes_dia'     => 'integer',
        'alerta_cobranca_dias_exibicao' => 'integer',
```

- [ ] **Step 6: Atualizar `Oficina.php`**

Adicionar ao `$fillable` (depois de `'dias_suspensao_vencido',`):
```php
        'alerta_cobranca_exibicoes_hoje',
        'alerta_cobranca_ultima_exibicao_em',
```
E ao `$casts`:
```php
        'alerta_cobranca_exibicoes_hoje'     => 'integer',
        'alerta_cobranca_ultima_exibicao_em' => 'date',
```

- [ ] **Step 7: Atualizar `Cobranca.php`**

Adicionar `'link_pagamento',` ao `$fillable` (depois de `'vencimento',`).

- [ ] **Step 8: Persistir `link_pagamento` em `CobrancaRecorrenteService`**

Em `backend/app/Services/CobrancaRecorrenteService.php`, dentro de
`criarCobranca()`, logo depois da linha `$payment = $gateway === 'MERCADOPAGO' ...`
(depois do bloco `try/catch`), calcular o link:

```php
        $linkPagamento = $gateway === 'MERCADOPAGO'
            ? ($payment['init_point'] ?? null)
            : ($payment['invoiceUrl'] ?? null);
```

E adicionar `'link_pagamento' => $linkPagamento,` ao array de
`Cobranca::create([...])` logo abaixo de `'vencimento' => $vencimento,`.

- [ ] **Step 9: Persistir `link_pagamento` em `gerarCobrancaAvulsa`**

Em `backend/app/Http/Controllers/SaaS/OficinaController.php`, dentro de
`gerarCobrancaAvulsa()`, a linha atual:
```php
        $linkPagamento = $gateway === 'MERCADOPAGO' ? ($payment['init_point'] ?? null) : null;
```
vira:
```php
        $linkPagamento = $gateway === 'MERCADOPAGO'
            ? ($payment['init_point'] ?? null)
            : ($payment['invoiceUrl'] ?? null);
```
E adicionar `'link_pagamento' => $linkPagamento,` ao array de
`Cobranca::create([...])` logo abaixo de `'vencimento' => $validated['vencimento'],`.

- [ ] **Step 10: Escrever o teste**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Services\CobrancaRecorrenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CobrancaLinkPagamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_motor_recorrente_persiste_link_pagamento_asaas(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'ASAAS',
            'asaas_customer_id' => 'cus_1', 'proximo_vencimento' => now()->addDays(2)->toDateString(),
        ]);

        Http::fake(['*/payments' => Http::response([
            'id' => 'pay_x', 'invoiceUrl' => 'https://asaas.test/i/pay_x',
        ], 200)]);

        app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertDatabaseHas('cobrancas', [
            'oficina_id'     => $oficina->id,
            'link_pagamento' => 'https://asaas.test/i/pay_x',
        ]);
    }

    public function test_motor_recorrente_persiste_link_pagamento_mercadopago(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste MP', 'cnpj' => '11222333000271', 'slug' => 'teste-mp-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'gateway' => 'MERCADOPAGO',
            'mp_customer_id' => 'cus_mp_1', 'proximo_vencimento' => now()->addDays(2)->toDateString(),
        ]);

        Http::fake(['*/checkout/preferences' => Http::response([
            'id' => 'pref_x', 'init_point' => 'https://mp.test/checkout/pref_x',
        ], 200)]);

        app(CobrancaRecorrenteService::class)->gerarPendentes();

        $this->assertDatabaseHas('cobrancas', [
            'oficina_id'     => $oficina->id,
            'link_pagamento' => 'https://mp.test/checkout/pref_x',
        ]);
    }
}
```

- [ ] **Step 11: Rodar o teste (requer Postgres — indisponível neste
  sandbox; verificar via `php -l` + rastreamento manual como nas tasks
  anteriores desta sessão)**

Run: `cd backend && php artisan test tests/Feature/Saas/CobrancaLinkPagamentoTest.php`
Expected: PASS — 2 testes (só roda de fato num ambiente com Postgres).

- [ ] **Step 12: Sintaxe dos arquivos editados**

Run: `cd backend && php -l app/Models/SaasConfig.php && php -l app/Models/Oficina.php && php -l app/Models/Cobranca.php && php -l app/Services/CobrancaRecorrenteService.php && php -l app/Http/Controllers/SaaS/OficinaController.php`
Expected: `No syntax errors detected` em todos.

- [ ] **Step 13: Commit**

```bash
git add backend/database/migrations/2026_07_18_000003_add_alerta_cobranca_fields_to_saas_config_table.php backend/database/migrations/2026_07_18_000004_add_alerta_cobranca_fields_to_oficinas_table.php backend/database/migrations/2026_07_18_000005_add_link_pagamento_to_cobrancas_table.php backend/app/Models/SaasConfig.php backend/app/Models/Oficina.php backend/app/Models/Cobranca.php backend/app/Services/CobrancaRecorrenteService.php backend/app/Http/Controllers/SaaS/OficinaController.php backend/tests/Feature/Saas/CobrancaLinkPagamentoTest.php
git commit -m "feat(saas): campos de alerta de cobranca + persistencia do link de pagamento"
```

---

### Task 3: `AssinaturaService` — extrai `mudarCiclo` compartilhado

**Files:**
- Create: `backend/app/Services/AssinaturaService.php`
- Modify: `backend/app/Http/Controllers/SaaS/OficinaController.php`
- Test: `backend/tests/Feature/Saas/AssinaturaServiceTest.php` (novo)

**Interfaces:**
- Produces: `AssinaturaService::mudarCiclo(Oficina $oficina, string $ciclo): void`
  — mesma lógica que hoje vive só em `OficinaController::mudarCiclo`; passa a
  ser reaproveitada pelo endpoint tenant-side da Task 4.

- [ ] **Step 1: Escrever o teste**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Services\AssinaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssinaturaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mudar_ciclo_cancela_pendente_e_recalcula_vencimento(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'ciclo_cobranca' => 'MENSAL',
            'proximo_vencimento' => now()->addMonth()->toDateString(),
        ]);

        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => $oficina->proximo_vencimento,
        ]);

        app(AssinaturaService::class)->mudarCiclo($oficina, 'ANUAL');

        $this->assertDatabaseHas('cobrancas', ['id' => $cobranca->id, 'status' => 'CANCELADA']);
        $oficina->refresh();
        $this->assertSame('ANUAL', $oficina->ciclo_cobranca);
        $this->assertSame(now()->addMonths(12)->toDateString(), $oficina->proximo_vencimento->toDateString());
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/AssinaturaServiceTest.php`
Expected: FAIL — `Class "App\Services\AssinaturaService" not found` (requer
Postgres pra rodar de verdade; sem banco neste sandbox, confirme a falha
lendo o código — a classe realmente não existe ainda).

- [ ] **Step 3: Criar `AssinaturaService`**

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Oficina;

class AssinaturaService
{
    /** Cancela a cobrança de assinatura pendente do ciclo atual e recalcula o vencimento a partir de hoje. */
    public function mudarCiclo(Oficina $oficina, string $ciclo): void
    {
        Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->where('status', 'PENDENTE')
            ->update(['status' => 'CANCELADA']);

        $meses = $ciclo === 'ANUAL' ? 12 : 1;
        $oficina->update([
            'ciclo_cobranca'     => $ciclo,
            'proximo_vencimento' => now()->addMonths($meses)->toDateString(),
        ]);
    }
}
```

- [ ] **Step 4: Atualizar `OficinaController` pra usar o service**

Adicionar `private AssinaturaService $assinatura,` ao final da lista de
parâmetros do construtor (depois de `private EntitlementService $ent,`).

Substituir o corpo de `mudarCiclo()`:
```php
    public function mudarCiclo(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['ciclo' => 'required|in:MENSAL,ANUAL']);
        $oficina   = Oficina::findOrFail($id);

        $this->assinatura->mudarCiclo($oficina, $validated['ciclo']);

        return response()->json(['message' => 'Ciclo de cobrança atualizado.', 'data' => [
            'ciclo_cobranca'     => $oficina->ciclo_cobranca,
            'proximo_vencimento' => $oficina->proximo_vencimento->toDateString(),
        ]]);
    }
```
Adicionar `use App\Services\AssinaturaService;` aos imports do topo do arquivo.

- [ ] **Step 5: Rodar o teste novo + a suíte de Saas inteira (regressão —
  garante que `OficinaCobrancaEndpointsTest::test_mudar_ciclo_...` continua
  passando com o comportamento idêntico via service)**

Run: `cd backend && php artisan test tests/Feature/Saas`
Expected: PASS — todos os testes (requer Postgres; sem banco aqui, confirme
por leitura que `mudarCiclo()` do controller chama exatamente o mesmo
`Cobranca::where(...)->update(...)` + `Oficina::update(...)` que o teste
existente `OficinaCobrancaEndpointsTest::test_mudar_ciclo_para_anual_...`
já cobria antes desta mudança).

- [ ] **Step 6: Sintaxe**

Run: `cd backend && php -l app/Services/AssinaturaService.php && php -l app/Http/Controllers/SaaS/OficinaController.php`
Expected: `No syntax errors detected` nos 2.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/AssinaturaService.php backend/app/Http/Controllers/SaaS/OficinaController.php backend/tests/Feature/Saas/AssinaturaServiceTest.php
git commit -m "refactor(saas): extrai AssinaturaService::mudarCiclo pra reuso tenant-side"
```

---

### Task 4: `AssinaturaAlertaService` — fase, mensagem e throttle de exibição

**Files:**
- Create: `backend/app/Services/AssinaturaAlertaService.php`
- Test: `backend/tests/Feature/Saas/AssinaturaAlertaServiceTest.php` (novo)

**Interfaces:**
- Consumes: `Cobranca` (`tipo`, `status`, `vencimento`, `valor`,
  `link_pagamento`), `Oficina` (`dias_suspensao_vencido`,
  `alerta_cobranca_exibicoes_hoje`, `alerta_cobranca_ultima_exibicao_em`,
  `ciclo_cobranca`), `SaasConfig` (`alerta_cobranca_vezes_dia`,
  `alerta_cobranca_dias_exibicao`, `cobranca_dias_suspensao_padrao`,
  `desconto_anual_pct`) — todos de Tasks anteriores.
- Produces: `AssinaturaAlertaService::status(Oficina $oficina): array` —
  retorna `['show' => false]` ou `['show' => true, 'fase', 'mensagem',
  'valor', 'vencimento', 'link_pagamento', 'ciclo_atual',
  'desconto_anual_pct']`. Efeito colateral: se `show === true`, já
  incrementa o contador de exibições do dia da oficina. Consumida pelo
  controller tenant-side da Task 4.

- [ ] **Step 1: Escrever o teste**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Services\AssinaturaAlertaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssinaturaAlertaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        return Oficina::create(array_merge([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'ciclo_cobranca' => 'MENSAL',
        ], $overrides));
    }

    public function test_sem_cobranca_pendente_nao_mostra(): void
    {
        $oficina = $this->criarOficina();

        $status = app(AssinaturaAlertaService::class)->status($oficina);

        $this->assertFalse($status['show']);
    }

    public function test_cobranca_pendente_mostra_fase_disponivel(): void
    {
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
            'link_pagamento' => 'https://gateway.test/pay/1',
        ]);

        $status = app(AssinaturaAlertaService::class)->status($oficina);

        $this->assertTrue($status['show']);
        $this->assertSame('DISPONIVEL', $status['fase']);
        $this->assertStringContainsString('disponível para pagamento', $status['mensagem']);
        $this->assertSame('https://gateway.test/pay/1', $status['link_pagamento']);
    }

    public function test_cobranca_vencida_mostra_fase_vencida_com_contagem_de_suspensao(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(3)->toDateString(),
        ]);

        $status = app(AssinaturaAlertaService::class)->status($oficina);

        $this->assertTrue($status['show']);
        $this->assertSame('VENCIDA', $status['fase']);
        $this->assertStringContainsString('evite a suspensão', $status['mensagem']);
        $this->assertStringContainsString('7 dias', $status['mensagem']);
    }

    public function test_respeita_limite_de_vezes_por_dia(): void
    {
        SaasConfig::get()->update(['alerta_cobranca_vezes_dia' => 1]);
        $oficina = $this->criarOficina([
            'alerta_cobranca_exibicoes_hoje'     => 1,
            'alerta_cobranca_ultima_exibicao_em' => now()->toDateString(),
        ]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);

        $status = app(AssinaturaAlertaService::class)->status($oficina);

        $this->assertFalse($status['show']);
    }

    public function test_exibicao_incrementa_contador_do_dia(): void
    {
        SaasConfig::get()->update(['alerta_cobranca_vezes_dia' => 3]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);

        app(AssinaturaAlertaService::class)->status($oficina);

        $oficina->refresh();
        $this->assertSame(1, $oficina->alerta_cobranca_exibicoes_hoje);
        $this->assertSame(now()->toDateString(), $oficina->alerta_cobranca_ultima_exibicao_em->toDateString());
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/AssinaturaAlertaServiceTest.php`
Expected: FAIL — classe não existe (requer Postgres pra rodar; sem banco
neste sandbox, confirme por leitura que `AssinaturaAlertaService` ainda não
existe no repositório).

- [ ] **Step 3: Implementar**

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;

class AssinaturaAlertaService
{
    public function status(Oficina $oficina): array
    {
        $cobranca = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->whereIn('status', ['PENDENTE', 'VENCIDA'])
            ->orderByDesc('vencimento')
            ->first();

        if (!$cobranca) {
            return ['show' => false];
        }

        $cfg = SaasConfig::get();

        if ($cobranca->status === 'PENDENTE') {
            $diasParaVencer = (int) now()->diffInDays($cobranca->vencimento, false);
            if ($diasParaVencer > ($cfg->alerta_cobranca_dias_exibicao ?? 30)) {
                return ['show' => false];
            }

            $fase     = 'DISPONIVEL';
            $mensagem = 'Sua fatura de ' . $this->formatarMoeda((float) $cobranca->valor)
                . ' está disponível para pagamento. Vencimento: ' . $cobranca->vencimento->format('d/m/Y') . '.';
        } else {
            $diasVencida   = (int) $cobranca->vencimento->diffInDays(now());
            $diasSuspensao = $oficina->dias_suspensao_vencido ?? $cfg->cobranca_dias_suspensao_padrao;
            $restante      = $diasSuspensao - $diasVencida;

            $fase     = 'VENCIDA';
            $mensagem = 'Sua fatura venceu em ' . $cobranca->vencimento->format('d/m/Y')
                . '. Pague sua fatura e evite a suspensão dos seus serviços no sistema. ';
            $mensagem .= $restante > 0
                ? 'Sua oficina será suspensa em ' . $restante . ' dia' . ($restante === 1 ? '' : 's')
                    . ' e seu acesso será bloqueado até a identificação do pagamento.'
                : 'Sua oficina pode ser suspensa a qualquer momento.';
        }

        if (!$this->podeExibirHoje($oficina, $cfg)) {
            return ['show' => false];
        }

        $this->registrarExibicao($oficina);

        return [
            'show'               => true,
            'fase'               => $fase,
            'mensagem'           => $mensagem,
            'valor'              => number_format((float) $cobranca->valor, 2, '.', ''),
            'vencimento'         => $cobranca->vencimento->toDateString(),
            'link_pagamento'     => $cobranca->link_pagamento,
            'ciclo_atual'        => $oficina->ciclo_cobranca,
            'desconto_anual_pct' => (float) $cfg->desconto_anual_pct,
        ];
    }

    private function exibicoesHoje(Oficina $oficina): int
    {
        $hoje = now()->toDateString();
        return $oficina->alerta_cobranca_ultima_exibicao_em?->toDateString() === $hoje
            ? $oficina->alerta_cobranca_exibicoes_hoje
            : 0;
    }

    private function podeExibirHoje(Oficina $oficina, SaasConfig $cfg): bool
    {
        return $this->exibicoesHoje($oficina) < ($cfg->alerta_cobranca_vezes_dia ?? 1);
    }

    private function registrarExibicao(Oficina $oficina): void
    {
        $oficina->update([
            'alerta_cobranca_exibicoes_hoje'     => $this->exibicoesHoje($oficina) + 1,
            'alerta_cobranca_ultima_exibicao_em' => now()->toDateString(),
        ]);
    }

    private function formatarMoeda(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}
```

- [ ] **Step 4: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/AssinaturaAlertaServiceTest.php`
Expected: PASS — 5 testes (requer Postgres; sem banco aqui, verifique por
rastreamento manual de cada teste contra o código acima — em particular o
teste de `7 dias` em `test_cobranca_vencida_...`: `dias_suspensao_padrao=10`,
vencida há 3 dias, `10 - 3 = 7`).

- [ ] **Step 5: Sintaxe**

Run: `cd backend && php -l app/Services/AssinaturaAlertaService.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AssinaturaAlertaService.php backend/tests/Feature/Saas/AssinaturaAlertaServiceTest.php
git commit -m "feat(saas): AssinaturaAlertaService calcula fase/mensagem do alerta de cobranca"
```

---

### Task 5: Endpoints tenant-side (`GET /assinatura/alerta`, `POST /assinatura/mudar-ciclo`)

**Files:**
- Create: `backend/app/Http/Controllers/AssinaturaController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AssinaturaControllerTest.php` (novo)

**Interfaces:**
- Consumes: `AssinaturaAlertaService::status()` (Task 4),
  `AssinaturaService::mudarCiclo()` (Task 3), `App\Tenancy\TenancyContext::get()`.
- Produces: `GET /assinatura/alerta` (`['tenant','auth:sanctum']`, sem
  restrição de role), `POST /assinatura/mudar-ciclo` (`['tenant',
  'auth:sanctum','role:ADMIN']`).

- [ ] **Step 1: Escrever o teste**

Este teste usa autenticação de tenant (`auth:sanctum` com `Usuario`, não
`SuperAdmin`). Duas coisas não óbvias, confirmadas lendo
`InitializeTenancyByHeader`/`HasTenantScope` antes de escrever isto: (1) a
identificação do tenant numa requisição HTTP real depende do header
`X-Tenant` (o slug) — sem ele, `TenancyContext` nunca é setado e o
controller não encontra a oficina; (2) `Usuario` é tenant-scoped
(`HasTenantScope`), então precisa ser criado com `TenancyContext::set(...)`
ativo (como em `WhatsAppServiceTest.php`) pra `oficina_id` ser preenchido
corretamente.

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssinaturaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComUsuario(string $role = 'ADMIN'): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA', 'ciclo_cobranca' => 'MENSAL',
            'proximo_vencimento' => now()->addMonth()->toDateString(),
        ]);

        TenancyContext::set($oficina->id, $oficina->slug);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => $role, 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);
        TenancyContext::clear();

        return [$oficina, $usuario];
    }

    private function comoTenant(Oficina $oficina, Usuario $usuario): static
    {
        return $this->withHeaders(['X-Tenant' => $oficina->slug])->actingAs($usuario);
    }

    public function test_alerta_retorna_show_false_sem_cobranca_pendente(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();

        $response = $this->comoTenant($oficina, $usuario)->getJson('/api/assinatura/alerta');

        $response->assertStatus(200)->assertJson(['show' => false]);
    }

    public function test_alerta_retorna_mensagem_com_cobranca_pendente(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);

        $response = $this->comoTenant($oficina, $usuario)->getJson('/api/assinatura/alerta');

        $response->assertStatus(200)->assertJson(['show' => true, 'fase' => 'DISPONIVEL']);
    }

    public function test_mudar_ciclo_como_admin_funciona(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('ADMIN');

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/mudar-ciclo', ['ciclo' => 'ANUAL']);

        $response->assertStatus(200);
        $oficina->refresh();
        $this->assertSame('ANUAL', $oficina->ciclo_cobranca);
    }

    public function test_mudar_ciclo_como_mecanico_e_bloqueado(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('MECANICO');

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/mudar-ciclo', ['ciclo' => 'ANUAL']);

        $response->assertStatus(403);
    }
}
```

Nota pro implementador: `comoTenant()` retorna `static` (a própria classe de
teste, já com header + auth aplicados) — se o typehint `static` causar
algum atrito de versão do PHPUnit/Laravel neste projeto, remova o typehint
do retorno em vez de lutar contra ele; o importante é o comportamento
(header + auth juntos), não a assinatura.

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/AssinaturaControllerTest.php`
Expected: FAIL — rotas não existem (requer Postgres; sem banco aqui,
confirme por leitura de `routes/api.php` que `assinatura/alerta` e
`assinatura/mudar-ciclo` ainda não existem).

- [ ] **Step 3: Criar `AssinaturaController`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Oficina;
use App\Services\AssinaturaAlertaService;
use App\Services\AssinaturaService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssinaturaController extends Controller
{
    public function __construct(
        private readonly AssinaturaAlertaService $alertaService,
        private readonly AssinaturaService $assinaturaService,
    ) {}

    public function alerta(): JsonResponse
    {
        $oficina = Oficina::find(TenancyContext::get());
        if (!$oficina) {
            return response()->json(['show' => false]);
        }

        return response()->json($this->alertaService->status($oficina));
    }

    public function mudarCiclo(Request $request): JsonResponse
    {
        $validated = $request->validate(['ciclo' => 'required|in:MENSAL,ANUAL']);
        $oficina   = Oficina::findOrFail(TenancyContext::get());

        $this->assinaturaService->mudarCiclo($oficina, $validated['ciclo']);

        return response()->json(['message' => 'Ciclo de cobrança atualizado.', 'data' => [
            'ciclo_cobranca'     => $oficina->ciclo_cobranca,
            'proximo_vencimento' => $oficina->proximo_vencimento->toDateString(),
        ]]);
    }
}
```

- [ ] **Step 4: Adicionar as rotas**

Em `backend/routes/api.php`, adicionar `use App\Http\Controllers\AssinaturaController;`
aos imports do topo (junto dos outros `use App\Http\Controllers\...`).

Logo abaixo da linha `Route::get('notificacoes/ativas', ...)` (dentro do
mesmo grupo `Route::middleware(['tenant', 'auth:sanctum'])`):
```php
    Route::get('assinatura/alerta', [AssinaturaController::class, 'alerta']);
```

Adicionar um novo grupo logo depois do bloco `// ─── WhatsApp — somente ADMIN ──` existente:
```php
// ─── Assinatura — somente ADMIN ──────────────────────────────────────────────
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::post('assinatura/mudar-ciclo', [AssinaturaController::class, 'mudarCiclo']);
});
```

- [ ] **Step 5: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/AssinaturaControllerTest.php`
Expected: PASS — 4 testes (requer Postgres; sem banco aqui, verifique por
leitura que o middleware `role:ADMIN` já existente no projeto — usado em
`configuracoes`/`whatsapp/config` — se comporta do mesmo jeito aqui, e que
`AssinaturaController::alerta()`/`mudarCiclo()` chamam exatamente os
services já testados nas Tasks 2 e 3).

- [ ] **Step 6: Sintaxe**

Run: `cd backend && php -l app/Http/Controllers/AssinaturaController.php && php -l routes/api.php`
Expected: `No syntax errors detected` nos 2.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/AssinaturaController.php backend/routes/api.php backend/tests/Feature/AssinaturaControllerTest.php
git commit -m "feat(saas): endpoints tenant-side GET assinatura/alerta e POST assinatura/mudar-ciclo"
```

---

### Task 6: `SaasConfigController` — campos de cadência do alerta

**Files:**
- Modify: `backend/app/Http/Controllers/SaaS/SaasConfigController.php`
- Test: `backend/tests/Feature/Saas/SaasConfigAlertaCobrancaTest.php` (novo)

**Interfaces:**
- Produces: `GET /saas/config` inclui `alerta_cobranca_vezes_dia` e
  `alerta_cobranca_dias_exibicao`; `PUT /saas/config/cobranca` (já existe,
  Task 10 do motor de cobrança) passa a aceitar e validar esses 2 campos
  também.

- [ ] **Step 1: Escrever o teste**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SaasConfigAlertaCobrancaTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_show_inclui_campos_de_alerta_cobranca(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->getJson('/api/saas/config');

        $response->assertStatus(200)->assertJsonStructure([
            'data' => ['alerta_cobranca_vezes_dia', 'alerta_cobranca_dias_exibicao'],
        ]);
    }

    public function test_update_cobranca_salva_campos_de_alerta(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->putJson('/api/saas/config/cobranca', [
            'cobranca_dias_antecedencia_padrao' => 5,
            'cobranca_dias_suspensao_padrao'    => 10,
            'desconto_anual_pct'                => 15,
            'alerta_cobranca_vezes_dia'          => 2,
            'alerta_cobranca_dias_exibicao'      => 20,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('saas_config', [
            'alerta_cobranca_vezes_dia'     => 2,
            'alerta_cobranca_dias_exibicao' => 20,
        ]);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/SaasConfigAlertaCobrancaTest.php`
Expected: FAIL — `show()` não retorna os campos e `updateCobranca()` rejeita
os 2 campos novos por não estarem na validação (requer Postgres pra rodar
de verdade).

- [ ] **Step 3: Atualizar `show()`**

Adicionar ao array retornado (logo abaixo de
`'desconto_anual_pct' => (float) $cfg->desconto_anual_pct,`):
```php
                'alerta_cobranca_vezes_dia'     => $cfg->alerta_cobranca_vezes_dia,
                'alerta_cobranca_dias_exibicao' => $cfg->alerta_cobranca_dias_exibicao,
```

- [ ] **Step 4: Atualizar `updateCobranca()`**

Substituir o método inteiro:
```php
    public function updateCobranca(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cobranca_dias_antecedencia_padrao' => ['required', 'integer', 'min:1', 'max:60'],
            'cobranca_dias_suspensao_padrao'    => ['required', 'integer', 'min:1', 'max:90'],
            'desconto_anual_pct'                => ['required', 'numeric', 'min:0', 'max:90'],
            'alerta_cobranca_vezes_dia'          => ['required', 'integer', 'min:1', 'max:10'],
            'alerta_cobranca_dias_exibicao'      => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        SaasConfig::get()->update($validated);

        return response()->json(['message' => 'Configurações de cobrança salvas.', 'data' => $validated]);
    }
```

- [ ] **Step 5: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/SaasConfigAlertaCobrancaTest.php`
Expected: PASS — 2 testes (requer Postgres pra rodar de verdade).

- [ ] **Step 6: Sintaxe**

Run: `cd backend && php -l app/Http/Controllers/SaaS/SaasConfigController.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/SaasConfigController.php backend/tests/Feature/Saas/SaasConfigAlertaCobrancaTest.php
git commit -m "feat(saas): campos de cadencia do alerta de cobranca (vezes/dia, dias de exibicao)"
```

---

### Task 7: Frontend — `AssinaturaAlertaModal`

**Files:**
- Create: `frontend/components/AssinaturaAlertaModal.tsx`
- Modify: `frontend/app/(dashboard)/layout.tsx`

**Interfaces:**
- Consumes: `GET /assinatura/alerta` (Task 5), `POST /assinatura/mudar-ciclo`
  (Task 5), `useAuth().getUser()` (já existe em `frontend/hooks/useAuth.ts`,
  retorna `{id, nome, email, role}` de `localStorage`).

- [ ] **Step 1: Criar o componente**

```tsx
'use client'
import { useEffect, useState } from 'react'
import api from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'

interface AlertaAssinatura {
  show: boolean
  fase?: 'DISPONIVEL' | 'VENCIDA'
  mensagem?: string
  valor?: string
  vencimento?: string
  link_pagamento?: string | null
  ciclo_atual?: 'MENSAL' | 'ANUAL'
  desconto_anual_pct?: number
}

function fmtBRL(v: string): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v))
}

export function AssinaturaAlertaModal() {
  const { getUser } = useAuth()
  const [alerta, setAlerta] = useState<AlertaAssinatura | null>(null)
  const [visible, setVisible] = useState(false)
  const [mudandoCiclo, setMudandoCiclo] = useState(false)

  useEffect(() => {
    api.get<AlertaAssinatura>('/assinatura/alerta')
      .then(r => {
        if (r.data.show) {
          setAlerta(r.data)
          setVisible(true)
        }
      })
      .catch(() => { /* silencioso */ })
  }, [])

  async function trocarParaAnual() {
    if (!confirm(`Trocar sua assinatura para anual com ${alerta?.desconto_anual_pct}% de desconto?`)) return
    setMudandoCiclo(true)
    try {
      await api.post('/assinatura/mudar-ciclo', { ciclo: 'ANUAL' })
      setVisible(false)
    } catch {
      alert('Não foi possível trocar o ciclo agora. Tente novamente mais tarde.')
    } finally {
      setMudandoCiclo(false)
    }
  }

  if (!visible || !alerta?.show) return null

  const vencida = alerta.fase === 'VENCIDA'
  const isAdmin = getUser()?.role === 'ADMIN'
  const mostrarUpsell = isAdmin && alerta.ciclo_atual === 'MENSAL' && !!alerta.desconto_anual_pct

  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,.7)', zIndex: 2000,
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20,
    }}>
      <div style={{
        background: 'var(--card)', border: `1px solid ${vencida ? 'var(--danger)' : 'var(--border)'}`,
        borderRadius: 14, width: '100%', maxWidth: 480, padding: 32, position: 'relative',
      }}>
        <button
          onClick={() => setVisible(false)}
          style={{ position: 'absolute', top: 16, right: 16, background: 'none', border: 'none', color: 'var(--muted)', fontSize: 20, cursor: 'pointer', lineHeight: 1 }}
        >
          ✕
        </button>

        <div style={{ fontSize: 32, marginBottom: 12 }}>{vencida ? '⚠️' : '💳'}</div>
        <h2 className="font-display" style={{ fontSize: 22, fontWeight: 800, color: vencida ? 'var(--danger)' : 'var(--text)', margin: '0 0 12px' }}>
          {vencida ? 'Fatura vencida' : 'Pagamento disponível'}
        </h2>
        <p style={{ fontSize: 14, color: 'var(--text)', lineHeight: 1.6, margin: '0 0 8px' }}>
          {alerta.mensagem}
        </p>
        {alerta.valor && (
          <p style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 20, fontWeight: 700, color: 'var(--accent)', margin: '12px 0 20px' }}>
            {fmtBRL(alerta.valor)}
          </p>
        )}

        <div style={{ display: 'flex', gap: 10, marginBottom: mostrarUpsell ? 24 : 0 }}>
          <a
            href={alerta.link_pagamento ?? '#'}
            target="_blank"
            rel="noopener noreferrer"
            style={{
              flex: 1, textAlign: 'center', padding: '11px', background: 'var(--accent)', color: '#000',
              borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif",
              textDecoration: 'none', opacity: alerta.link_pagamento ? 1 : 0.5,
              pointerEvents: alerta.link_pagamento ? 'auto' : 'none',
            }}
          >
            Pagar com PIX
          </a>
          <a
            href={alerta.link_pagamento ?? '#'}
            target="_blank"
            rel="noopener noreferrer"
            style={{
              flex: 1, textAlign: 'center', padding: '11px', background: 'transparent', border: '1px solid var(--accent)',
              color: 'var(--accent)', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif",
              textDecoration: 'none', opacity: alerta.link_pagamento ? 1 : 0.5,
              pointerEvents: alerta.link_pagamento ? 'auto' : 'none',
            }}
          >
            Pagar com Cartão
          </a>
        </div>

        {mostrarUpsell && (
          <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
            <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 12 }}>
              Economize trocando para o plano anual
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 14 }}>
              <div style={{ border: '1px solid var(--border)', borderRadius: 10, padding: 14, textAlign: 'center' }}>
                <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 4 }}>Mensal</div>
                <div style={{ fontSize: 13, color: 'var(--text)', fontWeight: 600 }}>Plano atual</div>
              </div>
              <div style={{ border: '1px solid var(--accent)', background: 'rgba(245,166,35,.08)', borderRadius: 10, padding: 14, textAlign: 'center' }}>
                <div style={{ fontSize: 12, color: 'var(--accent)', marginBottom: 4, fontWeight: 700 }}>Anual</div>
                <div style={{ fontSize: 13, color: 'var(--text)', fontWeight: 600 }}>-{alerta.desconto_anual_pct}%</div>
              </div>
            </div>
            <button
              onClick={trocarParaAnual}
              disabled={mudandoCiclo}
              style={{
                width: '100%', padding: '10px', background: 'none', border: '1px solid var(--accent)',
                color: 'var(--accent)', borderRadius: 8, fontWeight: 700, fontSize: 13,
                cursor: mudandoCiclo ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif",
              }}
            >
              {mudandoCiclo ? 'Trocando…' : `Trocar para anual e economizar ${alerta.desconto_anual_pct}%`}
            </button>
          </div>
        )}
      </div>
    </div>
  )
}
```

- [ ] **Step 2: Montar no layout do dashboard**

Em `frontend/app/(dashboard)/layout.tsx`, adicionar o import:
```typescript
import { AssinaturaAlertaModal } from '@/components/AssinaturaAlertaModal'
```
E adicionar `<AssinaturaAlertaModal />` logo depois de `<NotificacaoModal />`
(última linha antes do `</div>` de fechamento).

- [ ] **Step 3: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p tsconfig.json`
Expected: sem erros referenciando `AssinaturaAlertaModal.tsx` ou `layout.tsx`.

- [ ] **Step 4: Commit**

```bash
git add frontend/components/AssinaturaAlertaModal.tsx "frontend/app/(dashboard)/layout.tsx"
git commit -m "feat(saas): modal de alerta de cobranca com pagamento e upsell anual"
```

---

### Task 8: Frontend — SaaS Admin Configurações (cadência do alerta)

**Files:**
- Modify: `frontend/app/saas-admin/(protected)/configuracoes/page.tsx`

**Interfaces:**
- Consumes: `GET /saas/config` (campos novos de Task 5), `PUT
  /saas/config/cobranca` (já existente, agora aceita os 2 campos novos).

- [ ] **Step 1: Adicionar os campos à interface `SaasConfigData`**

Depois de `desconto_anual_pct: number` (adicionado na feature anterior):
```typescript
  alerta_cobranca_vezes_dia: number
  alerta_cobranca_dias_exibicao: number
```

- [ ] **Step 2: Adicionar estado e carregamento**

Depois de `const [savingCobranca, setSavingCobranca] = useState(false)`:
```typescript
  const [alertaVezesDia, setAlertaVezesDia] = useState('1')
  const [alertaDiasExibicao, setAlertaDiasExibicao] = useState('30')
```

No `useEffect` que carrega `/saas/config`, logo depois de
`setDescontoAnual(String(d.desconto_anual_pct ?? 0))`:
```typescript
        setAlertaVezesDia(String(d.alerta_cobranca_vezes_dia ?? 1))
        setAlertaDiasExibicao(String(d.alerta_cobranca_dias_exibicao ?? 30))
```

- [ ] **Step 3: Incluir na função de salvar**

Em `salvarCobranca()`, o corpo do `saasApi.put('/saas/config/cobranca', {...})`
passa a incluir:
```typescript
        alerta_cobranca_vezes_dia: parseInt(alertaVezesDia, 10) || 1,
        alerta_cobranca_dias_exibicao: parseInt(alertaDiasExibicao, 10) || 30,
```
(mantendo os 3 campos que já existiam no mesmo objeto).

- [ ] **Step 4: Adicionar os campos visuais**

Dentro da `SectionCard` "Cobrança Recorrente" já existente, logo depois do
bloco do campo "Desconto pagamento anual (%)" (antes do `<SaveButton
loading={savingCobranca} onClick={salvarCobranca} label="Salvar Cobrança"
/>`):
```tsx
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Alerta de cobrança: vezes/dia
            </label>
            <input value={alertaVezesDia} onChange={e => setAlertaVezesDia(e.target.value)} type="number" min={1} max={10}
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
          <div style={{ marginBottom: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
              Dias de exibição (antes do vencimento)
            </label>
            <input value={alertaDiasExibicao} onChange={e => setAlertaDiasExibicao(e.target.value)} type="number" min={1} max={90}
              style={{ width: '100%', padding: '9px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--card)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }} />
          </div>
        </div>
```

- [ ] **Step 5: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p tsconfig.json`
Expected: sem erros referenciando `configuracoes/page.tsx`.

- [ ] **Step 6: Commit**

```bash
git add "frontend/app/saas-admin/(protected)/configuracoes/page.tsx"
git commit -m "feat(saas): campos de cadencia do alerta de cobranca na tela de configuracoes"
```
