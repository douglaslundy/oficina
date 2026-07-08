# Histórico por Veículo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vincular OS a veículos de verdade e criar uma tela de consulta por placa que mostra, para um veículo específico, quem foram seus donos (com período) e todo o histórico de OS.

**Architecture:** Nova tabela `veiculo_proprietarios` guarda um registro por período de posse (aberto = dono atual). `VeiculoController` ganha `buscar`/`show`/`transferir`. `OrdemServicoController` passa a persistir e filtrar por `veiculo_id` (hoje coletado no frontend e descartado no backend). Frontend ganha `/veiculos` (busca por placa) e `/veiculos/[id]` (detalhe).

**Tech Stack:** Laravel 11 / PHP 8.3 (`declare(strict_types=1)`), PostgreSQL 16, Next.js 14 App Router / TypeScript, estilos inline com CSS vars (sem Tailwind nas páginas existentes — seguir o padrão já usado em `clientes/[id]/page.tsx`).

## Global Constraints

- **`declare(strict_types=1)`** em todo arquivo PHP novo ou modificado.
- **Sem testes de feature rodando localmente**: a máquina de dev não tem Postgres/Docker (só
  `pdo_mysql`/`pdo_pgsql` instalados, nenhum servidor rodando). `phpunit.xml` usa `DB_CONNECTION=pgsql`.
  Por isso, os passos "rodar teste" deste plano usam **`php -l`** (lint de sintaxe) como verificação
  local — é a evidência real disponível aqui. A execução de fato dos testes de Feature (red→green)
  só acontece depois do deploy (Task 11), que já roda `php artisan migrate --force` sozinho no start
  do container (`docker-entrypoint.sh`).
- **Frontend sem test runner configurado** (sem jest/vitest). Verificação local = `npx tsc --noEmit`
  dentro de `frontend/`.
- **Multi-tenant**: toda tabela nova usa `App\Tenancy\HasTenantScope` (coluna `oficina_id`,
  auto-preenchida via `TenancyContext` quando o header `X-Tenant` está presente). Rotas somente-leitura
  liberadas a todos os roles autenticados; rotas de escrita restritas a `role:ADMIN,ATENDENTE` — mesmo
  padrão já usado em `clientes`/`veiculos` em `routes/api.php`.
- **Design system**: cores via CSS vars (`--accent`, `--success`, `--danger`, `--info`, `--muted`),
  `StatusPill` para status, `font-mono` (JetBrains Mono) para placas e valores, datas em `dd/mm/aaaa`.
- **Placa não é chave de negócio única no banco** — normalizar (maiúsculas, sem hífen/espaço) em toda
  comparação/busca por placa.

---

### Task 1: Tabela `veiculo_proprietarios` + model

**Files:**
- Create: `backend/database/migrations/2026_07_07_100001_create_veiculo_proprietarios_table.php`
- Create: `backend/app/Models/VeiculoProprietario.php`

**Interfaces:**
- Produces: tabela `veiculo_proprietarios(id, veiculo_id, cliente_id, oficina_id, data_inicio, data_fim)`
  e model `App\Models\VeiculoProprietario` com relations `veiculo(): BelongsTo` e `cliente(): BelongsTo`,
  usado pelas Tasks 2, 5 e 6.

