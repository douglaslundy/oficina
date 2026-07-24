# Log de Notificações Visualizadas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar server-side quem visualizou cada notificação (manual do admin ou alerta de cobrança), quantas vezes e de qual IP, e expor isso numa tela do SaaS Admin com log detalhado por toggle.

**Architecture:** Nova tabela central `notificacao_visualizacoes` grava um evento por exibição (tipo MANUAL ou COBRANCA, com snapshot de título/mensagem). O throttle de exibição das notificações manuais (`vezes_dia`/`intervalo_minutos`) migra do `localStorage` do navegador para consultas nessa tabela; o throttle do alerta de cobrança (já server-side, em contador na tabela `oficinas`) continua como está — só ganha um log adicional. `TrustProxies` é configurado para que o IP capturado seja o do usuário real, não o do Traefik.

**Tech Stack:** Laravel 12 (PHP 8.3, PostgreSQL), Next.js (TypeScript), PHPUnit.

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo/editado.
- Sem `any` explícito no TypeScript novo/editado.
- Testes de Feature (`RefreshDatabase`) exigem Postgres, indisponível neste
  ambiente de desenvolvimento local — cada task deixa explícito que o
  `php artisan test` real só roda com banco disponível (CI/produção);
  localmente a verificação é `php -l` (sintaxe) e, quando aplicável,
  `npx tsc --noEmit` / `npm run build` no frontend.
- Datas em `pt-BR`, valores monetários em `R$ X.XXX,XX` (usar
  `formatarDataHora`/`formatarMoeda` de `frontend/lib/formatters.ts`).
- Seguir o design system existente (cores `--card`/`--border`/`--muted`/
  `--accent`, classes `.pill`/`.pill-success`/`.pill-danger`/`.pill-accent`/
  `.pill-info` já definidas em `frontend/app/globals.css`).

---

### Task 1: Tabela e model `NotificacaoVisualizacao`

**Files:**
- Create: `backend/database/migrations/2026_07_23_000001_create_notificacao_visualizacoes_table.php`
- Create: `backend/app/Models/NotificacaoVisualizacao.php`
- Test: `backend/tests/Feature/NotificacaoVisualizacaoModelTest.php`

**Interfaces:**
- Produces: model `App\Models\NotificacaoVisualizacao` com campos
  `id, tipo ('MANUAL'|'COBRANCA'), notificacao_id, cobranca_id, titulo,
  mensagem, oficina_id, usuario_id, ip, user_agent, visualizado_em` e
  relações `oficina(): BelongsTo`, `usuario(): BelongsTo`,
  `cobranca(): BelongsTo`. Usado por todas as tasks seguintes.

- [ ] **Step 1: Escrever o teste (falha por a tabela/model não existirem)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Models\NotificacaoVisualizacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoVisualizacaoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_visualizacao_manual_com_relacoes(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);
        TenancyContext::clear();
        $notificacao = Notificacao::create(['titulo' => 'Aviso', 'texto' => 'Texto do aviso', 'alvo_tipo' => 'TODOS']);

        $visualizacao = NotificacaoVisualizacao::create([
            'tipo' => 'MANUAL', 'notificacao_id' => $notificacao->id,
            'titulo' => $notificacao->titulo, 'mensagem' => $notificacao->texto,
            'oficina_id' => $oficina->id, 'usuario_id' => $usuario->id,
            'ip' => '203.0.113.7', 'user_agent' => 'PHPUnit',
        ]);

        $this->assertDatabaseHas('notificacao_visualizacoes', ['id' => $visualizacao->id, 'tipo' => 'MANUAL']);
        $this->assertSame($oficina->id, $visualizacao->oficina->id);
        $this->assertSame($usuario->id, $visualizacao->usuario->id);
        $this->assertNotNull($visualizacao->visualizado_em);
    }
}
```

- [ ] **Step 2: Rodar o teste (requer Postgres — indisponível neste ambiente)**

Run: `cd backend && php artisan test tests/Feature/NotificacaoVisualizacaoModelTest.php`
Expected: FAIL — `Class "App\Models\NotificacaoVisualizacao" not found`
(requer Postgres pra rodar de verdade; sem banco neste sandbox, confirme a
falha revisando o erro esperado).

- [ ] **Step 3: Criar a migration**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notificacao_visualizacoes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('tipo', 10); // MANUAL | COBRANCA
            $table->uuid('notificacao_id')->nullable();
            $table->uuid('cobranca_id')->nullable();
            $table->string('titulo', 150);
            $table->text('mensagem');
            $table->uuid('oficina_id');
            $table->uuid('usuario_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('visualizado_em')->useCurrent();

            $table->foreign('notificacao_id')->references('id')->on('notificacoes')->nullOnDelete();
            $table->foreign('cobranca_id')->references('id')->on('cobrancas')->nullOnDelete();
            $table->foreign('oficina_id')->references('id')->on('oficinas')->cascadeOnDelete();
            $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();

            $table->index(['tipo', 'notificacao_id']);
            $table->index(['tipo', 'cobranca_id', 'oficina_id']);
            $table->index('oficina_id');
            $table->index('visualizado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacao_visualizacoes');
    }
};
```

