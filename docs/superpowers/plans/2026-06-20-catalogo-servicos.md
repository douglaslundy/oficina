# Catálogo de Serviços — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar cadastro de serviços reutilizáveis e integrá-los à OS com select + fallback manual.

**Architecture:** Backend Laravel (Model + Controller + rotas) seguindo o padrão do módulo `Produto`. Frontend Next.js com página CRUD própria e integração no OSForm em dois pontos: `NewItemInline` (edição de OS) e `useFieldArray` (nova OS).

**Tech Stack:** Laravel 11 / PHP 8.3 / PHPUnit, Next.js 14 / TypeScript / React Hook Form

---

## Mapa de Arquivos

| Ação | Arquivo |
|------|---------|
| CREATE | `backend/database/migrations/2026_06_20_000002_create_servicos_table.php` |
| CREATE | `backend/app/Models/Servico.php` |
| CREATE | `backend/tests/Feature/ServicoTest.php` |
| CREATE | `backend/app/Http/Controllers/ServicoController.php` |
| MODIFY | `backend/routes/api.php` |
| CREATE | `frontend/app/(dashboard)/servicos/page.tsx` |
| MODIFY | `frontend/components/layout/Sidebar.tsx` |
| MODIFY | `frontend/components/forms/OSForm.tsx` |

---

## Task 1: Migration + Model

**Files:**
- Create: `backend/database/migrations/2026_06_20_000002_create_servicos_table.php`
- Create: `backend/app/Models/Servico.php`

- [ ] **Step 1.1 — Criar migration**

```php
<?php
// backend/database/migrations/2026_06_20_000002_create_servicos_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('servicos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('oficina_id')->nullable()->index();
            $table->string('nome', 120);
            $table->decimal('valor_padrao', 10, 2)->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicos');
    }
};
```

- [ ] **Step 1.2 — Criar Model**

```php
<?php
// backend/app/Models/Servico.php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Servico extends Model
{
    use HasTenantScope;

    protected $table = 'servicos';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['nome', 'valor_padrao', 'ativo', 'oficina_id'];

    protected $casts = [
        'ativo'        => 'boolean',
        'valor_padrao' => 'float',
        'criado_em'    => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
```

- [ ] **Step 1.3 — Rodar migration para verificar**

```bash
cd backend && php artisan migrate
```

Esperado: `Migrating: 2026_06_20_000002_create_servicos_table` → `Migrated`

- [ ] **Step 1.4 — Commit**

```bash
git add backend/database/migrations/2026_06_20_000002_create_servicos_table.php \
        backend/app/Models/Servico.php
git commit -m "feat: migration e model Servico"
```

---

## Task 2: Testes (escrita antes do controller)

**Files:**
- Create: `backend/tests/Feature/ServicoTest.php`

- [ ] **Step 2.1 — Escrever ServicoTest**