- [ ] **Step 1: Criar a migração**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('veiculo_proprietarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('veiculo_id');
            $table->uuid('cliente_id');
            $table->foreignUuid('oficina_id')->nullable()->constrained('oficinas')->nullOnDelete();
            $table->timestampTz('data_inicio')->useCurrent();
            $table->timestampTz('data_fim')->nullable();

            $table->foreign('veiculo_id')->references('id')->on('veiculos')->onDelete('cascade');
            $table->foreign('cliente_id')->references('id')->on('clientes')->onDelete('cascade');
            $table->index(['veiculo_id', 'data_fim']);
        });

        // Backfill: 1 período aberto (dono atual) por veículo já existente.
        $veiculos = DB::table('veiculos')->select('id', 'cliente_id', 'oficina_id', 'criado_em')->get();
        foreach ($veiculos as $veiculo) {
            DB::table('veiculo_proprietarios')->insert([
                'id'          => (string) Str::uuid(),
                'veiculo_id'  => $veiculo->id,
                'cliente_id'  => $veiculo->cliente_id,
                'oficina_id'  => $veiculo->oficina_id,
                'data_inicio' => $veiculo->criado_em,
                'data_fim'    => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('veiculo_proprietarios');
    }
};
```

Nota: `oficina_id` nullable segue o padrão da tabela `servicos`
(`2026_06_20_000002_create_servicos_table.php`), não o de `veiculos` (que é `NOT NULL`) — é a
convenção mais usada nas tabelas recentes do projeto.

- [ ] **Step 2: Lint da migração**

Run: `cd backend && php -l database/migrations/2026_07_07_100001_create_veiculo_proprietarios_table.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Criar o model**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VeiculoProprietario extends Model
{
    use HasTenantScope;

    protected $table = 'veiculo_proprietarios';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'veiculo_id', 'cliente_id', 'oficina_id', 'data_inicio', 'data_fim',
    ];

    protected $casts = [
        'data_inicio' => 'datetime',
        'data_fim'    => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function veiculo(): BelongsTo { return $this->belongsTo(Veiculo::class, 'veiculo_id'); }
    public function cliente(): BelongsTo { return $this->belongsTo(Cliente::class, 'cliente_id'); }
}
```

- [ ] **Step 4: Lint do model**

Run: `cd backend && php -l app/Models/VeiculoProprietario.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_07_07_100001_create_veiculo_proprietarios_table.php backend/app/Models/VeiculoProprietario.php
git commit -m "feat(veiculos): tabela e model de histórico de proprietários"
```

---

### Task 2: `VeiculoController::store` — placa duplicada + registrar propriedade

**Files:**
- Modify: `backend/app/Http/Controllers/VeiculoController.php:1-35` (imports e método `store`)
- Create: `backend/tests/Feature/VeiculoTest.php`

**Interfaces:**
- Consumes: `App\Models\VeiculoProprietario` (Task 1).
- Produces: toda criação de veículo (`POST /clientes/{clienteId}/veiculos`) passa a criar também um
  registro de propriedade aberto; bloqueia placa duplicada entre veículos ativos. Os helpers privados
  `criarOficina()`/`loginAdmin()`/`criarCliente()` em `VeiculoTest.php` são reaproveitados pelas
  Tasks 5 e 6 (mesmo arquivo).

- [ ] **Step 1: Escrever o teste (arquivo novo)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Oficina;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VeiculoTest extends TestCase
{
    use RefreshDatabase;

    private function criarOficina(): Oficina
    {
        return Oficina::create([
            'nome'   => 'Oficina Teste',
            'slug'   => 'oficina-teste',
            'status' => 'ATIVA',
        ]);
    }

    private function loginAdmin(string $oficinaId): string
    {
        $user = Usuario::create([
            'nome'       => 'Admin',
            'email'      => 'admin@test.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $oficinaId,
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function criarCliente(string $oficinaId, string $nome, string $cpf): Cliente
    {
        return Cliente::create([
            'nome'       => $nome,
            'cpf_cnpj'   => $cpf,
            'oficina_id' => $oficinaId,
        ]);
    }

    public function test_criar_veiculo_cria_registro_de_propriedade(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');

        $response = $this->withToken($token)
            ->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", [
                'modelo' => 'Honda Civic',
                'ano'    => 2020,
                'placa'  => 'ABC1234',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('veiculo_proprietarios', [
            'veiculo_id' => $response->json('id'),
            'cliente_id' => $cliente->id,
            'data_fim'   => null,
        ]);
    }

    public function test_rejeitar_placa_duplicada_em_veiculo_ativo(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente1 = $this->criarCliente($oficina->id, 'João Silva', '11111111111');
        $cliente2 = $this->criarCliente($oficina->id, 'Maria Souza', '22222222222');

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente1->id}/veiculos", [
                'modelo' => 'Honda Civic', 'placa' => 'ABC-1234',
            ])->assertStatus(201);

        // Mesma placa, case e hífen diferentes — deve ser bloqueado.
        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente2->id}/veiculos", [
                'modelo' => 'Toyota Corolla', 'placa' => 'abc1234',
            ]);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 2: Lint do teste**

Run: `cd backend && php -l tests/Feature/VeiculoTest.php`
Expected: `No syntax errors detected` (a execução real do teste acontece na Task 11, após deploy — ver
Global Constraints)

- [ ] **Step 3: Implementar — imports e `store`**

Em `backend/app/Http/Controllers/VeiculoController.php`, trocar:

```php
use App\Models\Veiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
```

por:

```php
use App\Models\Veiculo;
use App\Models\VeiculoProprietario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
```

E trocar o método `store` inteiro:

```php
    public function store(Request $request, string $clienteId): JsonResponse
    {
        $validated = $request->validate([
            'modelo' => ['required', 'string', 'max:80'],
            'ano'    => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'placa'  => ['nullable', 'string', 'max:10'],
            'chassi' => ['nullable', 'string', 'max:20'],
        ]);

        $veiculo = Veiculo::create(array_merge($validated, [
            'cliente_id' => $clienteId,
        ]));

        return response()->json($this->shape($veiculo->fresh()), 201);
    }
```

por:

```php
    public function store(Request $request, string $clienteId): JsonResponse
    {
        $validated = $request->validate([
            'modelo' => ['required', 'string', 'max:80'],
            'ano'    => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'placa'  => ['nullable', 'string', 'max:10'],
            'chassi' => ['nullable', 'string', 'max:20'],
        ]);

        if (!empty($validated['placa'])) {
            $normalizada = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $validated['placa']));
            $duplicado = Veiculo::where('ativo', true)
                ->whereRaw("REPLACE(REPLACE(UPPER(placa), '-', ''), ' ', '') = ?", [$normalizada])
                ->exists();

            if ($duplicado) {
                return response()->json([
                    'message' => 'Já existe um veículo cadastrado com esta placa. Use a opção Transferir no veículo existente para trocar o proprietário.',
                ], 422);
            }
        }

        $veiculo = DB::transaction(function () use ($validated, $clienteId) {
            $veiculo = Veiculo::create(array_merge($validated, [
                'cliente_id' => $clienteId,
            ]));

            VeiculoProprietario::create([
                'veiculo_id'  => $veiculo->id,
                'cliente_id'  => $clienteId,
                'data_inicio' => now(),
                'data_fim'    => null,
            ]);

            return $veiculo;
        });

        return response()->json($this->shape($veiculo->fresh()), 201);
    }
```

- [ ] **Step 4: Lint do controller**

Run: `cd backend && php -l app/Http/Controllers/VeiculoController.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/VeiculoController.php backend/tests/Feature/VeiculoTest.php
git commit -m "feat(veiculos): bloquear placa duplicada e registrar dono na criação"
```

---

### Task 3: `VeiculoController::buscar` — autocomplete por placa

**Files:**
- Modify: `backend/app/Http/Controllers/VeiculoController.php` (novo método `buscar`)
- Modify: `backend/routes/api.php:176-180`
- Modify: `backend/tests/Feature/VeiculoTest.php` (novos testes)

**Interfaces:**
- Produces: `GET /api/veiculos/busca?placa=...` → `200` com array
  `[{id, placa, modelo, ano, ativo, cliente_id, cliente_nome}]` (máx. 10, mais recentes primeiro).
  Usado pela Task 8 (frontend).

- [ ] **Step 1: Adicionar os testes ao final da classe `VeiculoTest`**

```php
    public function test_busca_por_placa_parcial_normaliza_case_e_hifen(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", [
                'modelo' => 'Honda Civic', 'placa' => 'ABC-1234',
            ])->assertStatus(201);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson('/api/veiculos/busca?placa=abc123');

        $response->assertStatus(200)->assertJsonCount(1);
        $this->assertSame('João Silva', $response->json('0.cliente_nome'));
    }

    public function test_busca_sem_correspondencia_retorna_vazio(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson('/api/veiculos/busca?placa=zzz9999');

        $response->assertStatus(200)->assertJsonCount(0);
    }
```

- [ ] **Step 2: Lint do teste**

Run: `cd backend && php -l tests/Feature/VeiculoTest.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Implementar `buscar` em `VeiculoController.php`** (adicionar como novo método público,
  antes de `private function shape`)

```php
    public function buscar(Request $request): JsonResponse
    {
        $placa = (string) $request->query('placa', '');
        $normalizada = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placa));

        if ($normalizada === '') {
            return response()->json([]);
        }

        $veiculos = Veiculo::with('cliente')
            ->whereRaw("REPLACE(REPLACE(UPPER(placa), '-', ''), ' ', '') LIKE ?", ["%{$normalizada}%"])
            ->orderBy('criado_em', 'desc')
            ->limit(10)
            ->get();

        return response()->json($veiculos->map(fn($v) => [
            'id'           => $v->id,
            'placa'        => $v->placa,
            'modelo'       => $v->modelo,
            'ano'          => $v->ano,
            'ativo'        => $v->ativo,
            'cliente_id'   => $v->cliente_id,
            'cliente_nome' => $v->cliente?->nome,
        ]));
    }
```

- [ ] **Step 4: Registrar a rota**

