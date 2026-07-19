# Suspensão Automática + Voto de Confiança Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Suspender automaticamente uma oficina com fatura vencida há mais
dias que o limite configurado; bloquear o acesso com uma página cheia (não
modal) oferecendo pagamento; permitir "voto de confiança" — liberação
temporária concedida pelo admin da plataforma (sem restrição) ou
self-service pela própria oficina (uma vez por fatura).

**Architecture:** A suspensão é um 3º passo do comando diário
`cobrancas:gerar` já existente (mesma cadência, sem novo agendamento).
Reativação ao pagar já funciona (código existente, não tocado). Duas rotas
tenant novas (`status-bloqueio`, `voto-confianca`) ganham uma exceção
explícita no middleware que hoje bloqueia 100% da API de oficinas
`SUSPENSA`. Frontend: um interceptor Axios redireciona pra uma página de
bloqueio em tela cheia assim que qualquer chamada retornar o código
`OFICINA_SUSPENSA`.

**Tech Stack:** Laravel 12 / PHP 8.2 / PostgreSQL 16 (backend), Next.js 16 /
TypeScript (frontend).

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo/editado.
- Sem `Docker`/Postgres neste ambiente: `php artisan test tests/Unit/...`
  roda localmente; Feature tests exigem Postgres — cada task deixa claro
  qual caso se aplica.
- Datas expostas em JSON como `toDateString()` (`YYYY-MM-DD`).
- O cálculo de "dias vencida" usa `vencimento->diffInDays(now())` (mesma
  chamada já usada e verificada em `AssinaturaAlertaService`, spec anterior
  — não trocar por outra forma de calcular).
- A trava "voto de confiança uma vez por fatura" vale só pro caminho
  self-service (tenant, `role:ADMIN`). O endpoint do SaaS admin nunca é
  restrito por essa trava.
- Botões de pagamento sempre abrem `link_pagamento` (checkout hospedado do
  gateway) — sem fluxo nativo de PIX/cartão.

---

### Task 1: Migrations + fillable/casts

**Files:**
- Create: `backend/database/migrations/2026_07_19_000001_add_voto_confianca_fields.php`
- Modify: `backend/app/Models/SaasConfig.php`
- Modify: `backend/app/Models/Oficina.php`
- Modify: `backend/app/Models/Cobranca.php`

**Interfaces:**
- Produces: `saas_config.voto_confianca_dias` (int, default 3),
  `oficinas.voto_confianca_ate` (date, nullable),
  `cobrancas.voto_confianca_usado_em` (timestamp, nullable).

- [ ] **Step 1: Migration (as 3 colunas em 1 arquivo, cada uma numa tabela diferente)**

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
            $table->integer('voto_confianca_dias')->default(3);
        });

        Schema::table('oficinas', function (Blueprint $table) {
            $table->date('voto_confianca_ate')->nullable();
        });

        Schema::table('cobrancas', function (Blueprint $table) {
            $table->timestampTz('voto_confianca_usado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn('voto_confianca_dias');
        });

        Schema::table('oficinas', function (Blueprint $table) {
            $table->dropColumn('voto_confianca_ate');
        });

        Schema::table('cobrancas', function (Blueprint $table) {
            $table->dropColumn('voto_confianca_usado_em');
        });
    }
};
```

- [ ] **Step 2: Sintaxe**

Run: `cd backend && php -l database/migrations/2026_07_19_000001_add_voto_confianca_fields.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Atualizar `SaasConfig.php`**

Adicionar `'voto_confianca_dias',` ao `$fillable` (depois de
`'alerta_cobranca_dias_exibicao',`) e `'voto_confianca_dias' => 'integer',`
ao `$casts`.

- [ ] **Step 4: Atualizar `Oficina.php`**

Adicionar `'voto_confianca_ate',` ao `$fillable` (depois de
`'alerta_cobranca_ultima_exibicao_em',`) e
`'voto_confianca_ate' => 'date',` ao `$casts`.

- [ ] **Step 5: Atualizar `Cobranca.php`**

Adicionar `'voto_confianca_usado_em',` ao `$fillable` (depois de
`'link_pagamento',`) e, no `$casts`, adicionar
`'voto_confianca_usado_em' => 'datetime',`.

- [ ] **Step 6: Sintaxe dos models**