```php
<?php
// backend/tests/Feature/ServicoTest.php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Servico;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ServicoTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome'       => 'Admin',
            'email'      => 'admin@test.com',
            'cpf'        => '52998224725',
            'role'       => 'ADMIN',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function loginAtendente(): string
    {
        $user = Usuario::create([
            'nome'       => 'Atendente',
            'email'      => 'atend@test.com',
            'cpf'        => '11144477735',
            'role'       => 'ATENDENTE',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('atend123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function criarServico(array $overrides = []): Servico
    {
        return Servico::create(array_merge([
            'nome'         => 'Troca de Óleo',
            'valor_padrao' => 80.00,
            'ativo'        => true,
        ], $overrides));
    }

    public function test_listar_servicos(): void
    {
        $token = $this->loginAdmin();
        $this->criarServico();
        $this->criarServico(['nome' => 'Alinhamento', 'valor_padrao' => 120.00]);

        $response = $this->withToken($token)->getJson('/api/servicos');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data', 'meta' => ['total']]);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_listar_apenas_ativos(): void
    {
        $token = $this->loginAdmin();
        $this->criarServico();
        $this->criarServico(['nome' => 'Inativo', 'ativo' => false]);

        $response = $this->withToken($token)->getJson('/api/servicos?ativo=1');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_criar_servico(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/servicos', [
            'nome'         => 'Revisão Completa',
            'valor_padrao' => 350.00,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.nome', 'Revisão Completa')
                 ->assertJsonPath('data.valor_padrao', 350.0);

        $this->assertDatabaseHas('servicos', ['nome' => 'Revisão Completa']);
    }

    public function test_atendente_pode_criar_servico(): void
    {
        $token = $this->loginAtendente();

        $response = $this->withToken($token)->postJson('/api/servicos', [
            'nome'         => 'Balanceamento',
            'valor_padrao' => 60.00,
        ]);

        $response->assertStatus(201);
    }

    public function test_criar_servico_sem_nome_falha(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->postJson('/api/servicos', [
            'valor_padrao' => 50.00,
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['nome']);
    }

    public function test_editar_servico(): void
    {
        $token   = $this->loginAdmin();
        $servico = $this->criarServico();

        $response = $this->withToken($token)->putJson("/api/servicos/{$servico->id}", [
            'nome'         => 'Troca de Óleo Premium',
            'valor_padrao' => 120.00,
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.nome', 'Troca de Óleo Premium');

        $this->assertDatabaseHas('servicos', ['id' => $servico->id, 'nome' => 'Troca de Óleo Premium']);
    }

    public function test_desativar_servico(): void
    {
        $token   = $this->loginAdmin();
        $servico = $this->criarServico();

        $response = $this->withToken($token)->deleteJson("/api/servicos/{$servico->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('servicos', ['id' => $servico->id, 'ativo' => false]);
    }

    public function test_reativar_servico_via_put(): void
    {
        $token   = $this->loginAdmin();
        $servico = $this->criarServico(['ativo' => false]);

        $response = $this->withToken($token)->putJson("/api/servicos/{$servico->id}", [
            'ativo' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('servicos', ['id' => $servico->id, 'ativo' => true]);
    }

    public function test_mecanico_nao_pode_criar_servico(): void
    {
        $user = Usuario::create([
            'nome'       => 'Mec',
            'email'      => 'mec@test.com',
            'cpf'        => '33344455568',
            'role'       => 'MECANICO',
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('mec123'),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/servicos', [
            'nome'         => 'Qualquer',
            'valor_padrao' => 10.00,
        ]);

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2.2 — Rodar testes para confirmar que falham (controller não existe)**

```bash
cd backend && php artisan test --filter ServicoTest
```

Esperado: todos os testes falham com `Route [api/servicos] not defined` ou 404.

- [ ] **Step 2.3 — Commit do teste**

```bash
git add backend/tests/Feature/ServicoTest.php
git commit -m "test: ServicoTest (failing, controller pendente)"
```

---

## Task 3: ServicoController + Rotas

**Files:**
- Create: `backend/app/Http/Controllers/ServicoController.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 3.1 — Criar ServicoController**

```php
<?php
// backend/app/Http/Controllers/ServicoController.php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Servico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServicoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Servico::query();

        if ($request->has('ativo')) {
            $query->where('ativo', filter_var($request->ativo, FILTER_VALIDATE_BOOLEAN));
        }

        $all     = $query->orderBy('nome')->get();
        $perPage = (int) ($request->per_page ?? 20);
        $page    = (int) ($request->page ?? 1);
        $total   = $all->count();
        $items   = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $items->map(fn($s) => [
                'id'           => $s->id,
                'nome'         => $s->nome,
                'valor_padrao' => (float) $s->valor_padrao,
                'ativo'        => $s->ativo,
            ]),
            'meta' => ['total' => $total, 'per_page' => $perPage, 'current_page' => $page],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'         => ['required', 'string', 'max:120'],
            'valor_padrao' => ['required', 'numeric', 'min:0'],
        ]);

        $servico = Servico::create($validated);

        return response()->json([
            'data' => [
                'id'           => $servico->id,
                'nome'         => $servico->nome,
                'valor_padrao' => (float) $servico->valor_padrao,
                'ativo'        => $servico->ativo,
            ],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $servico = Servico::findOrFail($id);

        $validated = $request->validate([
            'nome'         => ['sometimes', 'string', 'max:120'],
            'valor_padrao' => ['sometimes', 'numeric', 'min:0'],
            'ativo'        => ['sometimes', 'boolean'],
        ]);

        $servico->update($validated);

        return response()->json([
            'data' => [
                'id'           => $servico->id,
                'nome'         => $servico->nome,
                'valor_padrao' => (float) $servico->valor_padrao,
                'ativo'        => $servico->ativo,
            ],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $servico = Servico::findOrFail($id);
        $servico->update(['ativo' => false]);
        return response()->json(['message' => 'Serviço desativado.']);
    }
}
```