Em `backend/routes/api.php`, dentro do bloco `Route::middleware(['tenant', 'auth:sanctum'])` que já
contém `clientes/{clienteId}/veiculos` (linhas 176-180), trocar:

```php
    Route::get('clientes/{clienteId}/veiculos', [VeiculoController::class, 'index']);
});
```

por:

```php
    Route::get('clientes/{clienteId}/veiculos', [VeiculoController::class, 'index']);
    Route::get('veiculos/busca', [VeiculoController::class, 'buscar']);
});
```

- [ ] **Step 5: Lint**

Run: `cd backend && php -l app/Http/Controllers/VeiculoController.php routes/api.php`
Expected: `No syntax errors detected` para os dois arquivos

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/VeiculoController.php backend/routes/api.php backend/tests/Feature/VeiculoTest.php
git commit -m "feat(veiculos): endpoint de busca por placa (autocomplete)"
```

---

### Task 4: Persistir e filtrar OS por `veiculo_id`

**Files:**
- Modify: `backend/app/Models/OrdemServico.php:25-31`
- Modify: `backend/app/Http/Controllers/OrdemServicoController.php:1-75` (imports, `store`, `index`)
- Modify: `backend/app/Http/Resources/OrdemServicoResource.php:13-30`
- Create: `backend/tests/Feature/OrdemServicoVeiculoTest.php`

**Interfaces:**
- Consumes: `App\Models\Veiculo` (já existe).
- Produces: `OrdemServico.veiculo_id` persistido em `POST /api/os`; `GET /api/os?veiculo_id=...` filtra;
  `OrdemServicoResource` expõe `veiculo_id`. Usado pela Task 5 (`VeiculoController::show`).
- **Detalhe importante**: o frontend (`OSForm.tsx`) usa um id sintético `__proprio_<clienteId>` (~46
  caracteres) quando o veículo do cliente vem do campo legado (`clientes.veiculo_modelo`), sem
  registro real em `veiculos`. Esse valor **não** pode quebrar a validação — deve ser silenciosamente
  tratado como "sem veículo vinculado" (mesmo comportamento de hoje).

- [ ] **Step 1: Escrever o teste (arquivo novo)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Oficina;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrdemServicoVeiculoTest extends TestCase
{
    use RefreshDatabase;

    private function setupEntities(): array
    {
        $oficina = Oficina::create(['nome' => 'Oficina Teste', 'slug' => 'oficina-teste', 'status' => 'ATIVA']);
        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => 'a@t.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('pass'),
            'oficina_id' => $oficina->id,
        ]);
        $cliente = Cliente::create(['nome' => 'Cliente Teste', 'cpf_cnpj' => '87748248800', 'oficina_id' => $oficina->id]);
        $token = $admin->createToken('t')->plainTextToken;
        return [$token, $oficina, $cliente];
    }

    private function withTenant(string $token, Oficina $oficina)
    {
        return $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug]);
    }

    public function test_criar_os_persiste_veiculo_id_valido(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $veiculoId = $this->withTenant($token, $oficina)
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');

        $response = $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id,
            'veiculo_id' => $veiculoId,
            'status'     => 'ABERTA',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.veiculo_id', $veiculoId);
        $this->assertDatabaseHas('ordens_servico', ['cliente_id' => $cliente->id, 'veiculo_id' => $veiculoId]);
    }

    public function test_criar_os_com_id_sintetico_de_veiculo_legado_grava_null(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $response = $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id,
            'veiculo_id' => "__proprio_{$cliente->id}",
            'status'     => 'ABERTA',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.veiculo_id', null);
    }

    public function test_listar_os_filtra_por_veiculo_id(): void
    {
        [$token, $oficina, $cliente] = $this->setupEntities();

        $veiculoId = $this->withTenant($token, $oficina)
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');
        $outroVeiculoId = $this->withTenant($token, $oficina)
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Fiat Uno', 'placa' => 'XYZ9999'])
            ->json('id');

        $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id, 'veiculo_id' => $veiculoId, 'status' => 'ABERTA',
        ])->assertStatus(201);
        $this->withTenant($token, $oficina)->postJson('/api/os', [
            'cliente_id' => $cliente->id, 'veiculo_id' => $outroVeiculoId, 'status' => 'ABERTA',
        ])->assertStatus(201);

        $response = $this->withTenant($token, $oficina)->getJson("/api/os?veiculo_id={$veiculoId}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }
}
```

- [ ] **Step 2: Lint do teste**

Run: `cd backend && php -l tests/Feature/OrdemServicoVeiculoTest.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Adicionar `veiculo_id` ao `$fillable` de `OrdemServico`**

Em `backend/app/Models/OrdemServico.php`, trocar:

```php
    protected $fillable = [
        'cliente_id', 'mecanico_id', 'veiculo_descricao', 'veiculo_placa',
        'problema_relatado', 'status', 'forma_pagamento', 'prazo_entrega',
        'valor_total', 'valor_pago', 'numero', 'oficina_id',
        'venda_a_prazo', 'prazo_pagamento_dias', 'data_vencimento_pagamento',
        'tipo',
    ];
```

por:

```php
    protected $fillable = [
        'cliente_id', 'mecanico_id', 'veiculo_id', 'veiculo_descricao', 'veiculo_placa',
        'problema_relatado', 'status', 'forma_pagamento', 'prazo_entrega',
        'valor_total', 'valor_pago', 'numero', 'oficina_id',
        'venda_a_prazo', 'prazo_pagamento_dias', 'data_vencimento_pagamento',
        'tipo',
    ];
```

- [ ] **Step 4: Validar e sanitizar `veiculo_id` em `OrdemServicoController::store`**

No topo de `backend/app/Http/Controllers/OrdemServicoController.php`, trocar:

```php
use App\Http\Resources\OrdemServicoResource;
use App\Models\OrdemServico;
use App\Models\OsPagamento;
```

por:

```php
use App\Http\Resources\OrdemServicoResource;
use App\Models\OrdemServico;
use App\Models\OsPagamento;
use App\Models\Veiculo;
```

Na validação de `store` (linha ~87-88), trocar:

```php
            'veiculo_descricao'       => ['nullable', 'string', 'max:100'],
            'veiculo_placa'           => ['nullable', 'string', 'max:10'],
```

por:

```php
            'veiculo_id'              => ['nullable', 'string', 'max:60'],
            'veiculo_descricao'       => ['nullable', 'string', 'max:100'],
            'veiculo_placa'           => ['nullable', 'string', 'max:10'],