- [ ] **Step 4: Criar o model**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotificacaoVisualizacao extends Model
{
    protected $table = 'notificacao_visualizacoes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'tipo', 'notificacao_id', 'cobranca_id', 'titulo', 'mensagem',
        'oficina_id', 'usuario_id', 'ip', 'user_agent',
    ];

    protected $casts = [
        'visualizado_em' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function oficina(): BelongsTo
    {
        return $this->belongsTo(Oficina::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }
}
```

- [ ] **Step 5: Rodar o teste de novo**

Run: `cd backend && php artisan test tests/Feature/NotificacaoVisualizacaoModelTest.php`
Expected: PASS — 1 teste (requer Postgres; sem banco aqui, verifique por
`php -l` no passo seguinte).

- [ ] **Step 6: Checar sintaxe**

Run: `cd backend && php -l database/migrations/2026_07_23_000001_create_notificacao_visualizacoes_table.php && php -l app/Models/NotificacaoVisualizacao.php`
Expected: `No syntax errors detected` nos 2.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_07_23_000001_create_notificacao_visualizacoes_table.php backend/app/Models/NotificacaoVisualizacao.php backend/tests/Feature/NotificacaoVisualizacaoModelTest.php
git commit -m "feat(saas): tabela e model de log de visualizacao de notificacoes"
```

---

### Task 2: TrustProxies + endpoints tenant (registrar visualização e elegibilidade)

**Files:**
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/app/Http/Controllers/NotificacaoController.php`
- Modify: `backend/routes/api.php:185` (logo após a rota `notificacoes/ativas`)
- Test: `backend/tests/Feature/NotificacaoVisualizarTest.php`
- Test: `backend/tests/Feature/NotificacaoAtivasEligibilidadeTest.php`

**Interfaces:**
- Consumes: `App\Models\NotificacaoVisualizacao` (Task 1),
  `App\Tenancy\TenancyContext::get()` (já existe).
- Produces: `POST /api/notificacoes/{id}/visualizar` (tenant, autenticado) —
  grava uma linha `tipo=MANUAL`. `GET /api/notificacoes/ativas` passa a
  filtrar por elegibilidade server-side.

- [ ] **Step 1: Escrever os testes (falham — rota/lógica não existem)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoVisualizarTest extends TestCase
{
    use RefreshDatabase;

    public function test_visualizar_registra_log_com_ip_encaminhado_pelo_proxy(): void
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);
        TenancyContext::clear();
        $notificacao = Notificacao::create(['titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS']);

        $response = $this->withHeaders([
                'X-Tenant' => $oficina->slug,
                'X-Forwarded-For' => '203.0.113.7',
            ])
            ->actingAs($usuario)
            ->postJson("/api/notificacoes/{$notificacao->id}/visualizar");

        $response->assertStatus(201);
        $this->assertDatabaseHas('notificacao_visualizacoes', [
            'tipo' => 'MANUAL', 'notificacao_id' => $notificacao->id,
            'oficina_id' => $oficina->id, 'usuario_id' => $usuario->id,
            'ip' => '203.0.113.7',
        ]);
    }
}
```

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoAtivasEligibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficinaComUsuario(): array
    {
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        $usuario = Usuario::create([
            'nome' => 'Fulano', 'email' => 'fulano@' . uniqid() . '.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);
        TenancyContext::clear();
        return [$oficina, $usuario];
    }

    private function comoTenant(Oficina $oficina, Usuario $usuario): static
    {
        return $this->withHeaders(['X-Tenant' => $oficina->slug])->actingAs($usuario);
    }

    public function test_notificacao_some_apos_atingir_vezes_dia(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        $notificacao = Notificacao::create([
            'titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS',
            'vezes_dia' => 1, 'intervalo_minutos' => 60, 'ativo' => true,
        ]);

        $this->comoTenant($oficina, $usuario)->getJson('/api/notificacoes/ativas')
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->comoTenant($oficina, $usuario)->postJson("/api/notificacoes/{$notificacao->id}/visualizar")
            ->assertStatus(201);

        $this->comoTenant($oficina, $usuario)->getJson('/api/notificacoes/ativas')
            ->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_notificacao_respeita_intervalo_minutos_entre_exibicoes(): void
    {
        [$oficina, $usuario] = $this->criarOficinaComUsuario();
        $notificacao = Notificacao::create([
            'titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS',
            'vezes_dia' => 5, 'intervalo_minutos' => 60, 'ativo' => true,
        ]);

        $this->comoTenant($oficina, $usuario)->postJson("/api/notificacoes/{$notificacao->id}/visualizar")
            ->assertStatus(201);

        $this->comoTenant($oficina, $usuario)->getJson('/api/notificacoes/ativas')
            ->assertStatus(200)->assertJsonCount(0, 'data');

        $this->travel(61)->minutes();

        $this->comoTenant($oficina, $usuario)->getJson('/api/notificacoes/ativas')
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }
}
```

- [ ] **Step 2: Rodar os testes (requer Postgres — indisponível neste ambiente)**

Run: `cd backend && php artisan test tests/Feature/NotificacaoVisualizarTest.php tests/Feature/NotificacaoAtivasEligibilidadeTest.php`
Expected: FAIL — rota `notificacoes/{id}/visualizar` não existe, `ativas()`
ainda não filtra por elegibilidade (requer Postgres pra rodar de verdade;
sem banco neste sandbox, confirme a falha revisando os erros esperados).

- [ ] **Step 3: Configurar `TrustProxies` em `bootstrap/app.php`**

Modificar o bloco `->withMiddleware(...)`:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        // Backend nunca é exposto diretamente — só o Traefik tem porta
        // publicada (ver docker-compose.vps.yml/prod.yml) — confiar em
        // qualquer proxy é seguro nesta topologia e necessário pra
        // $request->ip() refletir o IP real do usuário, não o do container
        // do proxy.
        $middleware->trustProxies(at: '*');

        $middleware->api(
            prepend: [\Illuminate\Http\Middleware\HandleCors::class],
            append: [\App\Http\Middleware\SecurityHeaders::class],
        );

        $middleware->alias([
            'tenant' => \App\Http\Middleware\InitializeTenancyByHeader::class,
            'role'   => \App\Http\Middleware\CheckRole::class,
        ]);
    })
```

- [ ] **Step 4: Adicionar `visualizar()` e mudar `ativas()` para elegibilidade server-side**

Substituir todo o conteúdo de `backend/app/Http/Controllers/NotificacaoController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notificacao;
use App\Models\NotificacaoVisualizacao;
use App\Models\Oficina;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacaoController extends Controller
{
    /** Notificações ativas e elegíveis para a oficina atual (para exibir no modal). */
    public function ativas(): JsonResponse
    {
        $oficina = Oficina::find(TenancyContext::get());
        if (!$oficina) {
            return response()->json(['data' => []]);
        }

        $hoje = now()->toDateString();

        $notificacoes = Notificacao::where('ativo', true)
            ->where(fn ($q) => $q->whereNull('data_inicio')->orWhere('data_inicio', '<=', $hoje))
            ->where(fn ($q) => $q->whereNull('data_fim')->orWhere('data_fim', '>=', $hoje))
            ->orderByDesc('criado_em')
            ->get()
            ->filter(function (Notificacao $n) use ($oficina) {
                return match ($n->alvo_tipo) {
                    'TODOS'    => true,
                    'PLANO'    => $n->plano_id === $oficina->plano_id,
                    'OFICINAS' => in_array($oficina->id, (array) $n->oficina_ids, true),
                    default    => false,
                };
            })
            ->filter(fn (Notificacao $n) => $this->elegivelParaExibir($n, $oficina))
            ->map(fn (Notificacao $n) => [
                'id'        => $n->id,
                'titulo'    => $n->titulo,
                'subtitulo' => $n->subtitulo,
                'texto'     => $n->texto,
                'imagem'    => $n->imagem,
            ])
            ->values();

        return response()->json(['data' => $notificacoes]);
    }

    /** Registra que a oficina/usuário atual visualizou (fechou) a notificação. */
    public function visualizar(string $id): JsonResponse
    {
        $notificacao = Notificacao::findOrFail($id);
        $oficinaId = TenancyContext::get();

        NotificacaoVisualizacao::create([
            'tipo'           => 'MANUAL',
            'notificacao_id' => $notificacao->id,
            'titulo'         => $notificacao->titulo,
            'mensagem'       => $notificacao->texto,
            'oficina_id'     => $oficinaId,
            'usuario_id'     => auth()->id(),
            'ip'             => request()->ip(),
            'user_agent'     => request()->userAgent(),
        ]);

        return response()->json(['message' => 'Visualização registrada.'], 201);
    }

    /**
     * Elegibilidade server-side: no máximo `vezes_dia` exibições por dia,
     * respeitando `intervalo_minutos` desde a última exibição — throttle
     * por oficina (todos os usuários da equipe compartilham a mesma cota),
     * não por usuário individual.
     */
    private function elegivelParaExibir(Notificacao $n, Oficina $oficina): bool
    {
        $hoje = now()->toDateString();

        $countHoje = NotificacaoVisualizacao::where('notificacao_id', $n->id)
            ->where('oficina_id', $oficina->id)
            ->whereDate('visualizado_em', $hoje)
            ->count();

        if ($countHoje >= $n->vezes_dia) {
            return false;
        }

        $ultima = NotificacaoVisualizacao::where('notificacao_id', $n->id)
            ->where('oficina_id', $oficina->id)
            ->orderByDesc('visualizado_em')
            ->value('visualizado_em');

        if ($ultima && now()->diffInMinutes($ultima) < $n->intervalo_minutos) {
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 5: Adicionar a rota**

Em `backend/routes/api.php`, logo após a linha 185
(`Route::get('notificacoes/ativas', ...)`), adicionar:

```php
    Route::post('notificacoes/{id}/visualizar', [\App\Http\Controllers\NotificacaoController::class, 'visualizar']);
```

- [ ] **Step 6: Rodar os testes de novo**

Run: `cd backend && php artisan test tests/Feature/NotificacaoVisualizarTest.php tests/Feature/NotificacaoAtivasEligibilidadeTest.php`
Expected: PASS — 3 testes (requer Postgres; sem banco aqui, verifique por
`php -l` no passo seguinte).

- [ ] **Step 7: Checar sintaxe**

Run: `cd backend && php -l bootstrap/app.php && php -l app/Http/Controllers/NotificacaoController.php && php -l routes/api.php`
Expected: `No syntax errors detected` nos 3.

- [ ] **Step 8: Commit**

```bash
git add backend/bootstrap/app.php backend/app/Http/Controllers/NotificacaoController.php backend/routes/api.php backend/tests/Feature/NotificacaoVisualizarTest.php backend/tests/Feature/NotificacaoAtivasEligibilidadeTest.php
git commit -m "feat(saas): TrustProxies + log server-side de visualizacao de notificacao manual"
```

---

### Task 3: Frontend — `NotificacaoModal` grava visualização no backend

**Files:**
- Modify: `frontend/components/NotificacaoModal.tsx`

**Interfaces:**
- Consumes: `POST /notificacoes/{id}/visualizar` (Task 2), `GET
  /notificacoes/ativas` (já retorna só elegíveis — Task 2).
- Produces: nenhuma interface nova para outras tasks.

- [ ] **Step 1: Substituir o arquivo inteiro**

```tsx
'use client'
import { useEffect, useState } from 'react'
import api from '@/lib/api'
import { NotificacaoCard } from '@/components/NotificacaoCard'

interface Notificacao {
  id: string
  titulo: string
  subtitulo: string | null
  texto: string
  imagem: string | null
}

export function NotificacaoModal() {
  const [atual, setAtual] = useState<Notificacao | null>(null)

  useEffect(() => {
    api.get<{ data: Notificacao[] }>('/notificacoes/ativas')
      .then(r => {
        const lista = r.data.data ?? []
        if (lista.length > 0) setAtual(lista[0])
      })
      .catch(() => { /* silencioso */ })
  }, [])

  function fechar() {
    if (atual) {
      api.post(`/notificacoes/${atual.id}/visualizar`).catch(() => { /* silencioso */ })
    }
    setAtual(null)
  }

  if (!atual) return null

  return <NotificacaoCard notificacao={atual} onFechar={fechar} />
}
```

- [ ] **Step 2: Checar tipos**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros referenciando `NotificacaoModal.tsx`.

- [ ] **Step 3: Commit**

```bash
git add frontend/components/NotificacaoModal.tsx
git commit -m "feat(saas): NotificacaoModal registra visualizacao no backend em vez de localStorage"
```

---

### Task 4: `AssinaturaAlertaService` grava log de visualização de cobrança

**Files:**
- Modify: `backend/app/Services/AssinaturaAlertaService.php`
- Test: `backend/tests/Feature/Saas/AssinaturaAlertaLogTest.php`

**Interfaces:**
- Consumes: `App\Models\NotificacaoVisualizacao` (Task 1).
- Produces: toda vez que `GET /assinatura/alerta` retorna `show=true`, uma
  linha `tipo=COBRANCA` é gravada — sem nenhuma mudança na resposta JSON
  nem na lógica de throttle existente (`podeExibirHoje`/`registrarExibicao`
  continuam intocados).

- [ ] **Step 1: Escrever o teste (falha — nenhuma linha é gravada ainda)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssinaturaAlertaLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerta_de_cobranca_grava_log_de_visualizacao(): void
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
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('senha123'),
        ]);
        TenancyContext::clear();
        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);

        $response = $this->withHeaders(['X-Tenant' => $oficina->slug])
            ->actingAs($usuario)
            ->getJson('/api/assinatura/alerta');

        $response->assertStatus(200)->assertJson(['show' => true]);
        $this->assertDatabaseHas('notificacao_visualizacoes', [
            'tipo' => 'COBRANCA', 'cobranca_id' => $cobranca->id,
            'oficina_id' => $oficina->id, 'usuario_id' => $usuario->id,
        ]);
    }
}
```

- [ ] **Step 2: Rodar o teste (requer Postgres — indisponível neste ambiente)**

Run: `cd backend && php artisan test tests/Feature/Saas/AssinaturaAlertaLogTest.php`
Expected: FAIL — nenhuma linha em `notificacao_visualizacoes` (requer
Postgres pra rodar de verdade; sem banco neste sandbox, confirme a falha
revisando o erro esperado).

- [ ] **Step 3: Injetar `Request` e gravar o log em `status()`**

Em `backend/app/Services/AssinaturaAlertaService.php`, adicionar imports no
topo:

```php
use App\Models\NotificacaoVisualizacao;
use Illuminate\Http\Request;
```

Adicionar um construtor à classe (logo após `class AssinaturaAlertaService`):

```php
    public function __construct(private readonly Request $request)
    {
    }
```

Em `status()`, trocar:

```php
        $this->registrarExibicao($oficina);

        return [
```

por:

```php
        $this->registrarExibicao($oficina);
        $this->registrarLog($oficina, $cobranca, $fase, $mensagem);

        return [
```

E adicionar o método privado (junto aos outros métodos privados no fim da
classe, antes de `formatarMoeda`):

```php
    private function registrarLog(Oficina $oficina, Cobranca $cobranca, string $fase, string $mensagem): void
    {
        NotificacaoVisualizacao::create([
            'tipo'        => 'COBRANCA',
            'cobranca_id' => $cobranca->id,
            'titulo'      => $fase === 'VENCIDA' ? 'Fatura vencida' : 'Fatura disponível para pagamento',
            'mensagem'    => $mensagem,
            'oficina_id'  => $oficina->id,
            'usuario_id'  => auth()->id(),
            'ip'          => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
```

- [ ] **Step 4: Rodar o teste de novo**

Run: `cd backend && php artisan test tests/Feature/Saas/AssinaturaAlertaLogTest.php`
Expected: PASS — 1 teste (requer Postgres; sem banco aqui, verifique por
`php -l` no passo seguinte).

- [ ] **Step 5: Checar sintaxe**

Run: `cd backend && php -l app/Services/AssinaturaAlertaService.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AssinaturaAlertaService.php backend/tests/Feature/Saas/AssinaturaAlertaLogTest.php
git commit -m "feat(saas): loga visualizacao do alerta de cobranca sem alterar seu throttle"
```

---

### Task 5: SaaS Admin — endpoints de log das notificações manuais

**Files:**
- Modify: `backend/app/Http/Controllers/SaaS/NotificacaoController.php`
- Modify: `backend/routes/api.php:143` (logo após a rota `patch
  notificacoes/{id}/ativo`)
- Test: `backend/tests/Feature/Saas/NotificacaoLogTest.php`

**Interfaces:**
- Consumes: `App\Models\NotificacaoVisualizacao` (Task 1).
- Produces: `GET /api/saas/notificacoes` ganha `total_visualizacoes`
  (int) e `oficinas_distintas` (int) por item. Novo
  `GET /api/saas/notificacoes/{id}/log` — paginado (`data, current_page,
  last_page, total, per_page`), cada item com `oficina: {nome}`,
  `usuario: {nome}|null`, `ip`, `visualizado_em`.

- [ ] **Step 1: Escrever os testes (falham — campos/rota não existem)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Notificacao;
use App\Models\NotificacaoVisualizacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoLogTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_index_traz_contagem_de_visualizacoes(): void
    {
        $this->autenticarSuperAdmin();
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        $notificacao = Notificacao::create(['titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS']);
        NotificacaoVisualizacao::create([
            'tipo' => 'MANUAL', 'notificacao_id' => $notificacao->id,
            'titulo' => 'Aviso', 'mensagem' => 'Texto', 'oficina_id' => $oficina->id,
            'ip' => '203.0.113.7',
        ]);

        $response = $this->getJson('/api/saas/notificacoes');

        $response->assertStatus(200);
        $item = collect($response->json('data'))->firstWhere('id', $notificacao->id);
        $this->assertSame(1, $item['total_visualizacoes']);
        $this->assertSame(1, $item['oficinas_distintas']);
    }

    public function test_log_retorna_visualizacoes_paginadas(): void
    {
        $this->autenticarSuperAdmin();
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        $notificacao = Notificacao::create(['titulo' => 'Aviso', 'texto' => 'Texto', 'alvo_tipo' => 'TODOS']);
        NotificacaoVisualizacao::create([
            'tipo' => 'MANUAL', 'notificacao_id' => $notificacao->id,
            'titulo' => 'Aviso', 'mensagem' => 'Texto', 'oficina_id' => $oficina->id,
            'ip' => '203.0.113.7',
        ]);

        $response = $this->getJson("/api/saas/notificacoes/{$notificacao->id}/log");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($oficina->nome, $response->json('data.0.oficina.nome'));
    }
}
```

- [ ] **Step 2: Rodar os testes (requer Postgres — indisponível neste ambiente)**

Run: `cd backend && php artisan test tests/Feature/Saas/NotificacaoLogTest.php`
Expected: FAIL — `total_visualizacoes` ausente, rota `/log` inexistente
(requer Postgres pra rodar de verdade; sem banco neste sandbox, confirme a
falha revisando os erros esperados).

- [ ] **Step 3: Substituir o controller**

Substituir todo o conteúdo de
`backend/app/Http/Controllers/SaaS/NotificacaoController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Notificacao;
use App\Models\NotificacaoVisualizacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacaoController extends Controller
{
    public function index(): JsonResponse
    {
        $data = Notificacao::orderByDesc('criado_em')->get()->map(function (Notificacao $n) {
            $arr = $n->toArray();
            $arr['total_visualizacoes'] = NotificacaoVisualizacao::where('notificacao_id', $n->id)->count();
            $arr['oficinas_distintas']  = NotificacaoVisualizacao::where('notificacao_id', $n->id)
                ->distinct()->count('oficina_id');
            return $arr;
        });

        return response()->json(['data' => $data]);
    }

    /** Log paginado de visualizações de uma notificação manual específica. */
    public function log(string $id): JsonResponse
    {
        Notificacao::findOrFail($id);

        $logs = NotificacaoVisualizacao::where('notificacao_id', $id)
            ->with(['oficina:id,nome', 'usuario:id,nome'])
            ->orderByDesc('visualizado_em')
            ->paginate(20);

        return response()->json($logs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $data['ativo'] = false;
        $notificacao = Notificacao::create($data);
        return response()->json(['message' => 'Notificação criada.', 'data' => $notificacao], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $notificacao = Notificacao::findOrFail($id);
        $notificacao->update($this->validatePayload($request));
        return response()->json(['message' => 'Notificação atualizada.', 'data' => $notificacao]);
    }

    public function destroy(string $id): JsonResponse
    {
        Notificacao::findOrFail($id)->delete();
        return response()->json(['message' => 'Notificação removida.']);
    }

    public function publicar(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['ativo' => ['required', 'boolean']]);
        $notificacao = Notificacao::findOrFail($id);
        $notificacao->update(['ativo' => $validated['ativo']]);
        return response()->json(['message' => 'Status atualizado.', 'data' => $notificacao]);
    }

    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'titulo'            => ['required', 'string', 'max:150'],
            'subtitulo'         => ['nullable', 'string', 'max:200'],
            'texto'             => ['required', 'string'],
            'imagem'            => ['nullable', 'string', 'max:2500000'], // data URL base64
            'alvo_tipo'         => ['required', 'in:TODOS,PLANO,OFICINAS'],
            'plano_id'          => ['nullable', 'required_if:alvo_tipo,PLANO', 'uuid'],
            'oficina_ids'       => ['array', 'required_if:alvo_tipo,OFICINAS'],
            'oficina_ids.*'     => ['uuid'],
            'vezes_dia'         => ['required', 'integer', 'min:1', 'max:50'],
            'intervalo_minutos' => ['required', 'integer', 'min:1', 'max:10080'],
            'data_inicio'       => ['nullable', 'date'],
            'data_fim'          => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'ativo'             => ['boolean'],
        ]);

        if ($validated['alvo_tipo'] !== 'PLANO')    $validated['plano_id'] = null;
        if ($validated['alvo_tipo'] !== 'OFICINAS') $validated['oficina_ids'] = [];

        return $validated;
    }
}
```

- [ ] **Step 4: Adicionar a rota**

Em `backend/routes/api.php`, logo após a linha 143
(`Route::patch('notificacoes/{id}/ativo', ...)`), adicionar:

```php
        Route::get('notificacoes/{id}/log', [\App\Http\Controllers\SaaS\NotificacaoController::class, 'log']);