- [ ] **Step 3.2 — Adicionar rotas em `api.php`**

Adicionar após o bloco de produtos (linha ~116), antes do bloco de OS:

```php
// ─── Serviços — leitura: todos; escrita: ADMIN, ATENDENTE; desativar: ADMIN ───
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('servicos', [ServicoController::class, 'index']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])->group(function () {
    Route::post('servicos',     [ServicoController::class, 'store']);
    Route::put('servicos/{id}', [ServicoController::class, 'update']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::delete('servicos/{id}', [ServicoController::class, 'destroy']);
});
```

Adicionar o `use` no topo do arquivo, junto com os outros imports:

```php
use App\Http\Controllers\ServicoController;
```

- [ ] **Step 3.3 — Rodar testes e confirmar que passam**

```bash
cd backend && php artisan test --filter ServicoTest
```

Esperado: `8 passed` (ou similar, todos verdes).

- [ ] **Step 3.4 — Commit**

```bash
git add backend/app/Http/Controllers/ServicoController.php \
        backend/routes/api.php
git commit -m "feat: ServicoController e rotas da API"
```

---

## Task 4: Página `/servicos` (frontend)

**Files:**
- Create: `frontend/app/(dashboard)/servicos/page.tsx`

- [ ] **Step 4.1 — Criar a página**