```

Logo após o bloco `if ($isVendaBalcao) { ... }` (linhas ~106-110) e antes do `try {` que abre a
transação (linha ~112), inserir:

```php
        // O frontend pode enviar um id sintético "__proprio_<clienteId>" quando o
        // veículo vem do campo legado do cliente (sem registro real em `veiculos`).
        // Nesse caso, ou em qualquer id que não exista mais, degrada para null —
        // mesmo comportamento de hoje (só texto livre em veiculo_descricao/placa).
        if (!empty($validated['veiculo_id']) && !Veiculo::where('id', $validated['veiculo_id'])->exists()) {
            $validated['veiculo_id'] = null;
        }
```

- [ ] **Step 5: Filtro por `veiculo_id` em `index`**

Em `OrdemServicoController::index`, logo após o bloco do filtro `mecanico_id` (linhas ~39-41), trocar:

```php
        if ($request->has('mecanico_id')) {
            $query->where('mecanico_id', $request->mecanico_id);
        }
        if ($request->has('numero')) {
```

por:

```php
        if ($request->has('mecanico_id')) {
            $query->where('mecanico_id', $request->mecanico_id);
        }
        if ($request->has('veiculo_id')) {
            $query->where('veiculo_id', $request->veiculo_id);
        }
        if ($request->has('numero')) {
```

- [ ] **Step 6: Expor `veiculo_id` no resource**

Em `backend/app/Http/Resources/OrdemServicoResource.php`, trocar:

```php
            'veiculo_descricao' => $this->veiculo_descricao,
            'veiculo_placa'    => $this->veiculo_placa,
```

por:

```php
            'veiculo_id'        => $this->veiculo_id,
            'veiculo_descricao' => $this->veiculo_descricao,
            'veiculo_placa'    => $this->veiculo_placa,
```

- [ ] **Step 7: Lint**

Run: `cd backend && php -l app/Models/OrdemServico.php app/Http/Controllers/OrdemServicoController.php app/Http/Resources/OrdemServicoResource.php`
Expected: `No syntax errors detected` para os três arquivos

- [ ] **Step 8: Commit**

```bash
git add backend/app/Models/OrdemServico.php backend/app/Http/Controllers/OrdemServicoController.php backend/app/Http/Resources/OrdemServicoResource.php backend/tests/Feature/OrdemServicoVeiculoTest.php
git commit -m "fix(os): persistir e filtrar ordens de serviço por veiculo_id"
```

---

### Task 5: `VeiculoController::show` — detalhe com dono, histórico e OS

**Files:**
- Modify: `backend/app/Http/Controllers/VeiculoController.php` (imports, novo método `show`)
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/VeiculoTest.php`

**Interfaces:**
- Consumes: `App\Models\VeiculoProprietario` (Task 1), `OrdemServico.veiculo_id` persistido (Task 4).
- Produces: `GET /api/veiculos/{id}` → `200` com
  `{id, modelo, ano, placa, chassi, ativo, proprietario_atual, historico_proprietarios, historico_os, resumo}`.
  Usado pela Task 9 (frontend).

- [ ] **Step 1: Adicionar o teste**

```php
    public function test_detalhe_do_veiculo_retorna_proprietario_historico_e_os(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');

        $veiculoResp = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", [
                'modelo' => 'Honda Civic', 'ano' => 2020, 'placa' => 'ABC-1234',
            ]);
        $veiculoId = $veiculoResp->json('id');

        \App\Models\OrdemServico::create([
            'cliente_id'  => $cliente->id,
            'veiculo_id'  => $veiculoId,
            'oficina_id'  => $oficina->id,
            'status'      => 'CONCLUIDA',
            'valor_total' => 150,
            'valor_pago'  => 150,
        ]);

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson("/api/veiculos/{$veiculoId}");

        $response->assertStatus(200)
            ->assertJsonPath('proprietario_atual.nome', 'João Silva')
            ->assertJsonPath('resumo.total_os', 1)
            ->assertJsonPath('resumo.valor_total_gasto', 150)
            ->assertJsonCount(1, 'historico_proprietarios')
            ->assertJsonCount(1, 'historico_os');
    }
```

- [ ] **Step 2: Lint do teste**

Run: `cd backend && php -l tests/Feature/VeiculoTest.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Implementar `show`**

Em `backend/app/Http/Controllers/VeiculoController.php`, trocar o import:

```php
use App\Models\Veiculo;
use App\Models\VeiculoProprietario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
```

por:

```php
use App\Models\OrdemServico;
use App\Models\Veiculo;
use App\Models\VeiculoProprietario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
```

E adicionar o método (antes de `private function shape`):

```php
    public function show(string $id): JsonResponse
    {
        $veiculo = Veiculo::findOrFail($id);

        $proprietarioAtual = VeiculoProprietario::with('cliente')
            ->where('veiculo_id', $veiculo->id)
            ->whereNull('data_fim')
            ->first();

        $clienteAtual = $proprietarioAtual?->cliente ?? $veiculo->cliente;

        $historicoProprietarios = VeiculoProprietario::with('cliente')
            ->where('veiculo_id', $veiculo->id)
            ->orderBy('data_inicio', 'desc')
            ->get()
            ->map(fn($p) => [
                'cliente_id'   => $p->cliente_id,
                'cliente_nome' => $p->cliente?->nome,
                'data_inicio'  => $p->data_inicio?->format('d/m/Y'),
                'data_fim'     => $p->data_fim?->format('d/m/Y'),
            ]);

        $historicoOs = OrdemServico::with('mecanico')
            ->where('veiculo_id', $veiculo->id)
            ->where('status', '!=', 'CANCELADA')
            ->orderBy('criado_em', 'desc')
            ->get()
            ->map(fn($os) => [
                'id'          => $os->id,
                'numero'      => $os->numero,
                'tipo'        => $os->tipo,
                'status'      => $os->status,
                'valor_total' => $os->valor_total,
                'valor_pago'  => $os->valor_pago,
                'mecanico'    => $os->mecanico?->nome,
                'criado_em'   => $os->criado_em?->format('d/m/Y'),
            ]);

        return response()->json([
            'id'     => $veiculo->id,
            'modelo' => $veiculo->modelo,
            'ano'    => $veiculo->ano,
            'placa'  => $veiculo->placa,
            'chassi' => $veiculo->chassi,
            'ativo'  => $veiculo->ativo,
            'proprietario_atual' => $clienteAtual ? [
                'id'       => $clienteAtual->id,
                'nome'     => $clienteAtual->nome,
                'telefone' => $clienteAtual->telefone,
            ] : null,
            'historico_proprietarios' => $historicoProprietarios,
            'historico_os'            => $historicoOs,
            'resumo' => [
                'total_os'          => $historicoOs->count(),
                'valor_total_gasto' => $historicoOs->sum('valor_pago'),
                'ultima_visita'     => $historicoOs->first()['criado_em'] ?? null,
            ],
        ]);
    }
```

- [ ] **Step 4: Registrar a rota**

Em `backend/routes/api.php`, trocar:

```php
    Route::get('clientes/{clienteId}/veiculos', [VeiculoController::class, 'index']);
    Route::get('veiculos/busca', [VeiculoController::class, 'buscar']);
});
```

por:

```php
    Route::get('clientes/{clienteId}/veiculos', [VeiculoController::class, 'index']);
    Route::get('veiculos/busca', [VeiculoController::class, 'buscar']);
    Route::get('veiculos/{id}', [VeiculoController::class, 'show']);
});
```

(a rota `veiculos/busca` precisa continuar registrada **antes** de `veiculos/{id}`, senão Laravel
casaria `/veiculos/busca` com o wildcard `{id}`)

- [ ] **Step 5: Lint**

Run: `cd backend && php -l app/Http/Controllers/VeiculoController.php routes/api.php`
Expected: `No syntax errors detected` para os dois arquivos

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/VeiculoController.php backend/routes/api.php backend/tests/Feature/VeiculoTest.php
git commit -m "feat(veiculos): endpoint de detalhe com dono, historico e OS"
```