```

- [ ] **Step 5: Rodar os testes de novo**

Run: `cd backend && php artisan test tests/Feature/Saas/NotificacaoLogTest.php`
Expected: PASS — 2 testes (requer Postgres; sem banco aqui, verifique por
`php -l` no passo seguinte).

- [ ] **Step 6: Checar sintaxe**

Run: `cd backend && php -l app/Http/Controllers/SaaS/NotificacaoController.php && php -l routes/api.php`
Expected: `No syntax errors detected` nos 2.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/NotificacaoController.php backend/routes/api.php backend/tests/Feature/Saas/NotificacaoLogTest.php
git commit -m "feat(saas): endpoint de log de visualizacao das notificacoes manuais"
```

---

### Task 6: SaaS Admin — endpoints de log do alerta de cobrança

**Files:**
- Create: `backend/app/Http/Controllers/SaaS/NotificacaoCobrancaLogController.php`
- Modify: `backend/routes/api.php` (dentro do grupo `saas`/`auth:saas`,
  logo após a rota adicionada na Task 5)
- Test: `backend/tests/Feature/Saas/NotificacaoCobrancaLogTest.php`

**Interfaces:**
- Consumes: `App\Models\NotificacaoVisualizacao` (Task 1).
- Produces: `GET /api/saas/notificacoes-cobranca` — lista agrupada por
  `(oficina_id, cobranca_id)` com `total_exibicoes` (int),
  `ultima_exibicao_em`, `oficina: {nome}`, `cobranca: {valor, vencimento,
  status}`. `GET /api/saas/notificacoes-cobranca/log?oficina_id=&cobranca_id=`
  — paginado, mesmo formato de item de log da Task 5 (sem `oficina`, já
  filtrado por uma só).