```tsx
// frontend/app/(dashboard)/servicos/page.tsx
'use client'
import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'
import { formatarMoeda } from '@/lib/formatters'

interface Servico {
  id: string
  nome: string
  valor_padrao: number
  ativo: boolean
}

interface ServicoForm {
  nome: string
  valor_padrao: string
}

const iStyle: React.CSSProperties = {
  width: '100%', padding: '9px 12px', borderRadius: 8,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 14, outline: 'none', boxSizing: 'border-box',
}

function ServicoModal({
  mode,
  initial,
  onClose,
  onSuccess,
}: {
  mode: 'create' | 'edit'
  initial?: Servico
  onClose: () => void
  onSuccess: () => void
}) {
  const [form, setForm] = useState<ServicoForm>({
    nome: initial?.nome ?? '',
    valor_padrao: initial ? String(initial.valor_padrao) : '',
  })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    if (!form.nome.trim()) { setError('Nome é obrigatório.'); return }
    if (form.valor_padrao === '' || isNaN(parseFloat(form.valor_padrao))) {
      setError('Informe um valor padrão válido.'); return
    }
    setSubmitting(true)
    try {
      const payload = { nome: form.nome.trim(), valor_padrao: parseFloat(form.valor_padrao) }
      if (mode === 'create') {
        await api.post('/servicos', payload)
      } else {
        await api.put(`/servicos/${initial!.id}`, payload)
      }
      onSuccess()
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message ?? 'Erro ao salvar.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div
      onClick={e => { if (e.target === e.currentTarget) onClose() }}
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.6)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}
    >
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 12, width: '100%', maxWidth: 400, padding: '28px 32px' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 24 }}>
          <h2 className="font-display" style={{ fontSize: 20, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
            {mode === 'create' ? 'Novo Serviço' : 'Editar Serviço'}
          </h2>
          <button onClick={onClose} style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 20, cursor: 'pointer' }}>✕</button>
        </div>

        {error && (
          <div style={{ background: 'rgba(229,57,53,.1)', border: '1px solid var(--danger)', borderRadius: 8, padding: '10px 14px', color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div>
            <label style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }}>
              Nome <span style={{ color: 'var(--danger)' }}>*</span>
            </label>
            <input
              style={iStyle}
              value={form.nome}
              onChange={e => setForm(p => ({ ...p, nome: e.target.value }))}
              placeholder="Ex: Troca de Óleo"
              disabled={submitting}
            />
          </div>

          <div>
            <label style={{ color: 'var(--muted)', fontSize: 13, display: 'block', marginBottom: 4 }}>
              Valor padrão (R$) <span style={{ color: 'var(--danger)' }}>*</span>
            </label>
            <input
              style={iStyle}
              type="number"
              min="0"
              step="0.01"
              value={form.valor_padrao}
              onChange={e => setForm(p => ({ ...p, valor_padrao: e.target.value }))}
              placeholder="0.00"
              disabled={submitting}
            />
          </div>

          <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 8, paddingTop: 14, borderTop: '1px solid var(--border)' }}>
            <button type="button" onClick={onClose} disabled={submitting}
              style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 8, padding: '9px 20px', fontSize: 14, cursor: 'pointer' }}>
              Cancelar
            </button>
            <button type="submit" disabled={submitting}
              className="font-display"
              style={{ background: submitting ? 'rgba(245,166,35,.4)' : 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, padding: '9px 24px', fontSize: 14, fontWeight: 700, cursor: submitting ? 'not-allowed' : 'pointer' }}>
              {submitting ? '⟳ Salvando...' : mode === 'create' ? 'Criar' : 'Salvar'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

export default function ServicosPage() {
  const [servicos, setServicos] = useState<Servico[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [modalMode, setModalMode] = useState<'create' | 'edit'>('create')
  const [editTarget, setEditTarget] = useState<Servico | undefined>()
  const [confirmDeactivate, setConfirmDeactivate] = useState<string | null>(null)
  const [deactivating, setDeactivating] = useState(false)

  const fetchServicos = useCallback(async () => {
    setLoading(true)
    try {
      const r = await api.get('/servicos?per_page=200')
      setServicos(r.data.data ?? [])
    } catch { /* silently fails */ }
    finally { setLoading(false) }
  }, [])

  useEffect(() => { fetchServicos() }, [fetchServicos])

  async function handleDeactivate(id: string) {
    setDeactivating(true)
    try {
      await api.delete(`/servicos/${id}`)
      toast('Serviço desativado.', 'success')
      setConfirmDeactivate(null)
      fetchServicos()
    } catch {
      toast('Erro ao desativar.', 'danger')
    } finally {
      setDeactivating(false)
    }
  }

  async function handleReactivate(id: string) {
    try {
      await api.put(`/servicos/${id}`, { ativo: true })
      toast('Serviço reativado.', 'success')
      fetchServicos()
    } catch {
      toast('Erro ao reativar.', 'danger')
    }
  }

  function handleModalSuccess() {
    setShowModal(false)
    toast(modalMode === 'create' ? 'Serviço criado!' : 'Serviço atualizado!', 'success')
    fetchServicos()
  }

  return (
    <div style={{ padding: '32px 32px 40px', maxWidth: 860, margin: '0 auto', color: 'var(--text)' }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 28 }}>
        <div>
          <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, color: 'var(--text)', margin: 0 }}>Serviços</h1>
          <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>Catálogo de serviços disponíveis para OS</p>
        </div>
        <button
          onClick={() => { setModalMode('create'); setEditTarget(undefined); setShowModal(true) }}
          className="font-display"
          style={{ background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 8, padding: '10px 22px', fontSize: 14, fontWeight: 700, cursor: 'pointer' }}
        >
          + Novo Serviço
        </button>
      </div>

      {/* Table */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr>
                {['Nome', 'Valor Padrão', 'Status', 'Ações'].map(h => (
                  <th key={h} style={{ padding: '11px 16px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.06em', borderBottom: '1px solid var(--border)', background: 'var(--surface)', whiteSpace: 'nowrap' }}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                Array.from({ length: 4 }).map((_, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                    {[180, 100, 60, 120].map((w, j) => (
                      <td key={j} style={{ padding: '13px 16px' }}>
                        <div style={{ height: 14, borderRadius: 4, background: 'var(--border)', width: w, animation: 'pulse 1.4s ease-in-out infinite' }} />
                      </td>
                    ))}
                  </tr>
                ))
              ) : servicos.length === 0 ? (
                <tr>
                  <td colSpan={4} style={{ padding: '48px 16px', textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
                    Nenhum serviço cadastrado. Clique em &quot;+ Novo Serviço&quot; para começar.
                  </td>
                </tr>
              ) : (
                servicos.map((s, idx) => (
                  <tr key={s.id}
                    style={{ borderBottom: idx === servicos.length - 1 ? 'none' : '1px solid var(--border)' }}
                    onMouseEnter={e => { (e.currentTarget as HTMLTableRowElement).style.background = 'rgba(255,255,255,.02)' }}
                    onMouseLeave={e => { (e.currentTarget as HTMLTableRowElement).style.background = '' }}
                  >
                    <td style={{ padding: '12px 16px', fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>{s.nome}</td>
                    <td style={{ padding: '12px 16px' }}>
                      <span className="font-mono" style={{ fontSize: 14, color: 'var(--text)' }}>{formatarMoeda(s.valor_padrao)}</span>
                    </td>
                    <td style={{ padding: '12px 16px' }}>
                      <span style={{ display: 'inline-block', padding: '3px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: 'uppercase', background: s.ativo ? 'rgba(67,160,71,.15)' : 'rgba(229,57,53,.15)', color: s.ativo ? 'var(--success)' : 'var(--danger)' }}>
                        {s.ativo ? 'Ativo' : 'Inativo'}
                      </span>
                    </td>
                    <td style={{ padding: '12px 16px', whiteSpace: 'nowrap' }}>
                      {confirmDeactivate === s.id ? (
                        <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                          <span style={{ fontSize: 12, color: 'var(--muted)' }}>Confirmar?</span>
                          <button onClick={() => handleDeactivate(s.id)} disabled={deactivating}
                            style={{ background: 'rgba(229,57,53,.15)', border: '1px solid var(--danger)', color: 'var(--danger)', borderRadius: 6, padding: '4px 10px', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
                            {deactivating ? '⟳' : 'Sim'}
                          </button>
                          <button onClick={() => setConfirmDeactivate(null)}
                            style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', fontSize: 12, cursor: 'pointer' }}>
                            Não
                          </button>
                        </div>
                      ) : (
                        <div style={{ display: 'flex', gap: 8 }}>
                          <button
                            onClick={() => { setModalMode('edit'); setEditTarget(s); setShowModal(true) }}
                            style={{ background: 'rgba(245,166,35,.1)', border: '1px solid rgba(245,166,35,.3)', color: 'var(--accent)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>
                            Editar
                          </button>
                          {s.ativo ? (
                            <button onClick={() => setConfirmDeactivate(s.id)}
                              style={{ background: 'rgba(229,57,53,.1)', border: '1px solid rgba(229,57,53,.3)', color: 'var(--danger)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>
                              Desativar
                            </button>
                          ) : (
                            <button onClick={() => handleReactivate(s.id)}
                              style={{ background: 'rgba(67,160,71,.1)', border: '1px solid rgba(67,160,71,.3)', color: 'var(--success)', borderRadius: 6, padding: '5px 14px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>
                              Reativar
                            </button>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {showModal && (
        <ServicoModal
          mode={modalMode}
          initial={editTarget}
          onClose={() => setShowModal(false)}
          onSuccess={handleModalSuccess}
        />
      )}

      <style>{`@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.45} }`}</style>
    </div>
  )
}
```