---

### Task 6: `VeiculoController::transferir` — troca de proprietário

**Files:**
- Modify: `backend/app/Http/Controllers/VeiculoController.php` (novo método `transferir`)
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/VeiculoTest.php`

**Interfaces:**
- Produces: `POST /api/veiculos/{id}/transferir` (`role:ADMIN,ATENDENTE`), payload
  `{novo_cliente_id}`. Fecha o período de propriedade atual, atualiza `veiculos.cliente_id`, abre novo
  período. Usado pela Task 9 (frontend).

- [ ] **Step 1: Adicionar os testes**

```php
    public function test_transferir_veiculo_atualiza_proprietario_e_historico(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $clienteAntigo = $this->criarCliente($oficina->id, 'João Silva', '11111111111');
        $clienteNovo = $this->criarCliente($oficina->id, 'Maria Souza', '22222222222');

        $veiculoId = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$clienteAntigo->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/veiculos/{$veiculoId}/transferir", ['novo_cliente_id' => $clienteNovo->id]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('veiculos', ['id' => $veiculoId, 'cliente_id' => $clienteNovo->id]);
        $this->assertDatabaseHas('veiculo_proprietarios', [
            'veiculo_id' => $veiculoId, 'cliente_id' => $clienteNovo->id, 'data_fim' => null,
        ]);

        $antigo = \App\Models\VeiculoProprietario::where('veiculo_id', $veiculoId)
            ->where('cliente_id', $clienteAntigo->id)->first();
        $this->assertNotNull($antigo->data_fim);
    }

    public function test_transferir_para_o_mesmo_cliente_e_rejeitado(): void
    {
        $oficina = $this->criarOficina();
        $token = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');

        $veiculoId = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');

        $response = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/veiculos/{$veiculoId}/transferir", ['novo_cliente_id' => $cliente->id]);

        $response->assertStatus(422);
    }

    public function test_mecanico_nao_pode_transferir_veiculo(): void
    {
        $oficina = $this->criarOficina();
        $adminToken = $this->loginAdmin($oficina->id);
        $cliente = $this->criarCliente($oficina->id, 'João Silva', '11111111111');
        $outroCliente = $this->criarCliente($oficina->id, 'Maria Souza', '22222222222');

        $veiculoId = $this->withToken($adminToken)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/clientes/{$cliente->id}/veiculos", ['modelo' => 'Honda Civic', 'placa' => 'ABC1234'])
            ->json('id');

        $mecanico = \App\Models\Usuario::create([
            'nome' => 'Mecânico', 'email' => 'mec@test.com', 'cpf' => '33333333333',
            'role' => 'MECANICO', 'status' => 'ATIVO', 'senha_hash' => \Illuminate\Support\Facades\Hash::make('123'),
            'oficina_id' => $oficina->id,
        ]);
        $mecToken = $mecanico->createToken('t')->plainTextToken;

        $response = $this->withToken($mecToken)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson("/api/veiculos/{$veiculoId}/transferir", ['novo_cliente_id' => $outroCliente->id]);

        $response->assertStatus(403);
    }
```

- [ ] **Step 2: Lint do teste**

Run: `cd backend && php -l tests/Feature/VeiculoTest.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Implementar `transferir`**

Adicionar em `backend/app/Http/Controllers/VeiculoController.php` (antes de `private function shape`):

```php
    public function transferir(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'novo_cliente_id' => ['required', 'string', 'exists:clientes,id'],
        ]);

        $veiculo = Veiculo::findOrFail($id);

        if ($veiculo->cliente_id === $validated['novo_cliente_id']) {
            return response()->json(['message' => 'O veículo já pertence a este cliente.'], 422);
        }

        DB::transaction(function () use ($veiculo, $validated) {
            VeiculoProprietario::where('veiculo_id', $veiculo->id)
                ->whereNull('data_fim')
                ->update(['data_fim' => now()]);

            $veiculo->update(['cliente_id' => $validated['novo_cliente_id']]);

            VeiculoProprietario::create([
                'veiculo_id'  => $veiculo->id,
                'cliente_id'  => $validated['novo_cliente_id'],
                'data_inicio' => now(),
                'data_fim'    => null,
            ]);
        });

        return response()->json($this->shape($veiculo->fresh()));
    }
```

- [ ] **Step 4: Registrar a rota (grupo de escrita `role:ADMIN,ATENDENTE`)**

Em `backend/routes/api.php`, trocar:

```php
    Route::put('veiculos/{id}',         [VeiculoController::class, 'update']);
    Route::delete('veiculos/{id}',      [VeiculoController::class, 'destroy']);
});
```

por:

```php
    Route::put('veiculos/{id}',         [VeiculoController::class, 'update']);
    Route::delete('veiculos/{id}',      [VeiculoController::class, 'destroy']);
    Route::post('veiculos/{id}/transferir', [VeiculoController::class, 'transferir']);
});
```

- [ ] **Step 5: Lint**

Run: `cd backend && php -l app/Http/Controllers/VeiculoController.php routes/api.php`
Expected: `No syntax errors detected` para os dois arquivos

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/VeiculoController.php backend/routes/api.php backend/tests/Feature/VeiculoTest.php
git commit -m "feat(veiculos): transferencia de proprietario com historico"
```

---

### Task 7: Sidebar — item de navegação "Veículos"

**Files:**
- Modify: `frontend/components/layout/Sidebar.tsx:26-46`

**Interfaces:**
- Produces: link `/veiculos` visível a todos os roles (sem `gate`), consumido pela Task 8.

- [ ] **Step 1: Adicionar o item ao array `NAV_ITEMS`**

Em `frontend/components/layout/Sidebar.tsx`, trocar:

```tsx
  { href: '/',                 label: 'Dashboard',         icon: '📊' },
  { href: '/clientes',         label: 'Clientes',          icon: '👥' },
  { href: '/produtos',         label: 'Produtos',          icon: '📦' },