Run: `cd backend && php -l app/Models/SaasConfig.php && php -l app/Models/Oficina.php && php -l app/Models/Cobranca.php`
Expected: `No syntax errors detected` nos 3.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_07_19_000001_add_voto_confianca_fields.php backend/app/Models/SaasConfig.php backend/app/Models/Oficina.php backend/app/Models/Cobranca.php
git commit -m "feat(saas): campos de voto de confianca (saas_config, oficinas, cobrancas)"
```

---

### Task 2: `CobrancaRecorrenteService::suspenderVencidas()` + comando

**Files:**
- Modify: `backend/app/Services/CobrancaRecorrenteService.php`
- Modify: `backend/app/Console/Commands/GerarCobrancasRecorrentes.php`
- Test: `backend/tests/Feature/Saas/SuspenderVencidasTest.php` (novo)

**Interfaces:**
- Consumes: `Oficina.dias_suspensao_vencido`, `Oficina.voto_confianca_ate`,
  `SaasConfig.cobranca_dias_suspensao_padrao` (todos já existentes).
- Produces: `CobrancaRecorrenteService::suspenderVencidas(): int`. Chamado
  como 3º passo do comando `cobrancas:gerar` (depois de `gerarPendentes()` e
  `marcarVencidas()`).

- [ ] **Step 1: Escrever o teste**

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
use Tests\TestCase;

class SuspenderVencidasTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        return Oficina::create(array_merge([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'INADIMPLENTE',
        ], $overrides));
    }

    public function test_suspende_oficina_vencida_alem_do_limite(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(1, $suspensas);
        $oficina->refresh();
        $this->assertSame('SUSPENSA', $oficina->status);
    }

    public function test_nao_suspende_antes_do_limite(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(3)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(0, $suspensas);
        $oficina->refresh();
        $this->assertSame('INADIMPLENTE', $oficina->status);
    }

    public function test_nao_suspende_com_voto_de_confianca_ativo(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina(['voto_confianca_ate' => now()->addDays(2)->toDateString()]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(15)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(0, $suspensas);
        $oficina->refresh();
        $this->assertSame('INADIMPLENTE', $oficina->status);
    }

    public function test_suspende_quando_voto_de_confianca_expirou(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina(['voto_confianca_ate' => now()->subDay()->toDateString()]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(15)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(1, $suspensas);
    }

    public function test_ignora_override_por_oficina(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 30]);
        $oficina = $this->criarOficina(['dias_suspensao_vencido' => 5]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(7)->toDateString(),
        ]);

        $suspensas = app(CobrancaRecorrenteService::class)->suspenderVencidas();

        $this->assertSame(1, $suspensas);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/SuspenderVencidasTest.php`
Expected: FAIL — `suspenderVencidas()` não existe (requer Postgres pra
rodar de verdade; sem banco aqui, confirme por leitura que o método ainda
não existe em `CobrancaRecorrenteService.php`).

- [ ] **Step 3: Implementar**

Em `backend/app/Services/CobrancaRecorrenteService.php`, adicionar (depois
de `marcarVencidas()`):

```php
    public function suspenderVencidas(): int
    {
        $cfg = SaasConfig::get();
        $suspensas = 0;

        $oficinas = Oficina::whereIn('status', ['ATIVA', 'INADIMPLENTE'])->get();

        foreach ($oficinas as $oficina) {
            $cobranca = Cobranca::where('oficina_id', $oficina->id)
                ->where('tipo', 'ASSINATURA')
                ->where('status', 'VENCIDA')
                ->orderByDesc('vencimento')
                ->first();

            if (!$cobranca) {
                continue;
            }

            $diasVencida   = (int) $cobranca->vencimento->diffInDays(now());
            $diasSuspensao = $oficina->dias_suspensao_vencido ?? $cfg->cobranca_dias_suspensao_padrao;

            if ($diasVencida < $diasSuspensao) {
                continue;
            }

            if ($oficina->voto_confianca_ate && $oficina->voto_confianca_ate->isFuture()) {
                continue;
            }

            $oficina->update(['status' => 'SUSPENSA']);
            $suspensas++;
        }

        return $suspensas;
    }
```

- [ ] **Step 4: Wire no comando**

Em `backend/app/Console/Commands/GerarCobrancasRecorrentes.php`, o `handle()`
atual chama `gerarPendentes()` e `marcarVencidas()`. Adicionar uma 3ª
chamada:

```php
    public function handle(): int
    {
        $geradas   = $this->service->gerarPendentes();
        $vencidas  = $this->service->marcarVencidas();
        $suspensas = $this->service->suspenderVencidas();

        $this->info("Cobranças geradas: {$geradas}. Vencidas: {$vencidas}. Suspensas: {$suspensas}.");
        return self::SUCCESS;
    }
```

- [ ] **Step 5: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/SuspenderVencidasTest.php`
Expected: PASS — 5 testes (requer Postgres pra rodar de verdade).

- [ ] **Step 6: Sintaxe**

Run: `cd backend && php -l app/Services/CobrancaRecorrenteService.php && php -l app/Console/Commands/GerarCobrancasRecorrentes.php`
Expected: `No syntax errors detected` nos 2.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/CobrancaRecorrenteService.php backend/app/Console/Commands/GerarCobrancasRecorrentes.php backend/tests/Feature/Saas/SuspenderVencidasTest.php
git commit -m "feat(saas): suspensao automatica de oficina vencida (CobrancaRecorrenteService::suspenderVencidas)"
```

---

### Task 3: `AssinaturaAlertaService::statusBloqueio()`

**Files:**
- Modify: `backend/app/Services/AssinaturaAlertaService.php`
- Test: `backend/tests/Feature/Saas/AssinaturaAlertaServiceBloqueioTest.php` (novo)