- [ ] **Step 4.2 — Commit**

```bash
git add frontend/app/(dashboard)/servicos/page.tsx
git commit -m "feat: pagina /servicos com CRUD e modal"
```

---

## Task 5: Sidebar

**Files:**
- Modify: `frontend/components/layout/Sidebar.tsx`

- [ ] **Step 5.1 — Adicionar entrada Serviços**

No array `NAV_ITEMS` (linha ~14 de `Sidebar.tsx`), inserir entre `/produtos` e `/os`:

```ts
const NAV_ITEMS: NavItem[] = [
  { href: '/',                 label: 'Dashboard',         icon: '📊' },
  { href: '/clientes',         label: 'Clientes',          icon: '👥' },
  { href: '/produtos',         label: 'Produtos',          icon: '📦' },
  { href: '/servicos',         label: 'Serviços',          icon: '🛠️' },   // ← NOVO
  { href: '/os',               label: 'Ordens de Serviço', icon: '🔧' },
  { href: '/agendamentos',     label: 'Agendamento',       icon: '📅' },
  { href: '/fiscal/emitir',    label: 'Emitir NF',         icon: '🧾' },
  { href: '/fiscal/historico', label: 'Histórico NF',      icon: '📋' },
  { href: '/relatorios',       label: 'Relatórios',        icon: '📈' },
  { href: '/usuarios',         label: 'Usuários',          icon: '👤' },
  { href: '/empresa',          label: 'Empresa',           icon: '🏢' },
  { href: '/auditoria',        label: 'Auditoria',         icon: '🔍' },
  { href: '/configuracoes',    label: 'Configurações',     icon: '⚙️' },
]
```