```

por:

```tsx
  { href: '/',                 label: 'Dashboard',         icon: '📊' },
  { href: '/clientes',         label: 'Clientes',          icon: '👥' },
  { href: '/veiculos',         label: 'Veículos',          icon: '🚗' },
  { href: '/produtos',         label: 'Produtos',          icon: '📦' },
```

- [ ] **Step 2: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros novos relacionados a `Sidebar.tsx`

- [ ] **Step 3: Commit**

```bash
git add frontend/components/layout/Sidebar.tsx
git commit -m "feat(veiculos): item de navegação Veículos na sidebar"
```

---

### Task 8: Página `/veiculos` — busca por placa

**Files:**
- Create: `frontend/app/(dashboard)/veiculos/page.tsx`

**Interfaces:**
- Consumes: `GET /api/veiculos/busca?placa=` (Task 3) → `VeiculoBusca[]`.
- Produces: rota `/veiculos`, navega para `/veiculos/[id]` (Task 9) ao clicar num resultado.

- [ ] **Step 1: Criar a página**

```tsx
'use client'
import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import { formatarPlaca } from '@/lib/formatters'
import api from '@/lib/api'

interface VeiculoBusca {
  id: string
  placa: string | null
  modelo: string
  ano: number | null
  ativo: boolean
  cliente_id: string
  cliente_nome: string | null
}