- [ ] **Step 1: Escrever os testes (falham — controller/rotas não existem)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Saas;

use App\Models\Cobranca;
use App\Models\NotificacaoVisualizacao;
use App\Models\Oficina;
use App\Models\Plano;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificacaoCobrancaLogTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarSuperAdmin(): void
    {
        $admin = SuperAdmin::create(['nome' => 'Super', 'email' => 'super@teste.com', 'senha_hash' => Hash::make('senha123')]);
        $this->actingAs($admin, 'saas');
    }

    public function test_index_agrupa_por_oficina_e_cobranca(): void
    {
        $this->autenticarSuperAdmin();
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);
        NotificacaoVisualizacao::create([
            'tipo' => 'COBRANCA', 'cobranca_id' => $cobranca->id,
            'titulo' => 'Fatura disponível', 'mensagem' => 'texto', 'oficina_id' => $oficina->id,
        ]);
        NotificacaoVisualizacao::create([
            'tipo' => 'COBRANCA', 'cobranca_id' => $cobranca->id,
            'titulo' => 'Fatura disponível', 'mensagem' => 'texto', 'oficina_id' => $oficina->id,
        ]);

        $response = $this->getJson('/api/saas/notificacoes-cobranca');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame(2, $response->json('data.0.total_exibicoes'));
    }

    public function test_log_retorna_visualizacoes_do_grupo(): void
    {
        $this->autenticarSuperAdmin();
        $plano = Plano::create(['nome' => 'Padrão', 'preco_mensal' => 100]);
        $oficina = Oficina::create([
            'nome' => 'Teste', 'cnpj' => '11222333000181', 'slug' => 'teste-' . uniqid(),
            'plano_id' => $plano->id, 'status' => 'ATIVA',
        ]);
        $cobranca = Cobranca::create([
            'oficina_id' => $oficina->id, 'tipo' => 'ASSINATURA', 'valor' => 100,
            'status' => 'PENDENTE', 'vencimento' => now()->addDays(3)->toDateString(),
        ]);
        NotificacaoVisualizacao::create([
            'tipo' => 'COBRANCA', 'cobranca_id' => $cobranca->id,
            'titulo' => 'Fatura disponível', 'mensagem' => 'texto', 'oficina_id' => $oficina->id,
            'ip' => '203.0.113.7',
        ]);

        $response = $this->getJson("/api/saas/notificacoes-cobranca/log?oficina_id={$oficina->id}&cobranca_id={$cobranca->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('203.0.113.7', $response->json('data.0.ip'));
    }
}
```

- [ ] **Step 2: Rodar os testes (requer Postgres — indisponível neste ambiente)**

Run: `cd backend && php artisan test tests/Feature/Saas/NotificacaoCobrancaLogTest.php`
Expected: FAIL — classe/rotas inexistentes (requer Postgres pra rodar de
verdade; sem banco neste sandbox, confirme a falha revisando os erros
esperados).

- [ ] **Step 3: Criar o controller**

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\NotificacaoVisualizacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacaoCobrancaLogController extends Controller
{
    /** Agrupa exibições do alerta de cobrança por (oficina, cobrança). */
    public function index(): JsonResponse
    {
        $grupos = NotificacaoVisualizacao::query()
            ->where('tipo', 'COBRANCA')
            ->select('oficina_id', 'cobranca_id')
            ->selectRaw('count(*) as total_exibicoes')
            ->selectRaw('max(visualizado_em) as ultima_exibicao_em')
            ->groupBy('oficina_id', 'cobranca_id')
            ->orderByDesc('ultima_exibicao_em')
            ->with(['oficina:id,nome', 'cobranca:id,valor,vencimento,status'])
            ->get()
            ->map(function ($g) {
                $g->total_exibicoes = (int) $g->total_exibicoes;
                return $g;
            });

        return response()->json(['data' => $grupos]);
    }

    /** Log paginado de visualizações de um grupo (oficina, cobrança) específico. */
    public function log(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'oficina_id'  => ['required', 'uuid'],
            'cobranca_id' => ['required', 'uuid'],
        ]);

        $logs = NotificacaoVisualizacao::where('tipo', 'COBRANCA')
            ->where('oficina_id', $validated['oficina_id'])
            ->where('cobranca_id', $validated['cobranca_id'])
            ->with('usuario:id,nome')
            ->orderByDesc('visualizado_em')
            ->paginate(20);

        return response()->json($logs);
    }
}
```