- [ ] **Step 5.2 — Commit**

```bash
git add frontend/components/layout/Sidebar.tsx
git commit -m "feat: adiciona Servicos na sidebar"
```

---

## Task 6: OSForm — Integração do catálogo de serviços

**Files:**
- Modify: `frontend/components/forms/OSForm.tsx`

Este task tem duas partes: **A) `NewItemInline`** (modo edição de OS existente) e **B) `useFieldArray`** (modo nova OS).

### Parte A — `NewItemInline`

- [ ] **Step 6.1 — Adicionar prop `servicos` em `NewItemInline` e estado local**

Localizar a assinatura da função `NewItemInline` (próximo da linha 597) e adicionar a prop `servicos`:

```tsx
function NewItemInline({ osId, produtos, servicos, onAdded }: {
  osId: string
  produtos: Array<{ id: string; nome: string; qty_atual: number; unidade?: string; preco_venda: number | null }>
  servicos: Array<{ id: string; nome: string; valor_padrao: number }>
  onAdded?: (data: Record<string, unknown>) => void
}) {
  const [tipo, setTipo] = useState<'SERVICO' | 'PECA'>('SERVICO')
  const [produtoId, setProdutoId] = useState('')
  const [servicoId, setServicoId] = useState('')        // ← NOVO
  const [isManual, setIsManual] = useState(false)       // ← NOVO
  const [descricao, setDescricao] = useState('')
  const [quantidade, setQuantidade] = useState(1)
  const [valorUnitario, setValorUnitario] = useState(0)
  const [loading, setLoading] = useState(false)
```

Quando `tipo` muda para `'PECA'`, resetar estado de serviço. Adicionar no `onChange` do select de tipo:

```tsx
<select value={tipo} onChange={e => {
  setTipo(e.target.value as 'SERVICO' | 'PECA')
  setServicoId('')
  setIsManual(false)
  setDescricao('')
  setValorUnitario(0)
}} style={SI}>
```

- [ ] **Step 6.2 — Substituir input de texto de serviço por select + fallback**

Localizar o bloco que renderiza o input de descrição para SERVICO em `NewItemInline` (a linha que tem `placeholder="Descrição do serviço"`):

```tsx
// SUBSTITUIR:
) : (
  <input value={descricao} onChange={e => setDescricao(e.target.value)}
    placeholder="Descrição do serviço" style={SI} />
)}

// POR:
) : isManual ? (
  <input
    value={descricao}
    onChange={e => setDescricao(e.target.value)}
    placeholder="Descrição do serviço"
    style={SI}
  />
) : (
  <select
    value={servicoId}
    onChange={e => {
      const val = e.target.value
      if (val === '__manual__') {
        setIsManual(true)
        setServicoId('')
        setDescricao('')
      } else {
        setServicoId(val)
        const s = servicos.find(x => x.id === val)
        if (s) {
          setDescricao(s.nome)
          setValorUnitario(s.valor_padrao)
        }
      }
    }}
    style={SI}
  >
    <option value="">Selecionar serviço...</option>
    {servicos.map(s => (
      <option key={s.id} value={s.id}>{s.nome}</option>
    ))}
    <option value="__manual__">✏️ Outro (digitar manualmente)</option>
  </select>
)}
```

- [ ] **Step 6.3 — Resetar `servicoId` e `isManual` após adicionar item**

No final de `handleAdd` em `NewItemInline`, após `setDescricao('')`:

```tsx
setServicoId('')
setIsManual(false)
```

### Parte B — `OSForm` (estado e busca de serviços)

- [ ] **Step 6.4 — Adicionar estado e busca de serviços no `OSForm`**

Localizar onde `produtos` é buscado em `OSForm` (o `useState` e `fetchProdutos`):

```tsx
// Adicionar estado após a linha: const [produtos, setServicos...]
const [servicos, setServicos] = useState<Array<{ id: string; nome: string; valor_padrao: number }>>([])
```

No `useEffect` que já chama `fetchProdutos()` (ou separado), adicionar:

```tsx
// Dentro do useEffect que inicializa dados, adicionar:
api.get('/servicos?ativo=1&per_page=200')
  .then(r => setServicos(r.data.data ?? []))
  .catch(() => {})
```

- [ ] **Step 6.5 — Passar `servicos` para `NewItemInline`**