export default function VeiculosPage() {
  const router = useRouter()
  const [placa, setPlaca] = useState('')
  const [resultados, setResultados] = useState<VeiculoBusca[]>([])
  const [loading, setLoading] = useState(false)
  const [buscou, setBuscou] = useState(false)

  useEffect(() => {
    if (!placa.trim()) {
      setResultados([])
      setBuscou(false)
      return
    }
    const timeout = setTimeout(() => {
      setLoading(true)
      api.get('/veiculos/busca', { params: { placa } })
        .then(res => setResultados(res.data ?? []))
        .catch(() => setResultados([]))
        .finally(() => { setLoading(false); setBuscou(true) })
    }, 300)
    return () => clearTimeout(timeout)
  }, [placa])

  return (
    <div style={{ maxWidth: 720, margin: '0 auto' }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 4 }}>Veículos</h1>
      <p style={{ color: 'var(--muted)', fontSize: 14, marginBottom: 24 }}>Digite a placa para consultar o histórico completo de um veículo.</p>

      <input
        autoFocus
        placeholder="Digite a placa (ex: ABC-1234)"
        value={placa}
        onChange={e => setPlaca(e.target.value)}
        className="font-mono"
        style={{
          width: '100%', padding: '14px 16px', borderRadius: 10,
          background: 'var(--card)', border: '1px solid var(--border)',
          color: 'var(--text)', fontSize: 18, outline: 'none', boxSizing: 'border-box',
        }}
      />

      <div style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 8 }}>
        {loading && <p style={{ color: 'var(--muted)', fontSize: 14 }}>Buscando...</p>}

        {!loading && buscou && resultados.length === 0 && (
          <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhum veículo encontrado com essa placa.</p>
        )}

        {!loading && resultados.map(v => (
          <div
            key={v.id}
            onClick={() => router.push(`/veiculos/${v.id}`)}
            style={{
              display: 'flex', justifyContent: 'space-between', alignItems: 'center',
              padding: '14px 16px', borderRadius: 10,
              background: 'var(--card)', border: '1px solid var(--border)', cursor: 'pointer',
            }}
          >
            <div>
              <p style={{ margin: 0, fontSize: 15, color: 'var(--text)', fontWeight: 700 }}>
                {v.modelo}{v.ano ? ` ${v.ano}` : ''}
              </p>
              <p style={{ margin: '2px 0 0', fontSize: 13, color: 'var(--muted)' }}>
                {v.cliente_nome ?? 'Sem proprietário'}
                {!v.ativo && <span style={{ color: 'var(--danger)' }}> · Inativo</span>}
              </p>
            </div>
            {v.placa && (
              <span className="font-mono" style={{ color: 'var(--accent)', fontSize: 15, fontWeight: 700 }}>
                {formatarPlaca(v.placa)}
              </span>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
```

- [ ] **Step 2: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros novos relacionados a `app/(dashboard)/veiculos/page.tsx`

- [ ] **Step 3: Commit**

```bash
git add "frontend/app/(dashboard)/veiculos/page.tsx"
git commit -m "feat(veiculos): pagina de busca por placa"
```

---

### Task 9: Página `/veiculos/[id]` — detalhe, histórico e transferência

**Files:**
- Create: `frontend/app/(dashboard)/veiculos/[id]/page.tsx`

**Interfaces:**
- Consumes: `GET /api/veiculos/{id}` (Task 5), `POST /api/veiculos/{id}/transferir` (Task 6),
  `GET /api/clientes?search=` (já existe, `ClienteController::index`).
- Produces: rota `/veiculos/[id]`, alvo de navegação das Tasks 8 e 10.

- [ ] **Step 1: Criar a página**

```tsx
'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import Link from 'next/link'
import { StatCard } from '@/components/ui/StatCard'
import { StatusPill } from '@/components/ui/StatusPill'
import { DataTable, Column } from '@/components/ui/DataTable'
import { formatarMoeda, formatarPlaca } from '@/lib/formatters'
import { toast } from '@/hooks/useToast'
import api from '@/lib/api'

interface Proprietario { id: string; nome: string; telefone?: string | null }
interface HistoricoProprietario { cliente_id: string; cliente_nome: string | null; data_inicio: string | null; data_fim: string | null }
interface OsHistorico { id: string; numero: number; tipo: string; status: string; valor_total: number; valor_pago: number; mecanico: string | null; criado_em: string }
interface VeiculoDetalhe {
  id: string; modelo: string; ano: number | null; placa: string | null; chassi: string | null; ativo: boolean
  proprietario_atual: Proprietario | null
  historico_proprietarios: HistoricoProprietario[]
  historico_os: OsHistorico[]
  resumo: { total_os: number; valor_total_gasto: number; ultima_visita: string | null }
}
interface ClienteBusca { id: string; nome: string }

export default function VeiculoDetailPage() {
  const { id } = useParams<{ id: string }>()
  const router = useRouter()
  const [veiculo, setVeiculo] = useState<VeiculoDetalhe | null>(null)
  const [loading, setLoading] = useState(true)
  const [transferindo, setTransferindo] = useState(false)
  const [buscaCliente, setBuscaCliente] = useState('')
  const [clientesEncontrados, setClientesEncontrados] = useState<ClienteBusca[]>([])
  const [salvandoTransferencia, setSalvandoTransferencia] = useState(false)

  function carregar() {
    api.get(`/veiculos/${id}`)
      .then(res => setVeiculo(res.data))
      .catch(() => setVeiculo(null))
      .finally(() => setLoading(false))
  }

  useEffect(() => { carregar() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!transferindo || !buscaCliente.trim()) {
      setClientesEncontrados([])
      return
    }
    const timeout = setTimeout(() => {
      api.get('/clientes', { params: { search: buscaCliente, per_page: 10 } })
        .then(res => setClientesEncontrados(res.data.data ?? []))
        .catch(() => setClientesEncontrados([]))
    }, 300)
    return () => clearTimeout(timeout)
  }, [buscaCliente, transferindo])

  async function handleTransferir(novoClienteId: string) {
    setSalvandoTransferencia(true)
    try {
      await api.post(`/veiculos/${id}/transferir`, { novo_cliente_id: novoClienteId })
      toast('Veículo transferido com sucesso.', 'success')
      setTransferindo(false)
      setBuscaCliente('')
      setClientesEncontrados([])
      carregar()
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao transferir veículo.', 'danger')
    } finally {
      setSalvandoTransferencia(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Carregando...</p>
  if (!veiculo) return <p style={{ color: 'var(--danger)' }}>Veículo não encontrado.</p>

  const osColumns: Column<OsHistorico>[] = [
    { key: 'numero', label: '#', render: r => <span className="font-mono">{r.numero}</span> },
    { key: 'criado_em', label: 'Data' },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
    { key: 'mecanico', label: 'Mecânico', render: r => r.mecanico ?? '-' },
    { key: 'valor_total', label: 'Valor', render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
  ]

  return (
    <div style={{ maxWidth: 1100, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24, flexWrap: 'wrap' }}>
        <button onClick={() => router.back()} style={{ background: 'none', border: 'none', color: 'var(--muted)', cursor: 'pointer', fontSize: 14 }}>← Voltar</button>
        <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          {veiculo.modelo}{veiculo.ano ? ` ${veiculo.ano}` : ''}
        </h1>
        <StatusPill status={veiculo.ativo ? 'ATIVO' : 'INATIVO'} />
        {veiculo.placa && (
          <span className="font-mono" style={{ color: 'var(--accent)', fontSize: 18, fontWeight: 700 }}>
            {formatarPlaca(veiculo.placa)}
          </span>
        )}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 24 }}>
        <StatCard title="Total de OS" value={veiculo.resumo.total_os} icon="🔧" color="var(--info)" />
        <StatCard title="Valor Total Gasto" value={formatarMoeda(veiculo.resumo.valor_total_gasto)} icon="💰" color="var(--success)" />
        <StatCard title="Última Visita" value={veiculo.resumo.ultima_visita ?? '-'} icon="📅" color="var(--accent)" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20, marginBottom: 24 }}>
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
            <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Proprietário Atual</h3>
            <button
              onClick={() => setTransferindo(v => !v)}
              style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', cursor: 'pointer', fontSize: 12 }}
            >
              Transferir
            </button>
          </div>
          {veiculo.proprietario_atual ? (
            <>
              <Link href={`/clientes/${veiculo.proprietario_atual.id}`} style={{ color: 'var(--text)', fontSize: 15, fontWeight: 600, textDecoration: 'none' }}>
                {veiculo.proprietario_atual.nome}
              </Link>
              {veiculo.proprietario_atual.telefone && (
                <p style={{ color: 'var(--muted)', fontSize: 13, margin: '4px 0 0' }}>{veiculo.proprietario_atual.telefone}</p>
              )}
            </>
          ) : (
            <p style={{ color: 'var(--muted)', fontSize: 14 }}>Sem proprietário cadastrado.</p>
          )}

          {transferindo && (
            <div style={{ marginTop: 16, borderTop: '1px solid var(--border)', paddingTop: 16 }}>
              <input
                autoFocus
                placeholder="Buscar cliente por nome..."
                value={buscaCliente}
                onChange={e => setBuscaCliente(e.target.value)}
                style={{ width: '100%', padding: '9px 12px', borderRadius: 8, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box' }}
              />
              <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 4, maxHeight: 200, overflowY: 'auto' }}>
                {clientesEncontrados
                  .filter(c => c.id !== veiculo.proprietario_atual?.id)
                  .map(c => (
                    <div
                      key={c.id}
                      onClick={() => !salvandoTransferencia && handleTransferir(c.id)}
                      style={{ padding: '8px 10px', borderRadius: 6, cursor: 'pointer', fontSize: 14, color: 'var(--text)', background: 'var(--bg)' }}
                    >
                      {c.nome}
                    </div>
                  ))}
              </div>
            </div>
          )}
        </div>

        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24 }}>
          <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 12 }}>Histórico de Proprietários</h3>
          {veiculo.historico_proprietarios.length === 0 ? (
            <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhum histórico registrado.</p>
          ) : (
            veiculo.historico_proprietarios.map((p, i) => (
              <div key={i} style={{ padding: '8px 0', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between' }}>
                <span style={{ color: 'var(--text)', fontSize: 14 }}>{p.cliente_nome ?? '-'}</span>
                <span style={{ color: 'var(--muted)', fontSize: 12 }}>{p.data_inicio} {p.data_fim ? `→ ${p.data_fim}` : '(atual)'}</span>
              </div>
            ))
          )}
        </div>
      </div>

      <div>
        <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 12 }}>Histórico de OS</h3>
        <DataTable
          columns={osColumns}
          data={veiculo.historico_os}
          onRowClick={r => router.push(r.tipo === 'VENDA_BALCAO' ? `/pdv/${r.id}` : `/os/${r.id}`)}
          emptyMessage="Nenhuma OS encontrada para este veículo."
        />
      </div>
    </div>
  )
}
```

- [ ] **Step 2: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros novos relacionados a `app/(dashboard)/veiculos/[id]/page.tsx`

- [ ] **Step 3: Commit**

```bash
git add "frontend/app/(dashboard)/veiculos/[id]/page.tsx"
git commit -m "feat(veiculos): pagina de detalhe com historico e transferencia"
```

---

### Task 10: Link "Ver histórico completo" na tela do cliente

**Files:**
- Modify: `frontend/app/(dashboard)/clientes/[id]/page.tsx:1-8` (imports) e `:282-297` (lista de
  veículos)

**Interfaces:**
- Consumes: rota `/veiculos/[id]` (Task 9).

- [ ] **Step 1: Adicionar o import de `Link`**

Trocar:

```tsx
'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import { ClienteForm } from '@/components/forms/ClienteForm'
```

por:

```tsx
'use client'
import { useEffect, useState } from 'react'
import { useParams, useRouter } from 'next/navigation'
import Link from 'next/link'
import { ClienteForm } from '@/components/forms/ClienteForm'
```

- [ ] **Step 2: Adicionar o link na lista de veículos**

Trocar:

```tsx
          {veiculos.filter(v => v.ativo).map(v => (
            <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
              <div>
                <p style={{ margin: 0, fontSize: 14, color: 'var(--text)', fontWeight: 600 }}>
                  {v.modelo}{v.ano ? ` ${v.ano}` : ''}
                </p>
                {v.placa && <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)' }}>{v.placa}</p>}
              </div>
              <button
                onClick={() => handleRemoverVeiculo(v.id)}
                style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 13 }}
              >
                Remover
              </button>
            </div>
          ))}