- [ ] **Step 4: Adicionar as rotas**

Em `backend/routes/api.php`, logo após a rota `notificacoes/{id}/log`
adicionada na Task 5, adicionar:

```php
        Route::get('notificacoes-cobranca',     [\App\Http\Controllers\SaaS\NotificacaoCobrancaLogController::class, 'index']);
        Route::get('notificacoes-cobranca/log', [\App\Http\Controllers\SaaS\NotificacaoCobrancaLogController::class, 'log']);
```

- [ ] **Step 5: Rodar os testes de novo**

Run: `cd backend && php artisan test tests/Feature/Saas/NotificacaoCobrancaLogTest.php`
Expected: PASS — 2 testes (requer Postgres; sem banco aqui, verifique por
`php -l` no passo seguinte).

- [ ] **Step 6: Checar sintaxe**

Run: `cd backend && php -l app/Http/Controllers/SaaS/NotificacaoCobrancaLogController.php && php -l routes/api.php`
Expected: `No syntax errors detected` nos 2.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/NotificacaoCobrancaLogController.php backend/routes/api.php backend/tests/Feature/Saas/NotificacaoCobrancaLogTest.php
git commit -m "feat(saas): endpoints de log agrupado do alerta de cobranca"
```

---

### Task 7: Frontend — componente compartilhado de log inline (toggle)

**Files:**
- Create: `frontend/components/saas/NotificacaoLogInline.tsx`

**Interfaces:**
- Consumes: qualquer endpoint paginado no formato `{data, current_page,
  last_page, total, per_page}` cujos itens tenham `id, ip, user_agent,
  visualizado_em` e opcionalmente `oficina: {nome}`, `usuario: {nome}`.
- Produces: componente `NotificacaoLogInline({ endpoint, mostrarOficina,
  colSpan })` — uma `<tr>` com `<td colSpan>` contendo a tabela paginada de
  log. Usado pelas Tasks 8 e 9.

- [ ] **Step 1: Criar o componente**

```tsx
'use client'
import { useState, useEffect, useCallback } from 'react'
import saasApi from '@/lib/saas-api'
import { formatarDataHora } from '@/lib/formatters'