Localizar a chamada de `NewItemInline` (próximo da linha 477):

```tsx
// SUBSTITUIR:
<NewItemInline osId={initialData.id} produtos={produtos} onAdded={() => { fetchProdutos(); onSuccess?.({}) }} />

// POR:
<NewItemInline osId={initialData.id} produtos={produtos} servicos={servicos} onAdded={() => { fetchProdutos(); onSuccess?.({}) }} />
```

### Parte C — `useFieldArray` (nova OS)

- [ ] **Step 6.6 — Adicionar estado de campos manuais**

Após a linha `const [servicos, setServicos] = useState(...)`, adicionar:

```tsx
const [manualServiceFields, setManualServiceFields] = useState<Set<number>>(new Set())
```

Adicionar a função `handleServicoSelect` dentro de `OSForm`, após os hooks de estado:

```tsx
function handleServicoSelect(idx: number, value: string) {
  if (value === '__manual__') {
    setManualServiceFields(prev => new Set(prev).add(idx))
    setValue(`itens.${idx}.descricao`, '')
  } else {
    const s = servicos.find(x => x.id === value)
    if (s) {
      setValue(`itens.${idx}.descricao`, s.nome)
      setValue(`itens.${idx}.valor_unitario`, s.valor_padrao)
    }
    setManualServiceFields(prev => {
      const next = new Set(prev)
      next.delete(idx)
      return next
    })
  }
}
```

- [ ] **Step 6.7 — Substituir input de texto de serviço nos fields do `useFieldArray`**

Localizar o bloco `{fields.map((field, idx) => {` no modo nova OS (linha ~509). O trecho que tem o input de serviço é:

```tsx
// SUBSTITUIR:
) : (
  <input {...register(`itens.${idx}.descricao`)} placeholder="Descrição do serviço" style={S} />
)}

// POR:
) : manualServiceFields.has(idx) ? (
  <input
    {...register(`itens.${idx}.descricao`)}
    placeholder="Descrição do serviço"
    style={S}
  />
) : (
  <select
    onChange={e => handleServicoSelect(idx, e.target.value)}
    defaultValue=""
    style={S}
  >
    <option value="">Selecionar serviço...</option>
    {servicos.map(s => (
      <option key={s.id} value={s.id}>{s.nome}</option>
    ))}
    <option value="__manual__">✏️ Outro (digitar manualmente)</option>
  </select>
)}
```

- [ ] **Step 6.8 — Resetar `manualServiceFields` ao remover um item**

Localizar o botão `×` que chama `remove(idx)` (linha ~538) e atualizar:

```tsx
<button
  type="button"
  onClick={() => {
    remove(idx)
    setManualServiceFields(prev => {
      const next = new Set<number>()
      prev.forEach(i => { if (i < idx) next.add(i); else if (i > idx) next.add(i - 1) })
      return next
    })
  }}
  style={{ padding: '0 12px', background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18 }}
>
  ×
</button>
```

- [ ] **Step 6.9 — Commit da integração OSForm**

```bash
git add frontend/components/forms/OSForm.tsx
git commit -m "feat: select de servicos cadastrados no OSForm com fallback manual"
```

---

## Task 7: TypeScript check, testes finais, push e deploy

- [ ] **Step 7.1 — Rodar todos os testes backend**

```bash
cd backend && php artisan test
```

Esperado: todos os testes passam (sem falhas).

- [ ] **Step 7.2 — Verificar TypeScript no frontend**

```bash
cd frontend && npx tsc --noEmit
```

Esperado: nenhum erro ou aviso de tipo.

- [ ] **Step 7.3 — Push**

```bash
git push origin main
```

- [ ] **Step 7.4 — Deploy: pull + build + restart no servidor**

```bash
# Na máquina de deploy (via SSH como lundy@192.168.0.115):
cd /home/lundy/mecanicapro
git pull origin main
docker compose -p mecanicapro -f docker-compose.prod.yml build backend frontend
docker compose -p mecanicapro -f docker-compose.prod.yml up -d backend frontend
# Rodar migration no backend:
docker compose -p mecanicapro -f docker-compose.prod.yml exec backend php artisan migrate --force
```

- [ ] **Step 7.5 — Verificar containers healthy**

```bash
docker compose -p mecanicapro -f docker-compose.prod.yml ps
```

Esperado: backend e frontend com status `(healthy)`.