**Interfaces:**
- Produces: `AssinaturaAlertaService::statusBloqueio(Oficina $oficina): array`
  — retorna `['suspensa' => bool, 'voto_confianca_disponivel' => bool]` (sem
  fatura relevante) ou os mesmos 2 campos + `fase`, `mensagem`, `valor`,
  `vencimento`, `link_pagamento`. **Sem throttle** — sempre retorna o estado
  completo, ao contrário de `status()` (que é pra o modal dispensável).
- Refactor interno (sem mudar comportamento de `status()`): extrai um
  método privado `resolverFaseEMensagem(Oficina, Cobranca): array` de
  dentro de `status()`, reaproveitado pelos dois métodos públicos.

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

class AssinaturaAlertaServiceBloqueioTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(array $overrides = []): Oficina
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        return Oficina::create(array_merge([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'SUSPENSA',
        ], $overrides));
    }

    public function test_bloqueio_com_fatura_vencida_e_voto_disponivel(): void
    {
        SaasConfig::get()->update(['cobranca_dias_suspensao_padrao' => 10]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
            'link_pagamento' => 'https://gateway.test/pay/1',
        ]);

        $status = app(AssinaturaAlertaService::class)->statusBloqueio($oficina);

        $this->assertTrue($status['suspensa']);
        $this->assertTrue($status['voto_confianca_disponivel']);
        $this->assertSame('VENCIDA', $status['fase']);
        $this->assertSame('https://gateway.test/pay/1', $status['link_pagamento']);
    }

    public function test_bloqueio_com_voto_ja_usado(): void
    {
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
            'voto_confianca_usado_em' => now(),
        ]);

        $status = app(AssinaturaAlertaService::class)->statusBloqueio($oficina);

        $this->assertTrue($status['suspensa']);
        $this->assertFalse($status['voto_confianca_disponivel']);
    }

    public function test_bloqueio_sem_fatura_relevante(): void
    {
        $oficina = $this->criarOficina(['status' => 'ATIVA']);

        $status = app(AssinaturaAlertaService::class)->statusBloqueio($oficina);

        $this->assertFalse($status['suspensa']);
        $this->assertFalse($status['voto_confianca_disponivel']);
    }

    public function test_statusBloqueio_nao_incrementa_contador_de_exibicao(): void
    {
        SaasConfig::get()->update(['alerta_cobranca_vezes_dia' => 1]);
        $oficina = $this->criarOficina();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 199.90,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
        ]);

        app(AssinaturaAlertaService::class)->statusBloqueio($oficina);
        app(AssinaturaAlertaService::class)->statusBloqueio($oficina);
        app(AssinaturaAlertaService::class)->statusBloqueio($oficina);

        $oficina->refresh();
        $this->assertSame(0, $oficina->alerta_cobranca_exibicoes_hoje);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/AssinaturaAlertaServiceBloqueioTest.php`
Expected: FAIL — `statusBloqueio()` não existe (requer Postgres pra rodar
de verdade; sem banco aqui, confirme por leitura que o método ainda não
existe em `AssinaturaAlertaService.php`).

- [ ] **Step 3: Ler o arquivo atual e extrair `resolverFaseEMensagem()`**

Leia `backend/app/Services/AssinaturaAlertaService.php` primeiro — o
método `status()` de hoje calcula `$fase`/`$mensagem` inline dentro de um
`if ($cobranca->status === 'PENDENTE') { ... } else { ... }`. Extraia
exatamente esse bloco (sem mudar a lógica/texto de nenhuma mensagem) pra um
novo método privado:

```php
    /** @return array{fase: string, mensagem: string} */
    private function resolverFaseEMensagem(Oficina $oficina, Cobranca $cobranca, SaasConfig $cfg): array
    {
        if ($cobranca->status === 'PENDENTE') {
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

        return ['fase' => $fase, 'mensagem' => $mensagem];
    }
```

Substitua o trecho equivalente dentro de `status()` por uma chamada a esse
método (`['fase' => $fase, 'mensagem' => $mensagem] =
$this->resolverFaseEMensagem($oficina, $cobranca, $cfg);`), preservando
100% do resto de `status()` (early-returns, throttle, retorno final) sem
nenhuma mudança de comportamento.

- [ ] **Step 4: Adicionar `statusBloqueio()`**

```php
    public function statusBloqueio(Oficina $oficina): array
    {
        $suspensa = $oficina->status === 'SUSPENSA';

        $cobranca = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->whereIn('status', ['PENDENTE', 'VENCIDA'])
            ->orderByDesc('vencimento')
            ->first();

        if (!$cobranca) {
            return ['suspensa' => $suspensa, 'voto_confianca_disponivel' => false];
        }

        $cfg = SaasConfig::get();
        ['fase' => $fase, 'mensagem' => $mensagem] = $this->resolverFaseEMensagem($oficina, $cobranca, $cfg);

        return [
            'suspensa'                  => $suspensa,
            'fase'                      => $fase,
            'mensagem'                  => $mensagem,
            'valor'                     => number_format((float) $cobranca->valor, 2, '.', ''),
            'vencimento'                => $cobranca->vencimento->toDateString(),
            'link_pagamento'            => $cobranca->link_pagamento,
            'voto_confianca_disponivel' => $cobranca->status === 'VENCIDA' && $cobranca->voto_confianca_usado_em === null,
        ];
    }
```

- [ ] **Step 5: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/AssinaturaAlertaServiceBloqueioTest.php tests/Feature/Saas/AssinaturaAlertaServiceTest.php`
Expected: PASS — 4 testes novos + os 5 já existentes de `status()`
continuam passando sem nenhuma regressão (requer Postgres pra rodar de
verdade; sem banco aqui, releia `status()` inteiro e confirme que o
refactor do Step 3 não mudou nenhuma string de mensagem nem nenhuma
condição de early-return).

- [ ] **Step 6: Sintaxe**

Run: `cd backend && php -l app/Services/AssinaturaAlertaService.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/AssinaturaAlertaService.php backend/tests/Feature/Saas/AssinaturaAlertaServiceBloqueioTest.php
git commit -m "feat(saas): AssinaturaAlertaService::statusBloqueio para a pagina de bloqueio"
```

---

### Task 4: Middleware — exceção pras rotas de bloqueio + código de erro

**Files:**
- Modify: `backend/app/Http/Middleware/InitializeTenancyByHeader.php`
- Test: `backend/tests/Feature/TenancySuspensaExcecaoTest.php` (novo)

**Interfaces:**
- Produces: resposta 403 de oficina suspensa ganha `code: 'OFICINA_SUSPENSA'`.
  Rotas `api/assinatura/status-bloqueio` e `api/assinatura/voto-confianca`
  (ainda não existem — criadas na Task 5) passam a ser exceção do bloqueio.

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

class TenancySuspensaExcecaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaSuspensaComUsuario(): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'SUSPENSA',
        ]);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
            'oficina_id' => $oficina->id,
        ]);
        return [$oficina, $usuario];
    }

    public function test_rota_generica_continua_bloqueada_com_codigo(): void
    {
        [$oficina, $usuario] = $this->criarOficinaSuspensaComUsuario();

        $response = $this->withHeaders(['X-Tenant' => $oficina->slug])
            ->actingAs($usuario)
            ->getJson('/api/dashboard');

        $response->assertStatus(403)->assertJson(['code' => 'OFICINA_SUSPENSA']);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/TenancySuspensaExcecaoTest.php`
Expected: FAIL — a resposta ainda não tem o campo `code` (requer Postgres
pra rodar de verdade; sem banco aqui, confirme por leitura que o
middleware atual só retorna `message`, sem `code`).

Nota: a exceção de rota em si (`api/assinatura/status-bloqueio`/
`voto-confianca`) só é verificável de ponta a ponta na Task 5, quando essas
rotas passam a existir de fato — os próprios testes da Task 5 usam oficinas
`SUSPENSA` chamando essas rotas e esperando sucesso (não 403), o que já
prova a exceção funcionando. Não duplicar esse teste aqui contra uma rota
ainda inexistente.

- [ ] **Step 3: Editar o middleware**

Substituir o bloco `SUSPENSA` inteiro:

```php
            if ($oficina->status === 'SUSPENSA') {
                $rotasLiberadas = ['api/assinatura/status-bloqueio', 'api/assinatura/voto-confianca'];
                if (!in_array($request->path(), $rotasLiberadas, true)) {
                    return response()->json([
                        'message' => 'Esta oficina está suspensa. Entre em contato com o suporte.',
                        'code'    => 'OFICINA_SUSPENSA',
                    ], 403);
                }
            }
```

- [ ] **Step 4: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/TenancySuspensaExcecaoTest.php`
Expected: PASS — 1 teste (requer Postgres pra rodar de verdade).

- [ ] **Step 5: Sintaxe**

Run: `cd backend && php -l app/Http/Middleware/InitializeTenancyByHeader.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Middleware/InitializeTenancyByHeader.php backend/tests/Feature/TenancySuspensaExcecaoTest.php
git commit -m "feat(saas): middleware libera rotas de bloqueio para oficina suspensa + codigo OFICINA_SUSPENSA"
```

---

### Task 5: Endpoints tenant-side (`status-bloqueio`, `voto-confianca`)

**Files:**
- Modify: `backend/app/Http/Controllers/AssinaturaController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AssinaturaControllerBloqueioTest.php` (novo)

**Interfaces:**
- Consumes: `AssinaturaAlertaService::statusBloqueio()` (Task 3).
- Produces: `GET /assinatura/status-bloqueio` (tenant, sem role),
  `POST /assinatura/voto-confianca` (tenant, `role:ADMIN`).

- [ ] **Step 1: Escrever o teste**

Reaproveita o padrão `comoTenant()` já usado em
`backend/tests/Feature/AssinaturaControllerTest.php` (leia esse arquivo
primeiro pra copiar o helper).

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssinaturaControllerBloqueioTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComUsuario(string $status = 'SUSPENSA', string $role = 'ADMIN'): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => $status,
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

    public function test_status_bloqueio_retorna_dados_da_fatura(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
        ]);

        $response = $this->comoTenant($oficina, $usuario)->getJson('/api/assinatura/status-bloqueio');

        $response->assertStatus(200)->assertJson(['suspensa' => true]);
    }

    public function test_voto_confianca_libera_acesso(): void
    {
        SaasConfig::get()->update(['voto_confianca_dias' => 5]);
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
        ]);

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/voto-confianca');

        $response->assertStatus(200);
        $oficina->refresh();
        $this->assertSame('ATIVA', $oficina->status);
        $this->assertSame(now()->addDays(5)->toDateString(), $oficina->voto_confianca_ate->toDateString());
    }

    public function test_voto_confianca_bloqueado_se_ja_usado_na_fatura(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
            'voto_confianca_usado_em' => now(),
        ]);

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/voto-confianca');

        $response->assertStatus(422);
        $oficina->refresh();
        $this->assertSame('SUSPENSA', $oficina->status);
    }

    public function test_voto_confianca_bloqueado_para_oficina_nao_suspensa(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('ATIVA');

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/voto-confianca');

        $response->assertStatus(422);
    }

    public function test_voto_confianca_bloqueado_para_nao_admin(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario('SUSPENSA', 'MECANICO');

        $response = $this->comoTenant($oficina, $usuario)->postJson('/api/assinatura/voto-confianca');

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/AssinaturaControllerBloqueioTest.php`
Expected: FAIL — rotas não existem (requer Postgres pra rodar de verdade;
sem banco aqui, confirme por leitura de `routes/api.php` que as 2 rotas
ainda não existem).

- [ ] **Step 3: Adicionar os métodos ao controller**

Em `backend/app/Http/Controllers/AssinaturaController.php`, adicionar
`use App\Models\Cobranca;` e `use App\Models\SaasConfig;` aos imports, e os
2 métodos (depois de `mudarCiclo()`):

```php
    public function statusBloqueio(): JsonResponse
    {
        $oficina = Oficina::find(TenancyContext::get());
        if (!$oficina) {
            return response()->json(['suspensa' => false, 'voto_confianca_disponivel' => false]);
        }

        return response()->json($this->alertaService->statusBloqueio($oficina));
    }

    public function votoConfianca(): JsonResponse
    {
        $oficina = Oficina::findOrFail(TenancyContext::get());

        if ($oficina->status !== 'SUSPENSA') {
            return response()->json(['message' => 'Oficina não está suspensa.'], 422);
        }

        $cobranca = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->where('status', 'VENCIDA')
            ->orderByDesc('vencimento')
            ->first();

        if (!$cobranca) {
            return response()->json(['message' => 'Nenhuma fatura vencida encontrada.'], 422);
        }

        if ($cobranca->voto_confianca_usado_em !== null) {
            return response()->json(['message' => 'Voto de confiança já utilizado para esta fatura.'], 422);
        }

        $dias = SaasConfig::get()->voto_confianca_dias;

        $oficina->update([
            'status'             => 'ATIVA',
            'voto_confianca_ate' => now()->addDays($dias)->toDateString(),
        ]);
        $cobranca->update(['voto_confianca_usado_em' => now()]);

        return response()->json([
            'message'            => "Seu acesso foi liberado por {$dias} dias em voto de confiança.",
            'voto_confianca_ate' => $oficina->voto_confianca_ate->toDateString(),
        ]);
    }
```

- [ ] **Step 4: Adicionar as rotas**

Em `backend/routes/api.php`, logo abaixo de `Route::get('assinatura/alerta', ...)`:
```php
    Route::get('assinatura/status-bloqueio', [AssinaturaController::class, 'statusBloqueio']);
```
E dentro do grupo `role:ADMIN` que já contém `assinatura/mudar-ciclo`:
```php
    Route::post('assinatura/voto-confianca', [AssinaturaController::class, 'votoConfianca']);
```

- [ ] **Step 5: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/AssinaturaControllerBloqueioTest.php`
Expected: PASS — 5 testes (requer Postgres pra rodar de verdade).

- [ ] **Step 6: Sintaxe**

Run: `cd backend && php -l app/Http/Controllers/AssinaturaController.php && php -l routes/api.php`
Expected: `No syntax errors detected` nos 2.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/AssinaturaController.php backend/routes/api.php backend/tests/Feature/AssinaturaControllerBloqueioTest.php
git commit -m "feat(saas): endpoints tenant-side status-bloqueio e voto-confianca"
```

---

### Task 6: SaaS Admin — endpoint de voto de confiança + config

**Files:**
- Modify: `backend/app/Http/Controllers/SaaS/OficinaController.php`
- Modify: `backend/app/Http/Controllers/SaaS/SaasConfigController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Saas/OficinaVotoConfiancaTest.php` (novo)
- Test: `backend/tests/Feature/Saas/SaasConfigVotoConfiancaTest.php` (novo)

**Interfaces:**
- Produces: `POST /saas/oficinas/{id}/voto-confianca` (SaaS admin, sem
  restrição além de `auth:saas`). `GET /saas/config` inclui
  `voto_confianca_dias`; `PUT /saas/config/cobranca` passa a aceitar esse
  campo também.

- [ ] **Step 1: Escrever os testes**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SaasConfig;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OficinaVotoConfiancaTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_admin_concede_voto_confianca_mesmo_ja_usado_na_fatura(): void
    {
        $this->autenticarSuperAdmin();
        SaasConfig::get()->update(['voto_confianca_dias' => 7]);

        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'SUSPENSA',
        ]);
        Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'VENCIDA', 'vencimento' => now()->subDays(12)->toDateString(),
            'voto_confianca_usado_em' => now(), // já usado pelo self-service — admin ignora essa trava
        ]);

        $response = $this->postJson("/api/saas/oficinas/{$oficina->id}/voto-confianca");

        $response->assertStatus(200);
        $oficina->refresh();
        $this->assertSame('ATIVA', $oficina->status);
        $this->assertSame(now()->addDays(7)->toDateString(), $oficina->voto_confianca_ate->toDateString());
    }
}
```

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SaasConfigVotoConfiancaTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_show_inclui_voto_confianca_dias(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->getJson('/api/saas/config');

        $response->assertStatus(200)->assertJsonStructure(['data' => ['voto_confianca_dias']]);
    }

    public function test_update_cobranca_salva_voto_confianca_dias(): void
    {
        $this->autenticarSuperAdmin();

        $response = $this->putJson('/api/saas/config/cobranca', [
            'cobranca_dias_antecedencia_padrao' => 5,
            'cobranca_dias_suspensao_padrao'    => 10,
            'desconto_anual_pct'                => 15,
            'alerta_cobranca_vezes_dia'          => 2,
            'alerta_cobranca_dias_exibicao'      => 20,
            'voto_confianca_dias'                => 7,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('saas_config', ['voto_confianca_dias' => 7]);
    }
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd backend && php artisan test tests/Feature/Saas/OficinaVotoConfiancaTest.php tests/Feature/Saas/SaasConfigVotoConfiancaTest.php`
Expected: FAIL — rota/campos não existem ainda (requer Postgres pra rodar
de verdade; sem banco aqui, confirme por leitura que nada disso existe nos
controllers ainda).

- [ ] **Step 3: Adicionar `OficinaController::votoConfianca()`**

Adicionar `use App\Models\SaasConfig;` se ainda não importado (já está,
usado em outros métodos do arquivo). Adicionar o método (depois de
`reativar()`):

```php
    public function votoConfianca(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $dias    = SaasConfig::get()->voto_confianca_dias;

        $oficina->update([
            'status'             => 'ATIVA',
            'voto_confianca_ate' => now()->addDays($dias)->toDateString(),
        ]);

        $cobranca = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->where('status', 'VENCIDA')
            ->orderByDesc('vencimento')
            ->first();

        $cobranca?->update(['voto_confianca_usado_em' => now()]);

        return response()->json([
            'message' => "Voto de confiança concedido. Acesso liberado por {$dias} dias.",
            'data'    => ['id' => $oficina->id, 'status' => 'ATIVA', 'voto_confianca_ate' => $oficina->voto_confianca_ate->toDateString()],
        ]);
    }
```

- [ ] **Step 4: Rota do voto de confiança (SaaS admin)**

Em `backend/routes/api.php`, logo abaixo de
`Route::post('oficinas/{id}/reativar', ...)`:
```php
        Route::post('oficinas/{id}/voto-confianca', [SaaSOficinaController::class, 'votoConfianca']);
```

- [ ] **Step 5: `SaasConfigController` — `show()` e `updateCobranca()`**

Em `show()`, adicionar (depois de `'alerta_cobranca_dias_exibicao' => $cfg->alerta_cobranca_dias_exibicao,`):
```php
                'voto_confianca_dias' => $cfg->voto_confianca_dias,
```

Em `updateCobranca()`, adicionar `'voto_confianca_dias' => ['required', 'integer', 'min:1', 'max:30'],`
ao array de validação (junto dos outros 5 campos já existentes).

- [ ] **Step 6: Rodar e verificar que passa**

Run: `cd backend && php artisan test tests/Feature/Saas/OficinaVotoConfiancaTest.php tests/Feature/Saas/SaasConfigVotoConfiancaTest.php`
Expected: PASS — 3 testes (requer Postgres pra rodar de verdade).

- [ ] **Step 7: Sintaxe**

Run: `cd backend && php -l app/Http/Controllers/SaaS/OficinaController.php && php -l app/Http/Controllers/SaaS/SaasConfigController.php && php -l routes/api.php`
Expected: `No syntax errors detected` nos 3.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/OficinaController.php backend/app/Http/Controllers/SaaS/SaasConfigController.php backend/routes/api.php backend/tests/Feature/Saas/OficinaVotoConfiancaTest.php backend/tests/Feature/Saas/SaasConfigVotoConfiancaTest.php
git commit -m "feat(saas): voto de confianca pelo SaaS admin + config global de dias"
```

---

### Task 7: Frontend — interceptor + página `/bloqueado`

**Files:**
- Modify: `frontend/lib/api.ts`
- Create: `frontend/app/bloqueado/page.tsx`

**Interfaces:**
- Consumes: `GET /assinatura/status-bloqueio`, `POST /assinatura/voto-confianca`
  (Task 5), `useAuth().getUser()` (existente).

- [ ] **Step 1: Interceptor**

Em `frontend/lib/api.ts`, dentro do `api.interceptors.response.use(...)`
já existente, adicionar a checagem logo depois do bloco `if
(error.response?.status === 401 ...)`:

```typescript
    const isSuspensa = error.response?.status === 403 && error.response?.data?.code === 'OFICINA_SUSPENSA'
    if (isSuspensa && typeof window !== 'undefined' && window.location.pathname !== '/bloqueado') {
      window.location.href = '/bloqueado'
    }
```

- [ ] **Step 2: Página de bloqueio**

```tsx
'use client'
import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import api from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'

interface StatusBloqueio {
  suspensa: boolean
  fase?: 'DISPONIVEL' | 'VENCIDA'
  mensagem?: string
  valor?: string
  vencimento?: string
  link_pagamento?: string | null
  voto_confianca_disponivel: boolean
}

function fmtBRL(v: string): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v))
}

export default function BloqueadoPage() {
  const router = useRouter()
  const { getUser } = useAuth()
  const [status, setStatus] = useState<StatusBloqueio | null>(null)
  const [loading, setLoading] = useState(true)
  const [liberando, setLiberando] = useState(false)
  const [liberado, setLiberado] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  useEffect(() => {
    api.get<StatusBloqueio>('/assinatura/status-bloqueio')
      .then(r => {
        if (!r.data.suspensa) {
          router.push('/')
          return
        }
        setStatus(r.data)
      })
      .catch(() => router.push('/'))
      .finally(() => setLoading(false))
  }, [router])

  async function liberarVotoConfianca() {
    if (!confirm('Deseja liberar seu acesso em voto de confiança?')) return
    setLiberando(true)
    setErro(null)
    try {
      const res = await api.post<{ message: string }>('/assinatura/voto-confianca')
      setLiberado(res.data.message)
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setErro(msg ?? 'Não foi possível liberar o acesso agora.')
    } finally {
      setLiberando(false)
    }
  }

  if (loading || !status) {
    return (
      <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg)', color: 'var(--muted)' }}>
        Carregando...
      </div>
    )
  }

  const isAdmin = getUser()?.role === 'ADMIN'

  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg)', padding: 20 }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--danger)', borderRadius: 14, width: '100%', maxWidth: 480, padding: 36, textAlign: 'center' }}>
        <div style={{ fontSize: 40, marginBottom: 16 }}>🔒</div>
        <h1 className="font-display" style={{ fontSize: 24, fontWeight: 800, color: 'var(--danger)', margin: '0 0 12px' }}>
          Acesso Bloqueado
        </h1>
        <p style={{ fontSize: 14, color: 'var(--text)', lineHeight: 1.6, margin: '0 0 8px' }}>
          {status.mensagem}
        </p>
        {status.valor && (
          <p style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: 22, fontWeight: 700, color: 'var(--accent)', margin: '12px 0 24px' }}>
            {fmtBRL(status.valor)}
          </p>
        )}

        {!liberado && (
          <div style={{ display: 'flex', gap: 10, marginBottom: 24 }}>
            <a href={status.link_pagamento ?? '#'} target="_blank" rel="noopener noreferrer"
              style={{ flex: 1, textAlign: 'center', padding: '12px', background: 'var(--accent)', color: '#000', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", textDecoration: 'none', opacity: status.link_pagamento ? 1 : 0.5, pointerEvents: status.link_pagamento ? 'auto' : 'none' }}>
              Pagar com PIX
            </a>
            <a href={status.link_pagamento ?? '#'} target="_blank" rel="noopener noreferrer"
              style={{ flex: 1, textAlign: 'center', padding: '12px', background: 'transparent', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 8, fontWeight: 700, fontSize: 14, fontFamily: "'Barlow Condensed', sans-serif", textDecoration: 'none', opacity: status.link_pagamento ? 1 : 0.5, pointerEvents: status.link_pagamento ? 'auto' : 'none' }}>
              Pagar com Cartão
            </a>
          </div>
        )}

        {erro && (
          <p style={{ fontSize: 13, color: 'var(--danger)', marginBottom: 16 }}>{erro}</p>
        )}

        {liberado ? (
          <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
            <p style={{ fontSize: 14, color: 'var(--success)', fontWeight: 600, marginBottom: 16 }}>{liberado}</p>
            <button onClick={() => router.push('/')}
              style={{ width: '100%', padding: '11px', background: 'var(--success)', color: '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 14, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
              Voltar ao sistema
            </button>
          </div>
        ) : isAdmin && status.voto_confianca_disponivel ? (
          <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
            <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
              Deseja liberar seu acesso em voto de confiança enquanto regulariza o pagamento?
            </p>
            <button onClick={liberarVotoConfianca} disabled={liberando}
              style={{ width: '100%', padding: '11px', background: 'none', border: '1px solid var(--accent)', color: 'var(--accent)', borderRadius: 8, fontWeight: 700, fontSize: 14, cursor: liberando ? 'not-allowed' : 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
              {liberando ? 'Liberando…' : 'Liberar em voto de confiança'}
            </button>
          </div>
        ) : isAdmin ? (
          <p style={{ fontSize: 13, color: 'var(--muted)', borderTop: '1px solid var(--border)', paddingTop: 20 }}>
            Voto de confiança já utilizado para esta fatura.
          </p>
        ) : null}
      </div>
    </div>
  )
}
```

- [ ] **Step 3: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p tsconfig.json`
Expected: sem erros referenciando `lib/api.ts` ou `app/bloqueado/page.tsx`.

- [ ] **Step 4: Commit**

```bash
git add frontend/lib/api.ts frontend/app/bloqueado/page.tsx
git commit -m "feat(saas): interceptor + pagina de bloqueio para oficina suspensa"
```

---

### Task 8: Frontend — SaaS Admin (botão voto de confiança + config)

**Files:**
- Modify: `frontend/app/saas-admin/(protected)/oficinas/page.tsx`
- Modify: `frontend/app/saas-admin/(protected)/configuracoes/page.tsx`

**Interfaces:**
- Consumes: `POST /saas/oficinas/{id}/voto-confianca` (Task 6),
  `voto_confianca_dias` em `GET/PUT /saas/config` (Task 6).

- [ ] **Step 1: Tipo da ação estendido**

Em `frontend/app/saas-admin/(protected)/oficinas/page.tsx`, o estado
`confirmMap` hoje é `Record<string, 'suspender' | 'reativar'>`. Estender o
tipo pra incluir a nova ação:
```typescript
  const [confirmMap, setConfirmMap] = useState<Record<string, 'suspender' | 'reativar' | 'voto-confianca'>>({})
```
`requestAction`/`confirmAction` já são genéricos (recebem a string da ação e
fazem `saasApi.post(.../{action})`) — não precisam mudar de assinatura, só
o tipo do parâmetro `action` em `requestAction(oficina, action: ...)` que
segue o mesmo union type acima.

- [ ] **Step 2: Botão "Voto de Confiança"**

No bloco de ações da tabela (`oficina.status === 'SUSPENSA' ? (...botão
Reativar...) : (...)`), adicionar o botão novo ao lado do "Reativar"
existente — os dois ficam visíveis juntos quando `SUSPENSA` (são ações
independentes: reativar sem voto = reativação definitiva/manual; voto de
confiança = liberação temporizada):

```tsx
                          ) : oficina.status === 'SUSPENSA' ? (
                            <div style={{ display: 'flex', gap: 6 }}>
                              <button
                                onClick={() => requestAction(oficina, 'reativar')}
                                style={{ background: 'rgba(67,160,71,.1)', border: '1px solid rgba(67,160,71,.3)', color: 'var(--success)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}
                              >
                                Reativar
                              </button>
                              <button
                                onClick={() => requestAction(oficina, 'voto-confianca')}
                                style={{ background: 'rgba(245,166,35,.1)', border: '1px solid rgba(245,166,35,.3)', color: 'var(--accent)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}
                              >
                                Voto de Confiança
                              </button>
                            </div>
                          ) : (
```

Atualizar `confirmAction()`'s mensagem de sucesso (hoje só trata
`'suspender'`/`'reativar'`) pra incluir a 3ª opção:
```typescript
      showSuccess(
        action === 'suspender' ? `Oficina "${oficina.nome}" suspensa.`
        : action === 'reativar' ? `Oficina "${oficina.nome}" reativada.`
        : `Voto de confiança concedido para "${oficina.nome}".`
      )
```

- [ ] **Step 3: Campo de configuração**

Em `frontend/app/saas-admin/(protected)/configuracoes/page.tsx`, seguir o
mesmo padrão já usado pros outros campos da seção "Cobrança Recorrente"
(interface `SaasConfigData`, `useState`, carregamento no `useEffect`,
inclusão no payload de `salvarCobranca()`, input JSX) — adicionar
`voto_confianca_dias` do mesmo jeito que `alerta_cobranca_vezes_dia` foi
adicionado numa spec anterior (mesmos 4 pontos de inserção, mesmo
componente `SectionCard`).

- [ ] **Step 4: Typecheck**

Run: `cd frontend && npx tsc --noEmit -p tsconfig.json`
Expected: sem erros referenciando `oficinas/page.tsx` ou `configuracoes/page.tsx`.

- [ ] **Step 5: Commit**

```bash
git add "frontend/app/saas-admin/(protected)/oficinas/page.tsx" "frontend/app/saas-admin/(protected)/configuracoes/page.tsx"
git commit -m "feat(saas): botao voto de confianca na lista de oficinas + config de dias"
```