interface LogRow {
  id: string
  ip: string | null
  user_agent: string | null
  visualizado_em: string
  oficina?: { nome: string } | null
  usuario?: { nome: string } | null
}
interface Paginated {
  data: LogRow[]
  current_page: number
  last_page: number
  total: number
}

export function NotificacaoLogInline({ endpoint, mostrarOficina, colSpan }: {
  endpoint: string
  mostrarOficina: boolean
  colSpan: number
}) {
  const [logs, setLogs] = useState<Paginated | null>(null)
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)

  const carregar = useCallback(() => {
    setLoading(true)
    const sep = endpoint.includes('?') ? '&' : '?'
    saasApi.get<Paginated>(`${endpoint}${sep}page=${page}`)
      .then(r => setLogs(r.data))
      .catch(() => setLogs(null))
      .finally(() => setLoading(false))
  }, [endpoint, page])

  useEffect(() => { carregar() }, [carregar])

  return (
    <tr>
      <td colSpan={colSpan} style={{ padding: 0, background: 'var(--bg)' }}>
        <div style={{ padding: '14px 20px' }}>
          {loading ? (
            <div style={{ color: 'var(--muted)', fontSize: 13, padding: 12 }}>Carregando...</div>
          ) : !logs || logs.data.length === 0 ? (
            <div style={{ color: 'var(--muted)', fontSize: 13, padding: 12 }}>Nenhuma visualização registrada.</div>
          ) : (
            <>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr>
                    {[...(mostrarOficina ? ['Oficina'] : []), 'Usuário', 'Data/Hora', 'IP'].map(h => (
                      <th key={h} style={{ padding: '6px 10px', textAlign: 'left', fontSize: 10, fontWeight: 700, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {logs.data.map(l => (
                    <tr key={l.id} style={{ borderTop: '1px solid var(--border)' }}>
                      {mostrarOficina && <td style={{ padding: '6px 10px', fontSize: 12 }}>{l.oficina?.nome ?? '—'}</td>}
                      <td style={{ padding: '6px 10px', fontSize: 12 }}>{l.usuario?.nome ?? '—'}</td>
                      <td style={{ padding: '6px 10px', fontSize: 12, fontFamily: 'monospace' }}>{formatarDataHora(l.visualizado_em)}</td>
                      <td style={{ padding: '6px 10px', fontSize: 12, fontFamily: 'monospace' }}>{l.ip ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {logs.last_page > 1 && (
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 10 }}>
                  <span style={{ fontSize: 11, color: 'var(--muted)' }}>{logs.total} registros · Página {logs.current_page} de {logs.last_page}</span>
                  <div style={{ display: 'flex', gap: 6 }}>
                    <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} style={{ padding: '3px 10px', borderRadius: 5, background: 'transparent', border: '1px solid var(--border)', color: page <= 1 ? 'var(--border)' : 'var(--muted)', cursor: page <= 1 ? 'not-allowed' : 'pointer', fontSize: 11 }}>← Anterior</button>
                    <button disabled={page >= logs.last_page} onClick={() => setPage(p => p + 1)} style={{ padding: '3px 10px', borderRadius: 5, background: 'transparent', border: '1px solid var(--border)', color: page >= logs.last_page ? 'var(--border)' : 'var(--muted)', cursor: page >= logs.last_page ? 'not-allowed' : 'pointer', fontSize: 11 }}>Próxima →</button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </td>
    </tr>
  )
}
```

- [ ] **Step 2: Checar tipos**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros referenciando `NotificacaoLogInline.tsx`.

- [ ] **Step 3: Commit**

```bash
git add frontend/components/saas/NotificacaoLogInline.tsx
git commit -m "feat(saas): componente compartilhado de log inline com toggle e paginacao"
```

---

### Task 8: Frontend — aba "Manuais" com leituras e toggle de log

**Files:**
- Modify: `frontend/app/saas-admin/(protected)/notificacoes/page.tsx`

**Interfaces:**
- Consumes: `GET /saas/notificacoes` com `total_visualizacoes`/
  `oficinas_distintas` (Task 5), `NotificacaoLogInline` (Task 7).

- [ ] **Step 1: Adicionar os campos novos na interface `Notif`**

Em `frontend/app/saas-admin/(protected)/notificacoes/page.tsx`, no bloco
`interface Notif { ... }` (linhas 6-20), adicionar antes do `}` final:

```tsx
  total_visualizacoes?: number
  oficinas_distintas?: number
```

- [ ] **Step 2: Importar o componente de log e adicionar estado de abas/expansão**

Logo após `import { NotificacaoCard } from '@/components/NotificacaoCard'`
(linha 4), adicionar:

```tsx
import { NotificacaoLogInline } from '@/components/saas/NotificacaoLogInline'
import { NotificacaoCobrancaTable } from '@/components/saas/NotificacaoCobrancaTable'
```

No início da função `NotificacoesPage` (logo após `const [togglingId,
setTogglingId] = useState<string | null>(null)`, linha 158), adicionar:

```tsx
  const [aba, setAba] = useState<'manuais' | 'cobranca'>('manuais')
  const [expandido, setExpandido] = useState<string | null>(null)
```

- [ ] **Step 3: Adicionar o seletor de abas**

Substituir o bloco do cabeçalho (linhas 201-209, o `<div style={{ display:
'flex', justifyContent: 'space-between'...`) por:

```tsx
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: 24 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Notificações</h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>Avisos exibidos às oficinas ao acessar o sistema</p>
        </div>
        {aba === 'manuais' && (
          <button onClick={() => setEditando({ ...VAZIO })} style={{ background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, padding: '10px 22px', fontSize: 14, fontWeight: 700, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
            + Nova Notificação
          </button>
        )}
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
        {(['manuais', 'cobranca'] as const).map(a => (
          <button key={a} onClick={() => setAba(a)}
            style={{
              padding: '8px 18px', borderRadius: 8, cursor: 'pointer', fontSize: 14, fontWeight: 700,
              fontFamily: "'Barlow Condensed', sans-serif",
              background: aba === a ? 'var(--accent)' : 'transparent',
              color: aba === a ? '#000' : 'var(--muted)',
              border: `1px solid ${aba === a ? 'var(--accent)' : 'var(--border)'}`,
            }}>
            {a === 'manuais' ? 'Manuais' : 'Cobrança'}
          </button>
        ))}
      </div>

      {aba === 'cobranca' && <NotificacaoCobrancaTable />}
      {aba === 'manuais' && (
```

(a chave de abertura `(` aqui inicia um bloco JSX que envolve a tabela
existente — ver Step 4).

- [ ] **Step 4: Envolver a tabela existente, adicionar coluna "Leituras" e o toggle**

Substituir o restante do JSX (da linha 211, `<div style={{ background:
'var(--card)'...`, até o fechamento do componente nas linhas 250-253) por:

```tsx
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr>
              {['Título', 'Direcionamento', 'Freq.', 'Leituras', 'Status', 'Ações'].map(c => (
                <th key={c} style={{ padding: '11px 16px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)' }}>{c}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</td></tr>
            ) : lista.length === 0 ? (
              <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Nenhuma notificação cadastrada.</td></tr>
            ) : lista.map((n, i) => (
              <Fragment key={n.id}>
                <tr style={{ borderBottom: i < lista.length - 1 && expandido !== n.id ? '1px solid var(--border)' : undefined }}>
                  <td style={{ padding: '12px 16px' }}>
                    <div style={{ fontWeight: 600 }}>{n.titulo}</div>
                    {n.subtitulo && <div style={{ fontSize: 12, color: 'var(--muted)' }}>{n.subtitulo}</div>}
                  </td>
                  <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{ALVO[n.alvo_tipo]}</td>
                  <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{n.vezes_dia}x/dia · {n.intervalo_minutos}min</td>
                  <td style={{ padding: '12px 16px' }}>
                    <button onClick={() => setExpandido(expandido === n.id ? null : n.id)}
                      style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(30,136,229,.1)', border: '1px solid rgba(30,136,229,.3)', color: 'var(--info)', cursor: 'pointer', fontSize: 13 }}>
                      {n.total_visualizacoes ?? 0} · {n.oficinas_distintas ?? 0} oficina(s) {expandido === n.id ? '▲' : '▼'}
                    </button>
                  </td>
                  <td style={{ padding: '12px 16px' }}>
                    <span className={`pill ${n.ativo ? 'pill-success' : 'pill-muted'}`}>{n.ativo ? 'Publicada' : 'Rascunho'}</span>
                  </td>
                  <td style={{ padding: '12px 16px' }}>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                      <button onClick={() => setPreviewing(n)} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(30,136,229,.1)', border: '1px solid rgba(30,136,229,.3)', color: 'var(--info)', cursor: 'pointer', fontSize: 13 }}>👁 Visualizar</button>
                      <button onClick={() => alternarAtivo(n)} disabled={togglingId === n.id} style={{ padding: '5px 12px', borderRadius: 6, background: n.ativo ? 'rgba(122,128,144,.15)' : 'rgba(67,160,71,.1)', border: `1px solid ${n.ativo ? 'var(--border)' : 'rgba(67,160,71,.3)'}`, color: n.ativo ? 'var(--muted)' : 'var(--success)', cursor: togglingId === n.id ? 'not-allowed' : 'pointer', fontSize: 13 }}>
                        {togglingId === n.id ? '⟳' : n.ativo ? 'Despublicar' : 'Publicar'}
                      </button>
                      <button onClick={() => setEditando(n)} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(245,166,35,.1)', border: '1px solid rgba(245,166,35,.3)', color: 'var(--accent)', cursor: 'pointer', fontSize: 13 }}>Editar</button>
                      <button onClick={() => remover(n.id)} style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(229,57,53,.1)', border: '1px solid rgba(229,57,53,.3)', color: 'var(--danger)', cursor: 'pointer', fontSize: 13 }}>🗑</button>
                    </div>
                  </td>
                </tr>
                {expandido === n.id && (
                  <NotificacaoLogInline endpoint={`/saas/notificacoes/${n.id}/log`} mostrarOficina colSpan={6} />
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>
      )}
    </div>
  )
}
```

(o `)}` antes de `</div></div>` fecha o `{aba === 'manuais' && (` aberto no
Step 3).

- [ ] **Step 4: Importar `Fragment`**

Trocar a primeira linha do arquivo:

```tsx
import { useState, useEffect, useCallback } from 'react'
```

por:

```tsx
import { useState, useEffect, useCallback, Fragment } from 'react'
```

- [ ] **Step 5: Checar tipos e build**

Run: `cd frontend && npx tsc --noEmit && npm run build`
Expected: sem erros referenciando `saas-admin/notificacoes/page.tsx`
(a Task 9 ainda não criou `NotificacaoCobrancaTable.tsx` — se o build
falhar só por causa desse import, é esperado; resolvido na Task 9).

- [ ] **Step 6: Commit**

```bash
git add "frontend/app/saas-admin/(protected)/notificacoes/page.tsx"
git commit -m "feat(saas): aba de notificacoes manuais ganha coluna de leituras e toggle de log"
```

---

### Task 9: Frontend — aba "Cobrança" (tabela agrupada + toggle de log)

**Files:**
- Create: `frontend/components/saas/NotificacaoCobrancaTable.tsx`

**Interfaces:**
- Consumes: `GET /saas/notificacoes-cobranca` e
  `GET /saas/notificacoes-cobranca/log` (Task 6), `NotificacaoLogInline`
  (Task 7). Importado por `notificacoes/page.tsx` (Task 8, já referenciado
  lá).

- [ ] **Step 1: Criar o componente**

```tsx
'use client'
import { useState, useEffect, useCallback, Fragment } from 'react'
import saasApi from '@/lib/saas-api'
import { formatarData, formatarMoeda } from '@/lib/formatters'
import { NotificacaoLogInline } from '@/components/saas/NotificacaoLogInline'

interface Grupo {
  oficina_id: string
  cobranca_id: string
  total_exibicoes: number
  ultima_exibicao_em: string
  oficina: { nome: string } | null
  cobranca: { valor: number; vencimento: string; status: string } | null
}

const FASE_LABEL: Record<string, string> = { PENDENTE: 'Disponível', VENCIDA: 'Vencida' }

export function NotificacaoCobrancaTable() {
  const [lista, setLista] = useState<Grupo[]>([])
  const [loading, setLoading] = useState(true)
  const [expandido, setExpandido] = useState<string | null>(null)

  const carregar = useCallback(() => {
    setLoading(true)
    saasApi.get<{ data: Grupo[] }>('/saas/notificacoes-cobranca')
      .then(r => setLista(r.data.data ?? []))
      .catch(() => setLista([]))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { carregar() }, [carregar])

  return (
    <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead>
          <tr>
            {['Oficina', 'Valor', 'Vencimento', 'Fase', 'Exibições', ''].map(c => (
              <th key={c} style={{ padding: '11px 16px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)' }}>{c}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {loading ? (
            <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Carregando...</td></tr>
          ) : lista.length === 0 ? (
            <tr><td colSpan={6} style={{ padding: 40, textAlign: 'center', color: 'var(--muted)' }}>Nenhuma exibição de alerta de cobrança registrada.</td></tr>
          ) : lista.map(g => {
            const chave = `${g.oficina_id}:${g.cobranca_id}`
            return (
              <Fragment key={chave}>
                <tr style={{ borderBottom: expandido === chave ? undefined : '1px solid var(--border)' }}>
                  <td style={{ padding: '12px 16px', fontWeight: 600 }}>{g.oficina?.nome ?? '—'}</td>
                  <td style={{ padding: '12px 16px', fontFamily: 'monospace' }}>{g.cobranca ? formatarMoeda(Number(g.cobranca.valor)) : '—'}</td>
                  <td style={{ padding: '12px 16px', fontSize: 13, color: 'var(--muted)' }}>{g.cobranca ? formatarData(g.cobranca.vencimento) : '—'}</td>
                  <td style={{ padding: '12px 16px' }}>
                    <span className={`pill ${g.cobranca?.status === 'VENCIDA' ? 'pill-danger' : 'pill-accent'}`}>
                      {FASE_LABEL[g.cobranca?.status ?? ''] ?? g.cobranca?.status ?? '—'}
                    </span>
                  </td>
                  <td style={{ padding: '12px 16px' }}>{g.total_exibicoes}</td>
                  <td style={{ padding: '12px 16px' }}>
                    <button onClick={() => setExpandido(expandido === chave ? null : chave)}
                      style={{ padding: '5px 12px', borderRadius: 6, background: 'rgba(30,136,229,.1)', border: '1px solid rgba(30,136,229,.3)', color: 'var(--info)', cursor: 'pointer', fontSize: 13 }}>
                      {expandido === chave ? '▲ Ocultar log' : '▼ Ver log'}
                    </button>
                  </td>
                </tr>
                {expandido === chave && (
                  <NotificacaoLogInline
                    endpoint={`/saas/notificacoes-cobranca/log?oficina_id=${g.oficina_id}&cobranca_id=${g.cobranca_id}`}
                    mostrarOficina={false}
                    colSpan={6}
                  />
                )}
              </Fragment>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
```

- [ ] **Step 2: Checar tipos e build (fecha o que ficou pendente na Task 8)**

Run: `cd frontend && npx tsc --noEmit && npm run build`
Expected: sem erros em nenhum arquivo — o import de
`NotificacaoCobrancaTable` em `notificacoes/page.tsx` (Task 8) agora
resolve.

- [ ] **Step 3: Commit**

```bash
git add frontend/components/saas/NotificacaoCobrancaTable.tsx
git commit -m "feat(saas): aba de log do alerta de cobranca agrupado por oficina/fatura"
```

---

## Fora de escopo (não implementar neste plano)

- Purga/retenção automática de linhas antigas do log.
- Log da tela `/bloqueado`.
- Throttle por usuário individual (fica por oficina, como já era).