```

por:

```tsx
          {veiculos.filter(v => v.ativo).map(v => (
            <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
              <div>
                <p style={{ margin: 0, fontSize: 14, color: 'var(--text)', fontWeight: 600 }}>
                  {v.modelo}{v.ano ? ` ${v.ano}` : ''}
                </p>
                {v.placa && <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)' }}>{v.placa}</p>}
                <Link href={`/veiculos/${v.id}`} style={{ fontSize: 12, color: 'var(--accent)', textDecoration: 'none' }}>
                  Ver histórico completo →
                </Link>
              </div>
              <button
                onClick={() => handleRemoverVeiculo(v.id)}
                style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 13 }}
              >
                Remover
              </button>
            </div>
          ))}
```

- [ ] **Step 3: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros novos relacionados a `clientes/[id]/page.tsx`

- [ ] **Step 4: Commit**

```bash
git add "frontend/app/(dashboard)/clientes/[id]/page.tsx"
git commit -m "feat(clientes): link para historico completo do veiculo"
```

---

### Task 11: Deploy e verificação em produção (CHECKPOINT MANUAL)

**Este task só pode rodar com autorização explícita do usuário antes de cada ação de escrita em
produção** (push para `main`, deploy na VPS, criação de dados de teste). Não executar de forma
autônoma — parar e perguntar antes do Step 1.

**Files:** nenhum (deploy + verificação via API)

**Interfaces:** nenhuma nova — valida de ponta a ponta tudo que as Tasks 1-10 produziram.

- [ ] **Step 1: Confirmar com o usuário e dar push**

Perguntar: "Posso dar push em `main` e rodar o deploy na VPS de produção (144.91.92.70)?" Só prosseguir
com autorização explícita.

```bash
git push origin main
```

- [ ] **Step 2: Deploy na VPS (roda em background, ~10min)**

```bash
SSH_ASKPASS=/tmp/akp.sh SSH_ASKPASS_REQUIRE=force ssh -o StrictHostKeyChecking=accept-new root@144.91.92.70 \
  'cd /opt/mecanicapro && git pull origin main && bash deploy-vps.sh' </dev/null
```

(criar `/tmp/akp.sh` com a senha conforme a receita SSH_ASKPASS documentada no projeto; remover o
arquivo ao final)

- [ ] **Step 3: Confirmar que o deploy aplicou a migração nova**

```bash
SSH_ASKPASS=/tmp/akp.sh SSH_ASKPASS_REQUIRE=force ssh -o StrictHostKeyChecking=accept-new root@144.91.92.70 \
  'docker exec mecanicapro-postgres-1 psql -U mecanicapro -d mecanicapro -c "\d veiculo_proprietarios"' </dev/null
```

Expected: a tabela existe, com as colunas `veiculo_id`, `cliente_id`, `data_inicio`, `data_fim`.

- [ ] **Step 4: Checagem segura via API (criar → verificar → apagar)**

Usar o tenant real `stuntmotos` (ver `reference-prod-api`). Login, criar um cliente de teste, criar um
veículo, checar busca/detalhe/transferência, depois apagar o cliente e o veículo de teste:

```bash
# login
TOKEN=$(curl -s -X POST https://stuntmotos.dlsistemas.com.br/api/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' -H 'X-Tenant: stuntmotos' \
  -d '{"email":"<admin>","senha":"<senha>"}' | jq -r .token)

# criar cliente de teste
CLIENTE_ID=$(curl -s -X POST https://stuntmotos.dlsistemas.com.br/api/clientes \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' -H 'X-Tenant: stuntmotos' \
  -d '{"nome":"__teste_historico_veiculo","cpf_cnpj":"11111111111"}' | jq -r .data.id)

# criar veiculo
VEICULO_ID=$(curl -s -X POST https://stuntmotos.dlsistemas.com.br/api/clientes/$CLIENTE_ID/veiculos \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' -H 'X-Tenant: stuntmotos' \
  -d '{"modelo":"Teste Plano","placa":"ZZZ9999"}' | jq -r .id)

# buscar por placa
curl -s "https://stuntmotos.dlsistemas.com.br/api/veiculos/busca?placa=zzz9999" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'X-Tenant: stuntmotos'

# detalhe
curl -s "https://stuntmotos.dlsistemas.com.br/api/veiculos/$VEICULO_ID" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'X-Tenant: stuntmotos'

# limpeza
curl -s -X DELETE "https://stuntmotos.dlsistemas.com.br/api/veiculos/$VEICULO_ID" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'X-Tenant: stuntmotos'
curl -s -X DELETE "https://stuntmotos.dlsistemas.com.br/api/clientes/$CLIENTE_ID" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'X-Tenant: stuntmotos'
```

Expected: busca retorna o veículo de teste; detalhe retorna `proprietario_atual.nome` =
`__teste_historico_veiculo`; limpeza retorna sucesso (sem deixar dados de teste em produção).

- [ ] **Step 5: Verificação visual no navegador**

Abrir `https://stuntmotos.dlsistemas.com.br/veiculos`, buscar por uma placa real já cadastrada,
abrir o detalhe e conferir visualmente: pills, cores, tabela de histórico de OS, card de proprietário.

- [ ] **Step 6: Rodar a suíte de Feature no ambiente com banco (opcional, se houver acesso Docker)**

```bash
SSH_ASKPASS=/tmp/akp.sh SSH_ASKPASS_REQUIRE=force ssh -o StrictHostKeyChecking=accept-new root@144.91.92.70 \
  'cd /opt/mecanicapro && docker compose -p mecanicapro -f docker-compose.prod.yml exec backend php artisan test --filter=Veiculo' </dev/null
```

Expected: todos os testes de `VeiculoTest` e `OrdemServicoVeiculoTest` passam. **Não rodar isso sem
confirmar antes que aponta para um banco de teste/isolado — `artisan test` com `RefreshDatabase` dropa
o banco configurado.** Se não houver banco de teste dedicado na VPS, pular este step e confiar na
Step 4 (checagem via API).
