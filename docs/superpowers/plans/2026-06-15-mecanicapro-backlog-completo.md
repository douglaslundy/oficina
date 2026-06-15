# MecânicaPro — Backlog Completo: P0 → P3

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar todos os 20 itens do backlog (P0 a P3) do sistema MecânicaPro, fazendo commit após cada tarefa concluída.

**Architecture:** Backend Laravel 12 em `backend/`, Frontend Next.js 16 em `frontend/`. Middleware de tenant por header `X-Tenant`. Auth via Bearer token no localStorage. Todos os models de tenant usam `HasTenantScope`. Bootstrap do middleware em `backend/bootstrap/app.php`.

**Tech Stack:** Laravel 12, PHP 8.3, PostgreSQL, Next.js 16, TypeScript, Tailwind v4 (CSS-first), React Hook Form, TanStack Query via `api` axios instance em `frontend/lib/api.ts`.

---

## TASK 1 (P0): Scheduler diário para DIVIDA_VENCIDA

**Problema:** O status `DIVIDA_VENCIDA` de clientes só é recalculado quando uma OS é salva. Clientes com prazo vencido sem nova OS permanecem como `DEVEDOR`.

**Files:**
- Create: `backend/app/Console/Commands/RecalcularStatusClientes.php`
- Modify: `backend/routes/console.php`

- [ ] **Step 1: Criar o Artisan Command**

```php
<?php
// backend/app/Console/Commands/RecalcularStatusClientes.php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\ClienteStatusService;
use Illuminate\Console\Command;

class RecalcularStatusClientes extends Command
{
    protected $signature   = 'oficina:recalcular-status-clientes';
    protected $description = 'Recalcula status de todos os clientes com débito em aberto (DEVEDOR/DIVIDA_VENCIDA)';

    public function handle(ClienteStatusService $service): int
    {
        $clientes = Cliente::whereIn('status', ['DEVEDOR', 'DIVIDA_VENCIDA', 'OS_ABERTA'])->get();

        $this->info("Recalculando {$clientes->count()} clientes...");

        foreach ($clientes as $cliente) {
            $service->recalcular($cliente->id);
        }

        $this->info('Concluído.');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Registrar o scheduler diário em console.php**

```php
<?php
// backend/routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('oficina:recalcular-status-clientes')->dailyAt('02:00');
```

- [ ] **Step 3: Executar o command manualmente para validar**

```bash
cd backend && php artisan oficina:recalcular-status-clientes
```

Expected output:
```
Recalculando X clientes...
Concluído.
```

- [ ] **Step 4: Commit**

```bash
git add backend/app/Console/Commands/RecalcularStatusClientes.php backend/routes/console.php
git commit -m "feat: scheduler diário para recalcular status DIVIDA_VENCIDA dos clientes"
```

---

## TASK 2 (P0): RBAC real nas rotas do backend

**Problema:** `spatie/laravel-permission` está instalado mas nenhuma rota usa middleware de role. Qualquer usuário autenticado acessa tudo.

**Regras de acesso por role:**
- `ADMIN`: acesso total
- `MECANICO`: OS (read/write), agendamentos, dashboard, clientes (read), produtos (read)
- `ATENDENTE`: clientes, OS, agendamentos, produtos (read), dashboard
- `FINANCEIRO`: OS (read), notas-fiscais, dashboard, clientes (read)

**Files:**
- Create: `backend/app/Http/Middleware/CheckRole.php`
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Criar middleware CheckRole**

```php
<?php
// backend/app/Http/Middleware/CheckRole.php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = auth()->user();

        if (!$user || !in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: Registrar alias 'role' no bootstrap/app.php**

No bloco `->withMiddleware(function (Middleware $middleware): void {` adicionar:

```php
$middleware->alias([
    'tenant' => \App\Http\Middleware\InitializeTenancyByHeader::class,
    'role'   => \App\Http\Middleware\CheckRole::class,
]);
```

- [ ] **Step 3: Aplicar middleware de role nas rotas em api.php**

Substituir o grupo protegido `Route::middleware(['tenant', 'auth:sanctum'])->group(...)` existente por grupos separados com roles:

```php
// backend/routes/api.php — substituir o único grupo protegido por múltiplos grupos

$adminRoles    = 'ADMIN';
$allStaff      = 'ADMIN,MECANICO,ATENDENTE,FINANCEIRO';
$semMecanico   = 'ADMIN,ATENDENTE,FINANCEIRO';
$semFinanceiro = 'ADMIN,MECANICO,ATENDENTE';

// Dashboard — todos
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index']);
});

// Clientes — leitura para todos; escrita para ADMIN/ATENDENTE
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE,MECANICO,FINANCEIRO'])->group(function () {
    Route::get('clientes',              [ClienteController::class, 'index']);
    Route::get('clientes/{cliente}',    [ClienteController::class, 'show']);
    Route::get('clientes/{clienteId}/veiculos',  [VeiculoController::class, 'index']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])->group(function () {
    Route::post('clientes',             [ClienteController::class, 'store']);
    Route::put('clientes/{cliente}',    [ClienteController::class, 'update']);
    Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy']);
    Route::post('clientes/{clienteId}/veiculos', [VeiculoController::class, 'store']);
    Route::put('veiculos/{id}',         [VeiculoController::class, 'update']);
    Route::delete('veiculos/{id}',      [VeiculoController::class, 'destroy']);
});

// Produtos — leitura para todos; escrita para ADMIN/ATENDENTE
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('produtos',           [ProdutoController::class, 'index']);
    Route::get('produtos/{produto}', [ProdutoController::class, 'show']);
    Route::get('produtos/{produto}/estoque/historico', [EstoqueController::class, 'historico']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])->group(function () {
    Route::post('produtos',            [ProdutoController::class, 'store']);
    Route::put('produtos/{produto}',   [ProdutoController::class, 'update']);
    Route::delete('produtos/{produto}',[ProdutoController::class, 'destroy']);
    Route::post('produtos/{produto}/estoque/entrada', [EstoqueController::class, 'entrada']);
});

// OS — todos leem/criam/atualizam; só ADMIN pode deletar
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('os',          [OrdemServicoController::class, 'index']);
    Route::get('os/{id}',     [OrdemServicoController::class, 'show']);
    Route::get('os/{id}/pdf', [OrdemServicoController::class, 'pdf']);
    Route::get('os/{id}/recibo', [OrdemServicoController::class, 'recibo']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE,MECANICO'])->group(function () {
    Route::post('os',         [OrdemServicoController::class, 'store']);
    Route::put('os/{id}',     [OrdemServicoController::class, 'update']);
    Route::post('os/{osId}/itens',            [OrdemServicoController::class, 'addItem']);
    Route::put('os/{osId}/itens/{itemId}',    [OrdemServicoController::class, 'updateItem']);
    Route::delete('os/{osId}/itens/{itemId}', [OrdemServicoController::class, 'removeItem']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::delete('os/{id}', [OrdemServicoController::class, 'destroy']);
});

// Notas Fiscais — ADMIN e FINANCEIRO
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,FINANCEIRO'])->group(function () {
    Route::get('notas-fiscais',                   [NotaFiscalController::class, 'index']);
    Route::get('notas-fiscais/{id}',              [NotaFiscalController::class, 'show']);
    Route::get('notas-fiscais/{id}/pdf',          [NotaFiscalController::class, 'pdf']);
    Route::post('notas-fiscais',                  [NotaFiscalController::class, 'store']);
    Route::post('notas-fiscais/{id}/emitir',      [NotaFiscalController::class, 'emitir']);
    Route::post('notas-fiscais/{id}/cancelar',    [NotaFiscalController::class, 'cancelar']);
});

// Usuários — somente ADMIN
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::get('usuarios',       [UsuarioController::class, 'index']);
    Route::post('usuarios',      [UsuarioController::class, 'store']);
    Route::put('usuarios/{id}',  [UsuarioController::class, 'update']);
});

// Configurações e Empresa — somente ADMIN
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::post('configuracoes/certificado', [ConfiguracaoController::class, 'uploadCertificado']);
    Route::get('configuracoes',              [ConfiguracaoController::class, 'show']);
    Route::put('configuracoes',              [ConfiguracaoController::class, 'update']);
});

// Relatórios — ADMIN e FINANCEIRO
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,FINANCEIRO'])->group(function () {
    Route::get('relatorios/os',       [RelatorioController::class, 'os']);
    Route::get('relatorios/clientes', [RelatorioController::class, 'clientes']);
    Route::get('relatorios/estoque',  [RelatorioController::class, 'estoque']);
});

// Agendamentos — todos
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::apiResource('agendamentos', AgendamentoController::class);
    Route::post('agendamentos/{id}/confirmar', [AgendamentoController::class, 'confirmar']);
    Route::post('agendamentos/{id}/cancelar',  [AgendamentoController::class, 'cancelar']);
});
```

> **Nota:** O import `use App\Http\Controllers\RelatorioController;` será adicionado na Task 9.

- [ ] **Step 4: Também remover o bloco antigo do apiResource para não ter rotas duplicadas**

O arquivo api.php final não deve ter mais o bloco genérico `Route::middleware(['tenant', 'auth:sanctum'])->group(function () { Route::apiResource('clientes' ...` que englobava tudo. Esse bloco será completamente substituído.

- [ ] **Step 5: Testar manualmente que MECANICO recebe 403 ao acessar /api/usuarios**

```bash
# Criar um token de MECANICO e testar
cd backend && php artisan tinker
# $mec = Usuario::where('role', 'MECANICO')->first(); $mec->createToken('t')->plainTextToken
# curl -H "Authorization: Bearer <token>" http://localhost:8000/api/usuarios
```

Expected: `{"message":"Acesso negado."}` com status 403.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Middleware/CheckRole.php backend/bootstrap/app.php backend/routes/api.php
git commit -m "feat: RBAC real nas rotas — middleware CheckRole com roles por endpoint"
```

---

## TASK 3 (P0): Editar itens de OS (antes de concluir)

**Problema:** OS em status `ABERTA` ou `EM_ANDAMENTO` não permitem editar itens. A UI exibe os itens como read-only.

**Files:**
- Modify: `backend/app/Http/Controllers/OrdemServicoController.php` (add `addItem`, `updateItem`, `removeItem`)
- Modify: `frontend/components/forms/OSForm.tsx` (mostrar itens editáveis quando status != CONCLUIDA/CANCELADA)

- [ ] **Step 1: Adicionar métodos de item no OrdemServicoController**

No final da classe `OrdemServicoController`, adicionar:

```php
public function addItem(Request $request, string $osId): JsonResponse
{
    $os = OrdemServico::findOrFail($osId);

    if (in_array($os->status, ['CONCLUIDA', 'CANCELADA'], true)) {
        return response()->json(['message' => 'Não é possível editar itens de OS concluída ou cancelada.'], 422);
    }

    $validated = $request->validate([
        'tipo'           => ['required', 'in:SERVICO,PECA'],
        'produto_id'     => ['nullable', 'string', 'exists:produtos,id'],
        'descricao'      => ['required', 'string', 'max:200'],
        'quantidade'     => ['required', 'numeric', 'min:0.01'],
        'valor_unitario' => ['required', 'numeric', 'min:0'],
    ]);

    $item = $os->itens()->create($validated);

    // Recalcular valor total
    $total = $os->itens()->sum(\DB::raw('quantidade * valor_unitario'));
    $os->update(['valor_total' => $total]);

    return response()->json(['data' => $item], 201);
}

public function updateItem(Request $request, string $osId, string $itemId): JsonResponse
{
    $os   = OrdemServico::findOrFail($osId);
    $item = $os->itens()->findOrFail($itemId);

    if (in_array($os->status, ['CONCLUIDA', 'CANCELADA'], true)) {
        return response()->json(['message' => 'Não é possível editar itens de OS concluída ou cancelada.'], 422);
    }

    $validated = $request->validate([
        'descricao'      => ['sometimes', 'string', 'max:200'],
        'quantidade'     => ['sometimes', 'numeric', 'min:0.01'],
        'valor_unitario' => ['sometimes', 'numeric', 'min:0'],
    ]);

    $item->update($validated);

    $total = $os->itens()->sum(\DB::raw('quantidade * valor_unitario'));
    $os->update(['valor_total' => $total]);

    return response()->json(['data' => $item->fresh()]);
}

public function removeItem(Request $request, string $osId, string $itemId): JsonResponse
{
    $os   = OrdemServico::findOrFail($osId);
    $item = $os->itens()->findOrFail($itemId);

    if (in_array($os->status, ['CONCLUIDA', 'CANCELADA'], true)) {
        return response()->json(['message' => 'Não é possível remover itens de OS concluída ou cancelada.'], 422);
    }

    $item->delete();

    $total = $os->itens()->sum(\DB::raw('quantidade * valor_unitario'));
    $os->update(['valor_total' => $total]);

    return response()->json(['message' => 'Item removido.']);
}
```

Adicionar import no topo do controller (dentro do namespace, após os existentes):
```php
use Illuminate\Support\Facades\DB;
```

- [ ] **Step 2: Modificar OSForm.tsx — mostrar itens editáveis em modo de edição quando ABERTA/EM_ANDAMENTO**

No `OSForm.tsx`, o bloco que exibe itens em modo de edição precisa mostrar uma lista com botão de remoção e formulário de adição de novo item quando `os.status !== 'CONCLUIDA' && os.status !== 'CANCELADA'`.

Localizar a seção que exibe itens no modo `isEdit` (buscar por `veiculoDisplay` e a seção de itens abaixo). Substituir o bloco read-only de itens por:

```tsx
{/* Itens da OS */}
<div style={{ gridColumn: '1 / -1', marginTop: 8 }}>
  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
    <label style={L}>Itens da OS</label>
    {isEdit && !['CONCLUIDA', 'CANCELADA'].includes(watch('status')) && (
      <span style={{ fontSize: 12, color: 'var(--accent)' }}>editável</span>
    )}
  </div>

  {isEdit ? (
    // Modo edição: exibe itens salvos com opção de remover (se não finalizado)
    <div>
      {(initialData?.itens ?? []).map((item, idx) => (
        <div key={item.id ?? idx} style={{
          display: 'flex', gap: 8, alignItems: 'center',
          padding: '8px 12px', background: 'var(--bg)',
          borderRadius: 8, marginBottom: 6,
          border: '1px solid var(--border)',
        }}>
          <span style={{ flex: 1, fontSize: 13, color: 'var(--text)' }}>{item.descricao}</span>
          <span className="font-mono" style={{ fontSize: 13, color: 'var(--muted)', minWidth: 60, textAlign: 'right' }}>
            {item.quantidade}x {new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(item.valor_unitario)}
          </span>
          {!['CONCLUIDA', 'CANCELADA'].includes(watch('status')) && (
            <button
              type="button"
              onClick={() => handleRemoveItem(item.id!)}
              style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 16, padding: '0 4px' }}
            >×</button>
          )}
        </div>
      ))}

      {/* Form para adicionar novo item (só se editável) */}
      {!['CONCLUIDA', 'CANCELADA'].includes(watch('status')) && (
        <NewItemInline osId={initialData!.id!} produtos={produtos} onAdded={onSuccess} />
      )}
    </div>
  ) : (
    // Modo criação: campos dinâmicos existentes
    <div>
      {fields.map((field, idx) => (
        // ... (manter o render existente de campos dinâmicos para nova OS)
```

> **Nota:** O `NewItemInline` é um sub-componente inline definido no mesmo arquivo, abaixo do `OSForm`.

- [ ] **Step 3: Adicionar a função `handleRemoveItem` e o componente `NewItemInline` no OSForm.tsx**

Dentro do `OSForm`, adicionar após o `onSubmit`:

```tsx
async function handleRemoveItem(itemId: string) {
  if (!confirm('Remover este item?')) return
  try {
    await api.delete(`/os/${initialData!.id}/itens/${itemId}`)
    toast('Item removido.', 'success')
    onSuccess?.({})
  } catch {
    toast('Erro ao remover item.', 'danger')
  }
}
```

Após o export do `OSForm`, adicionar o componente inline:

```tsx
function NewItemInline({ osId, produtos, onAdded }: {
  osId: string
  produtos: Array<{ id: string; nome: string; preco_venda: number | null }>
  onAdded?: (data: Record<string, unknown>) => void
}) {
  const [tipo, setTipo] = useState<'SERVICO' | 'PECA'>('SERVICO')
  const [produtoId, setProdutoId] = useState('')
  const [descricao, setDescricao] = useState('')
  const [quantidade, setQuantidade] = useState(1)
  const [valorUnitario, setValorUnitario] = useState(0)
  const [loading, setLoading] = useState(false)

  function handleProdutoSelect(id: string) {
    setProdutoId(id)
    const p = produtos.find(x => x.id === id)
    if (p) {
      setDescricao(p.nome)
      setValorUnitario(p.preco_venda ?? 0)
    }
  }

  async function handleAdd() {
    if (!descricao || quantidade <= 0 || valorUnitario < 0) return
    setLoading(true)
    try {
      await api.post(`/os/${osId}/itens`, {
        tipo, produto_id: produtoId || null, descricao, quantidade, valor_unitario: valorUnitario,
      })
      toast('Item adicionado.', 'success')
      setDescricao(''); setProdutoId(''); setQuantidade(1); setValorUnitario(0)
      onAdded?.({})
    } catch {
      toast('Erro ao adicionar item.', 'danger')
    } finally {
      setLoading(false)
    }
  }

  const S2: React.CSSProperties = {
    padding: '7px 10px', borderRadius: 6, background: 'var(--bg)',
    border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13,
  }

  return (
    <div style={{ marginTop: 10, padding: 12, borderRadius: 8, border: '1px dashed var(--border)', background: 'var(--surface)' }}>
      <p style={{ color: 'var(--muted)', fontSize: 12, marginBottom: 8 }}>+ Novo item</p>
      <div style={{ display: 'grid', gridTemplateColumns: '100px 1fr', gap: 8, marginBottom: 8 }}>
        <select value={tipo} onChange={e => setTipo(e.target.value as 'SERVICO' | 'PECA')} style={S2}>
          <option value="SERVICO">Serviço</option>
          <option value="PECA">Peça</option>
        </select>
        {tipo === 'PECA' ? (
          <select value={produtoId} onChange={e => handleProdutoSelect(e.target.value)} style={S2}>
            <option value="">Selecionar peça...</option>
            {produtos.map(p => <option key={p.id} value={p.id}>{p.nome}</option>)}
          </select>
        ) : (
          <input value={descricao} onChange={e => setDescricao(e.target.value)}
            placeholder="Descrição do serviço" style={S2} />
        )}
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '80px 120px auto', gap: 8, alignItems: 'center' }}>
        <input type="number" value={quantidade} min={0.01} step={0.01}
          onChange={e => setQuantidade(Number(e.target.value))}
          placeholder="Qtd" style={S2} />
        <input type="number" value={valorUnitario} min={0} step={0.01}
          onChange={e => setValorUnitario(Number(e.target.value))}
          placeholder="Valor unit." style={S2} />
        <button type="button" onClick={handleAdd} disabled={loading}
          style={{ padding: '7px 16px', background: 'var(--accent)', color: '#000', borderRadius: 6, border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700 }}>
          {loading ? '...' : 'Adicionar'}
        </button>
      </div>
    </div>
  )
}
```

- [ ] **Step 4: Testar manualmente**
  - Abrir uma OS em status ABERTA, verificar que itens aparecem com botão × e formulário de adição
  - Adicionar um item, confirmar que a lista atualiza
  - Remover um item, confirmar que some
  - Mudar status para CONCLUIDA, confirmar que itens ficam read-only

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/OrdemServicoController.php frontend/components/forms/OSForm.tsx
git commit -m "feat: edição de itens em OS com status ABERTA ou EM_ANDAMENTO"
```

---

## TASK 4 (P1): Recálculo de status de clientes na listagem

**Problema:** Clientes com OS vencidas que não foram atualizadas desde o vencimento ficam com status `DEVEDOR` em vez de `DIVIDA_VENCIDA` na listagem.

**Solução:** Ao carregar a listagem, rodar `recalcular` em batch apenas nos clientes `DEVEDOR` (rápido — só verifica a query de data).

**Files:**
- Modify: `backend/app/Http/Controllers/ClienteController.php`

- [ ] **Step 1: Injetar ClienteStatusService e recalcular em batch no index**

```php
// No método index(), antes de retornar, inserir:
use App\Services\ClienteStatusService;

// No construtor da class:
public function __construct(private readonly ClienteStatusService $clienteStatusService) {}

// No início do método index():
// Recalcular clientes DEVEDOR que podem ter vencido desde o último acesso
$clientesDevedor = Cliente::whereIn('status', ['DEVEDOR'])->pluck('id');
foreach ($clientesDevedor as $id) {
    $this->clienteStatusService->recalcular($id);
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/app/Http/Controllers/ClienteController.php
git commit -m "feat: recalcular status DIVIDA_VENCIDA na listagem de clientes"
```

---

## TASK 5 (P1): Filtros avançados na lista de OS

**Problema:** A lista de OS só filtra por `cliente_id` exato e `status`. Faltam: filtro por período, por mecânico, por número de OS, e busca por nome do cliente.

**Files:**
- Modify: `backend/app/Http/Controllers/OrdemServicoController.php` (método `index`)
- Modify: `frontend/app/(dashboard)/os/page.tsx`

- [ ] **Step 1: Ampliar os filtros no backend**

No método `index()` do `OrdemServicoController`, adicionar após os filtros existentes:

```php
if ($request->has('mecanico_id')) {
    $query->where('mecanico_id', $request->mecanico_id);
}
if ($request->has('numero')) {
    $query->where('numero', (int)$request->numero);
}
if ($request->has('data_inicio')) {
    $query->whereDate('criado_em', '>=', $request->data_inicio);
}
if ($request->has('data_fim')) {
    $query->whereDate('criado_em', '<=', $request->data_fim);
}
if ($request->has('search')) {
    $search = $request->search;
    $query->where(function ($q) use ($search) {
        $q->whereHas('cliente', fn($c) => $c->where('nome', 'ilike', "%{$search}%"))
          ->orWhere('numero', is_numeric($search) ? (int)$search : 0);
    });
}
```

- [ ] **Step 2: Atualizar frontend os/page.tsx com barra de filtros**

```tsx
'use client'
import { useState, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { DataTable, Column } from '@/components/ui/DataTable'
import { StatusPill } from '@/components/ui/StatusPill'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import api from '@/lib/api'

interface OS {
  id: string
  numero: number
  cliente?: { nome: string; veiculo_placa?: string }
  veiculo_placa?: string
  problema_relatado?: string
  valor_total: number
  status: string
  criado_em: string
  mecanico?: { nome: string }
}

const I: React.CSSProperties = {
  padding: '7px 12px', borderRadius: 8, background: 'var(--card)',
  border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none',
}

export default function OSPage() {
  const router = useRouter()
  const [os, setOs] = useState<OS[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [dataInicio, setDataInicio] = useState('')
  const [dataFim, setDataFim] = useState('')

  useEffect(() => {
    const t = setTimeout(() => {
      setLoading(true)
      const params: Record<string, string> = {}
      if (search)      params.search      = search
      if (status)      params.status      = status
      if (dataInicio)  params.data_inicio = dataInicio
      if (dataFim)     params.data_fim    = dataFim
      api.get('/os', { params })
        .then(r => setOs(r.data.data ?? []))
        .catch(() => setOs([]))
        .finally(() => setLoading(false))
    }, 300)
    return () => clearTimeout(t)
  }, [search, status, dataInicio, dataFim])

  const columns: Column<OS>[] = [
    { key: 'numero', label: '#OS', render: r => <span className="font-mono" style={{ color: 'var(--accent)' }}>#{r.numero}</span> },
    { key: 'cliente', label: 'Cliente', render: r => r.cliente?.nome ?? '-' },
    { key: 'veiculo', label: 'Veículo', render: r => r.cliente?.veiculo_placa ?? r.veiculo_placa ?? '-' },
    { key: 'mecanico', label: 'Mecânico', render: r => r.mecanico?.nome ?? '-' },
    { key: 'problema', label: 'Serviço', render: r => <span style={{ color: 'var(--text)', fontSize: 13 }}>{r.problema_relatado?.slice(0, 40) ?? '-'}</span> },
    { key: 'valor_total', label: 'Valor', render: r => <span className="font-mono">{formatarMoeda(r.valor_total)}</span> },
    { key: 'status', label: 'Status', render: r => <StatusPill status={r.status} /> },
    { key: 'criado_em', label: 'Data', render: r => formatarData(r.criado_em) },
  ]

  const STATUS_OPTIONS = ['ABERTA', 'EM_ANDAMENTO', 'AGUARDANDO_PECAS', 'CONCLUIDA', 'CANCELADA']

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 16 }}>
        Ordens de Serviço
      </h1>

      {/* Filtros */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 20, flexWrap: 'wrap' }}>
        <input value={search} onChange={e => setSearch(e.target.value)}
          placeholder="Buscar por cliente ou #OS..." style={{ ...I, width: 240 }} />
        <select value={status} onChange={e => setStatus(e.target.value)} style={I}>
          <option value="">Todos os status</option>
          {STATUS_OPTIONS.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
        </select>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>De</span>
          <input type="date" value={dataInicio} onChange={e => setDataInicio(e.target.value)} style={I} />
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>até</span>
          <input type="date" value={dataFim} onChange={e => setDataFim(e.target.value)} style={I} />
        </div>
        {(search || status || dataInicio || dataFim) && (
          <button onClick={() => { setSearch(''); setStatus(''); setDataInicio(''); setDataFim('') }}
            style={{ ...I, background: 'transparent', color: 'var(--muted)', cursor: 'pointer' }}>
            Limpar
          </button>
        )}
      </div>

      <DataTable
        columns={columns}
        data={os}
        loading={loading}
        onRowClick={r => router.push(`/os/${r.id}`)}
        emptyMessage="Nenhuma OS encontrada."
      />
    </div>
  )
}
```

- [ ] **Step 3: Commit**

```bash
git add backend/app/Http/Controllers/OrdemServicoController.php frontend/app/\(dashboard\)/os/page.tsx
git commit -m "feat: filtros avançados na lista de OS (busca, status, período, mecânico)"
```

---

## TASK 6 (P1): UI de veículos múltiplos na tela do cliente

**Problema:** O backend já tem endpoints CRUD para veículos em `/clientes/{id}/veiculos` e `/veiculos/{id}`, mas a tela do cliente (`/clientes/[id]`) não tem UI para gerenciá-los.

**Files:**
- Modify: `frontend/app/(dashboard)/clientes/[id]/page.tsx`

- [ ] **Step 1: Adicionar interface Veiculo e estado de veículos no componente**

No topo do arquivo, após as interfaces existentes, adicionar:

```tsx
interface Veiculo {
  id: string
  modelo: string
  ano?: number | null
  placa?: string | null
  chassi?: string | null
  ativo: boolean
}
```

No componente `ClienteDetailPage`, adicionar estado:
```tsx
const [veiculos, setVeiculos] = useState<Veiculo[]>([])
const [novoVeiculo, setNovoVeiculo] = useState({ modelo: '', ano: '', placa: '' })
const [addingVeiculo, setAddingVeiculo] = useState(false)
```

No `useEffect` principal, adicionar chamada para veículos:
```tsx
api.get(`/clientes/${id}/veiculos`),
// e no .then: ([c, o, v]) => { ...; setVeiculos(v.data ?? []) }
```

- [ ] **Step 2: Adicionar seção de veículos na coluna direita**

Na coluna direita (após o histórico de OS), adicionar:

```tsx
{/* Veículos */}
<div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24, marginTop: 16 }}>
  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
    <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Veículos</h3>
    <button onClick={() => setAddingVeiculo(v => !v)}
      style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '4px 10px', cursor: 'pointer', fontSize: 12 }}>
      + Adicionar
    </button>
  </div>

  {veiculos.filter(v => v.ativo).map(v => (
    <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
      <div>
        <p style={{ margin: 0, fontSize: 14, color: 'var(--text)', fontWeight: 600 }}>{v.modelo}{v.ano ? ` ${v.ano}` : ''}</p>
        {v.placa && <p style={{ margin: 0, fontSize: 12, color: 'var(--muted)' }}>{v.placa}</p>}
      </div>
      <button onClick={() => handleRemoverVeiculo(v.id)}
        style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 13 }}>
        Remover
      </button>
    </div>
  ))}

  {veiculos.filter(v => v.ativo).length === 0 && !addingVeiculo && (
    <p style={{ color: 'var(--muted)', fontSize: 14 }}>Nenhum veículo cadastrado.</p>
  )}

  {addingVeiculo && (
    <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 8 }}>
      <input placeholder="Modelo (ex: Honda Civic)" value={novoVeiculo.modelo}
        onChange={e => setNovoVeiculo(p => ({ ...p, modelo: e.target.value }))}
        style={{ padding: '7px 10px', borderRadius: 6, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13 }} />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
        <input placeholder="Ano (ex: 2021)" value={novoVeiculo.ano}
          onChange={e => setNovoVeiculo(p => ({ ...p, ano: e.target.value }))}
          style={{ padding: '7px 10px', borderRadius: 6, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13 }} />
        <input placeholder="Placa (ex: ABC1234)" value={novoVeiculo.placa}
          onChange={e => setNovoVeiculo(p => ({ ...p, placa: e.target.value }))}
          style={{ padding: '7px 10px', borderRadius: 6, background: 'var(--bg)', border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13 }} />
      </div>
      <div style={{ display: 'flex', gap: 8 }}>
        <button onClick={handleAdicionarVeiculo}
          style={{ flex: 1, padding: '8px', background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 6, cursor: 'pointer', fontWeight: 700, fontSize: 13 }}>
          Salvar
        </button>
        <button onClick={() => setAddingVeiculo(false)}
          style={{ padding: '8px 16px', background: 'transparent', color: 'var(--muted)', border: '1px solid var(--border)', borderRadius: 6, cursor: 'pointer', fontSize: 13 }}>
          Cancelar
        </button>
      </div>
    </div>
  )}
</div>
```

- [ ] **Step 3: Adicionar handlers de veículo no componente**

```tsx
async function handleAdicionarVeiculo() {
  if (!novoVeiculo.modelo) return
  try {
    await api.post(`/clientes/${id}/veiculos`, {
      modelo: novoVeiculo.modelo,
      ano: novoVeiculo.ano ? Number(novoVeiculo.ano) : null,
      placa: novoVeiculo.placa || null,
    })
    toast('Veículo adicionado.', 'success')
    setNovoVeiculo({ modelo: '', ano: '', placa: '' })
    setAddingVeiculo(false)
    const res = await api.get(`/clientes/${id}/veiculos`)
    setVeiculos(res.data ?? [])
  } catch {
    toast('Erro ao adicionar veículo.', 'danger')
  }
}

async function handleRemoverVeiculo(veiculoId: string) {
  if (!confirm('Remover este veículo?')) return
  try {
    await api.delete(`/veiculos/${veiculoId}`)
    toast('Veículo removido.', 'success')
    setVeiculos(prev => prev.filter(v => v.id !== veiculoId))
  } catch {
    toast('Erro ao remover veículo.', 'danger')
  }
}
```

Adicionar import do `toast`:
```tsx
import { toast } from '@/hooks/useToast'
```

- [ ] **Step 4: Commit**

```bash
git add frontend/app/\(dashboard\)/clientes/\[id\]/page.tsx
git commit -m "feat: UI de veículos múltiplos na tela de detalhe do cliente"
```

---

## TASK 7 (P1): Exportação Excel e Módulo de Relatórios

**Problema:** Faltam relatórios exportáveis para OS, clientes e estoque. `maatwebsite/laravel-excel` está no spec mas não usado.

**Files:**
- Create: `backend/app/Http/Controllers/RelatorioController.php`
- Create: `backend/app/Exports/OsExport.php`
- Create: `backend/app/Exports/ClientesExport.php`
- Create: `backend/app/Exports/EstoqueExport.php`
- Modify: `backend/routes/api.php` (rotas já adicionadas na Task 2)
- Create: `frontend/app/(dashboard)/relatorios/page.tsx`
- Modify: `frontend/components/layout/Sidebar.tsx` (adicionar link Relatórios)

- [ ] **Step 1: Verificar se maatwebsite/laravel-excel está instalado**

```bash
cd backend && composer show maatwebsite/excel 2>/dev/null || composer require maatwebsite/excel
```

- [ ] **Step 2: Criar OsExport**

```php
<?php
// backend/app/Exports/OsExport.php
declare(strict_types=1);

namespace App\Exports;

use App\Models\OrdemServico;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class OsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(
        private readonly array $filters = []
    ) {}

    public function query()
    {
        $q = OrdemServico::with(['cliente', 'mecanico']);
        if (!empty($this->filters['status']))      $q->where('status', $this->filters['status']);
        if (!empty($this->filters['data_inicio'])) $q->whereDate('criado_em', '>=', $this->filters['data_inicio']);
        if (!empty($this->filters['data_fim']))    $q->whereDate('criado_em', '<=', $this->filters['data_fim']);
        return $q->orderBy('numero');
    }

    public function headings(): array
    {
        return ['#OS', 'Cliente', 'Mecânico', 'Status', 'Valor Total', 'Valor Pago', 'Saldo', 'Data'];
    }

    public function map($row): array
    {
        return [
            $row->numero,
            $row->cliente?->nome ?? '-',
            $row->mecanico?->nome ?? '-',
            $row->status,
            number_format($row->valor_total, 2, ',', '.'),
            number_format($row->valor_pago, 2, ',', '.'),
            number_format($row->getSaldoDevedorAttribute(), 2, ',', '.'),
            $row->criado_em?->format('d/m/Y') ?? '-',
        ];
    }
}
```

- [ ] **Step 3: Criar ClientesExport**

```php
<?php
// backend/app/Exports/ClientesExport.php
declare(strict_types=1);

namespace App\Exports;

use App\Models\Cliente;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ClientesExport implements FromQuery, WithHeadings, WithMapping
{
    public function query()
    {
        return Cliente::orderBy('nome');
    }

    public function headings(): array
    {
        return ['Nome', 'CPF/CNPJ', 'Telefone', 'E-mail', 'Cidade', 'UF', 'Status', 'Cadastro'];
    }

    public function map($row): array
    {
        return [
            $row->nome,
            $row->cpf_cnpj,
            $row->telefone ?? '-',
            $row->email ?? '-',
            $row->cidade ?? '-',
            $row->uf ?? '-',
            $row->status,
            $row->criado_em?->format('d/m/Y') ?? '-',
        ];
    }
}
```

- [ ] **Step 4: Criar EstoqueExport**

```php
<?php
// backend/app/Exports/EstoqueExport.php
declare(strict_types=1);

namespace App\Exports;

use App\Models\Produto;
use App\Services\EstoqueService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EstoqueExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly EstoqueService $estoqueService) {}

    public function query()
    {
        return Produto::where('ativo', true)->orderBy('nome');
    }

    public function headings(): array
    {
        return ['SKU', 'Nome', 'Categoria', 'Unidade', 'Qty Atual', 'Qty Mínima', 'Status', 'Preço Custo', 'Preço Venda'];
    }

    public function map($row): array
    {
        return [
            $row->sku,
            $row->nome,
            $row->categoria,
            $row->unidade,
            $row->qty_atual,
            $row->qty_minima,
            $this->estoqueService->getStatusEstoque($row->qty_atual, $row->qty_minima),
            number_format($row->preco_custo ?? 0, 2, ',', '.'),
            number_format($row->preco_venda ?? 0, 2, ',', '.'),
        ];
    }
}
```

- [ ] **Step 5: Criar RelatorioController**

```php
<?php
// backend/app/Http/Controllers/RelatorioController.php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ClientesExport;
use App\Exports\EstoqueExport;
use App\Exports\OsExport;
use App\Models\Cliente;
use App\Models\OrdemServico;
use App\Models\Produto;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RelatorioController extends Controller
{
    public function __construct(private readonly EstoqueService $estoqueService) {}

    public function os(Request $request): JsonResponse|BinaryFileResponse
    {
        $filters = $request->only(['status', 'data_inicio', 'data_fim']);
        $export = $request->boolean('export');

        if ($export) {
            return Excel::download(new OsExport($filters), 'ordens-servico.xlsx');
        }

        $q = OrdemServico::with(['cliente', 'mecanico']);
        if (!empty($filters['status']))      $q->where('status', $filters['status']);
        if (!empty($filters['data_inicio'])) $q->whereDate('criado_em', '>=', $filters['data_inicio']);
        if (!empty($filters['data_fim']))    $q->whereDate('criado_em', '<=', $filters['data_fim']);

        $os = $q->orderBy('numero')->get();

        $totalFaturado = $os->sum('valor_total');
        $totalRecebido = $os->sum('valor_pago');

        return response()->json([
            'total_os'        => $os->count(),
            'total_faturado'  => $totalFaturado,
            'total_recebido'  => $totalRecebido,
            'total_devedor'   => $totalFaturado - $totalRecebido,
            'por_status'      => $os->groupBy('status')->map->count(),
        ]);
    }

    public function clientes(Request $request): JsonResponse|BinaryFileResponse
    {
        if ($request->boolean('export')) {
            return Excel::download(new ClientesExport(), 'clientes.xlsx');
        }

        return response()->json([
            'total'           => Cliente::count(),
            'por_status'      => Cliente::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function estoque(Request $request): JsonResponse|BinaryFileResponse
    {
        if ($request->boolean('export')) {
            return Excel::download(new EstoqueExport($this->estoqueService), 'estoque.xlsx');
        }

        $produtos = Produto::where('ativo', true)->get();
        $criticos = $produtos->filter(fn($p) => in_array($this->estoqueService->getStatusEstoque($p->qty_atual, $p->qty_minima), ['CRITICO', 'SEM_ESTOQUE']));
        $baixos   = $produtos->filter(fn($p) => $this->estoqueService->getStatusEstoque($p->qty_atual, $p->qty_minima) === 'BAIXO');

        return response()->json([
            'total_produtos' => $produtos->count(),
            'criticos'       => $criticos->count(),
            'baixos'         => $baixos->count(),
            'normais'        => $produtos->count() - $criticos->count() - $baixos->count(),
        ]);
    }
}
```

- [ ] **Step 6: Adicionar import de RelatorioController em api.php**

No bloco de `use` no topo do `routes/api.php`:
```php
use App\Http\Controllers\RelatorioController;
```

- [ ] **Step 7: Criar página de Relatórios no frontend**

```tsx
// frontend/app/(dashboard)/relatorios/page.tsx
'use client'
import { useState, useEffect } from 'react'
import { formatarMoeda } from '@/lib/formatters'
import api from '@/lib/api'
import { toast } from '@/hooks/useToast'

interface OsReport {
  total_os: number
  total_faturado: number
  total_recebido: number
  total_devedor: number
  por_status: Record<string, number>
}

interface EstoqueReport {
  total_produtos: number
  criticos: number
  baixos: number
  normais: number
}

const I: React.CSSProperties = {
  padding: '7px 12px', borderRadius: 8, background: 'var(--card)',
  border: '1px solid var(--border)', color: 'var(--text)', fontSize: 13, outline: 'none',
}

export default function RelatoriosPage() {
  const [osData, setOsData] = useState<OsReport | null>(null)
  const [estoqueData, setEstoqueData] = useState<EstoqueReport | null>(null)
  const [dataInicio, setDataInicio] = useState('')
  const [dataFim, setDataFim] = useState('')
  const [statusFiltro, setStatusFiltro] = useState('')

  useEffect(() => {
    const params: Record<string, string> = {}
    if (dataInicio)   params.data_inicio = dataInicio
    if (dataFim)      params.data_fim    = dataFim
    if (statusFiltro) params.status      = statusFiltro

    Promise.all([
      api.get('/relatorios/os', { params }),
      api.get('/relatorios/estoque'),
    ]).then(([os, est]) => {
      setOsData(os.data)
      setEstoqueData(est.data)
    }).catch(() => {})
  }, [dataInicio, dataFim, statusFiltro])

  async function exportar(tipo: 'os' | 'clientes' | 'estoque') {
    try {
      const token = localStorage.getItem('auth_token')
      const slug  = localStorage.getItem('oficina_slug')
      const params = tipo === 'os' && (dataInicio || dataFim || statusFiltro)
        ? `?export=true${dataInicio ? '&data_inicio=' + dataInicio : ''}${dataFim ? '&data_fim=' + dataFim : ''}${statusFiltro ? '&status=' + statusFiltro : ''}`
        : '?export=true'
      const res = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'}/api/relatorios/${tipo}${params}`,
        { headers: { Authorization: `Bearer ${token}`, 'X-Tenant': slug ?? '' } }
      )
      if (!res.ok) throw new Error()
      const blob = await res.blob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${tipo}.xlsx`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      toast('Erro ao exportar.', 'danger')
    }
  }

  const cardStyle: React.CSSProperties = {
    background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 24,
  }
  const STATUS_OPTIONS = ['ABERTA', 'EM_ANDAMENTO', 'AGUARDANDO_PECAS', 'CONCLUIDA', 'CANCELADA']

  return (
    <div>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>
        Relatórios
      </h1>

      {/* Filtros OS */}
      <div style={{ ...cardStyle, marginBottom: 20 }}>
        <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', marginBottom: 14 }}>
          Filtros — Ordens de Serviço
        </h3>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
          <select value={statusFiltro} onChange={e => setStatusFiltro(e.target.value)} style={I}>
            <option value="">Todos os status</option>
            {STATUS_OPTIONS.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
          </select>
          <input type="date" value={dataInicio} onChange={e => setDataInicio(e.target.value)} style={I} />
          <span style={{ color: 'var(--muted)', fontSize: 13 }}>até</span>
          <input type="date" value={dataFim} onChange={e => setDataFim(e.target.value)} style={I} />
        </div>
      </div>

      {/* OS Stats */}
      {osData && (
        <div style={{ ...cardStyle, marginBottom: 20 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
            <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>
              Ordens de Serviço
            </h3>
            <button onClick={() => exportar('os')}
              style={{ padding: '6px 14px', background: 'var(--success)', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>
              📊 Exportar XLSX
            </button>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, marginBottom: 16 }}>
            {[
              { label: 'Total de OS', value: osData.total_os, color: 'var(--info)' },
              { label: 'Faturado', value: formatarMoeda(osData.total_faturado), color: 'var(--success)' },
              { label: 'Recebido', value: formatarMoeda(osData.total_recebido), color: 'var(--accent)' },
              { label: 'A Receber', value: formatarMoeda(osData.total_devedor), color: 'var(--danger)' },
            ].map(({ label, value, color }) => (
              <div key={label} style={{ background: 'var(--bg)', borderRadius: 8, padding: '12px 16px', borderLeft: `3px solid ${color}` }}>
                <p style={{ color: 'var(--muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 6px' }}>{label}</p>
                <p className="font-mono" style={{ color: 'var(--text)', fontSize: 20, fontWeight: 700, margin: 0 }}>{value}</p>
              </div>
            ))}
          </div>
          {Object.entries(osData.por_status).length > 0 && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              {Object.entries(osData.por_status).map(([status, count]) => (
                <span key={status} style={{
                  padding: '4px 12px', borderRadius: 20, fontSize: 12,
                  background: 'var(--surface)', color: 'var(--muted)', border: '1px solid var(--border)',
                }}>
                  {status.replace(/_/g, ' ')}: <strong style={{ color: 'var(--text)' }}>{count}</strong>
                </span>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Clientes e Estoque */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>
        <div style={cardStyle}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
            <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Clientes</h3>
            <button onClick={() => exportar('clientes')}
              style={{ padding: '6px 14px', background: 'var(--success)', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>
              📊 Exportar XLSX
            </button>
          </div>
          <p style={{ color: 'var(--muted)', fontSize: 14 }}>Exporta lista completa de clientes com status e dados de contato.</p>
        </div>

        {estoqueData && (
          <div style={cardStyle}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
              <h3 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: 0 }}>Estoque</h3>
              <button onClick={() => exportar('estoque')}
                style={{ padding: '6px 14px', background: 'var(--success)', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>
                📊 Exportar XLSX
              </button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 }}>
              {[
                { label: 'Críticos', value: estoqueData.criticos, color: 'var(--danger)' },
                { label: 'Baixos', value: estoqueData.baixos, color: 'var(--accent)' },
                { label: 'Normais', value: estoqueData.normais, color: 'var(--success)' },
              ].map(({ label, value, color }) => (
                <div key={label} style={{ textAlign: 'center', padding: 12, background: 'var(--bg)', borderRadius: 8 }}>
                  <p style={{ color, fontSize: 22, fontWeight: 700, margin: '0 0 4px' }}>{value}</p>
                  <p style={{ color: 'var(--muted)', fontSize: 11, margin: 0 }}>{label}</p>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
```

- [ ] **Step 8: Adicionar link "Relatórios" na Sidebar**

No `frontend/components/layout/Sidebar.tsx`, adicionar item de navegação para `/relatorios` (com ícone 📊) após o item de configurações, no mesmo padrão dos demais itens.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Exports/ backend/app/Http/Controllers/RelatorioController.php backend/routes/api.php \
        frontend/app/\(dashboard\)/relatorios/page.tsx frontend/components/layout/Sidebar.tsx
git commit -m "feat: módulo de relatórios com exportação Excel (OS, clientes, estoque)"
```

---

## TASK 8 (P2): Stat cards clicáveis no dashboard

**Problema:** Os stat cards do dashboard são puramente visuais. Clicar em "Dívidas em Aberto" deveria ir para a lista de clientes devedores, "NF Emitidas" para o histórico fiscal, etc.

**Files:**
- Modify: `frontend/components/ui/StatCard.tsx`
- Modify: `frontend/app/(dashboard)/page.tsx`

- [ ] **Step 1: Adicionar prop `href` no StatCard**

```tsx
// frontend/components/ui/StatCard.tsx
import Link from 'next/link'

interface StatCardProps {
  title: string
  value: string | number
  icon: string
  color: string
  subtitle?: string
  href?: string
}

export function StatCard({ title, value, icon, color, subtitle, href }: StatCardProps) {
  const inner = (
    <div style={{
      background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)',
      padding: 24, position: 'relative', overflow: 'hidden',
      cursor: href ? 'pointer' : 'default',
      transition: href ? 'border-color 0.15s' : undefined,
    }}
    onMouseEnter={href ? e => (e.currentTarget.style.borderColor = color) : undefined}
    onMouseLeave={href ? e => (e.currentTarget.style.borderColor = 'var(--border)') : undefined}
    >
      <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 2, background: color }} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <div>
          <p style={{ color: 'var(--muted)', fontSize: 12, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 8px' }}>{title}</p>
          <p className="font-mono" style={{ color: 'var(--text)', fontSize: 26, fontWeight: 700, margin: 0, lineHeight: 1 }}>{value}</p>
          {subtitle && <p style={{ color: 'var(--muted)', fontSize: 12, margin: '6px 0 0' }}>{subtitle}</p>}
        </div>
        <span style={{ fontSize: 28, opacity: 0.12 }}>{icon}</span>
      </div>
    </div>
  )

  if (href) return <Link href={href} style={{ textDecoration: 'none' }}>{inner}</Link>
  return inner
}
```

- [ ] **Step 2: Passar href para os StatCards no dashboard**

```tsx
// Em frontend/app/(dashboard)/page.tsx, atualizar os 4 StatCards:
<StatCard title="Clientes Ativos"     value={data.stats.clientes_ativos}              icon="👥" color="var(--info)"    href="/clientes" />
<StatCard title="Dívidas em Aberto"   value={formatarMoeda(data.stats.dividas_abertas)} icon="⚠"  color="var(--danger)"  href="/clientes?status=DEVEDOR,DIVIDA_VENCIDA" subtitle="Total em débito" />
<StatCard title="Faturamento do Mês"  value={formatarMoeda(data.stats.faturamento_mes)} icon="💰" color="var(--success)" href="/os?status=CONCLUIDA" />
<StatCard title="NF Emitidas"         value={data.stats.nf_emitidas_mes}               icon="🧾" color="var(--info)"    href="/fiscal/historico" subtitle="Este mês" />
```

- [ ] **Step 3: Commit**

```bash
git add frontend/components/ui/StatCard.tsx frontend/app/\(dashboard\)/page.tsx
git commit -m "feat: stat cards do dashboard clicáveis com link para listagens"
```

---

## TASK 9 (P2): Paginação e filtros de agendamentos

**Problema:** A página de agendamentos exibe todos sem paginação. Com muitos registros, fica impraticável.

**Files:**
- Modify: `backend/app/Http/Controllers/AgendamentoController.php` (garantir paginação e filtros)
- Modify: `frontend/app/(dashboard)/agendamentos/page.tsx` (adicionar controles de navegação de semana/mês)

A página já tem navegação semanal/mensal. O que falta é que o backend filtre por período e retorne corretamente ao mudar de semana/mês.

- [ ] **Step 1: Verificar filtros do AgendamentoController**

```bash
grep -n "data_inicio\|paginate\|whereDate" /c/Users/dougl/workspace6/backend/app/Http/Controllers/AgendamentoController.php
```

Se o controller já filtrar por semana, esta task pode ser pulada ou reduzida.

- [ ] **Step 2: Garantir que o backend aceita `data_inicio` e `data_fim` para filtrar agendamentos**

No método `index()` do `AgendamentoController`, confirmar que existe:
```php
if ($request->has('data_inicio') && $request->has('data_fim')) {
    $query->whereBetween('data_hora_inicio', [$request->data_inicio, $request->data_fim . ' 23:59:59']);
}
```

Se não existir, adicionar.

- [ ] **Step 3: Commit**

```bash
git add backend/app/Http/Controllers/AgendamentoController.php
git commit -m "feat: filtros por período nos agendamentos"
```

---

## TASK 10 (P2): Recibo de pagamento PDF

**Problema:** Não existe PDF de recibo para OS com pagamento registrado.

**Files:**
- Create: `backend/resources/views/pdf/recibo.blade.php`
- Modify: `backend/app/Http/Controllers/OrdemServicoController.php` (método `recibo`)
- Modify: `frontend/app/(dashboard)/os/[id]/page.tsx` (botão de recibo)

- [ ] **Step 1: Criar template Blade do recibo**

```blade
{{-- backend/resources/views/pdf/recibo.blade.php --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; color: #222; margin: 40px; }
  .header { text-align: center; border-bottom: 2px solid #f5a623; padding-bottom: 16px; margin-bottom: 20px; }
  .header h1 { font-size: 22px; margin: 0 0 4px; color: #111; }
  .header p { margin: 2px 0; color: #555; font-size: 12px; }
  .title { font-size: 18px; font-weight: bold; text-align: center; margin: 16px 0; color: #333; letter-spacing: 1px; }
  .grid { display: table; width: 100%; border-collapse: collapse; }
  .row { display: table-row; }
  .cell { display: table-cell; padding: 6px 10px; border-bottom: 1px solid #eee; }
  .label { color: #888; font-size: 11px; width: 140px; }
  .value { font-weight: 600; color: #222; }
  .total-box { margin-top: 24px; border: 2px solid #43a047; border-radius: 8px; padding: 16px 20px; text-align: right; }
  .total-box .lbl { color: #888; font-size: 12px; }
  .total-box .val { font-size: 24px; font-weight: 800; color: #43a047; }
  .footer { margin-top: 32px; text-align: center; color: #aaa; font-size: 11px; border-top: 1px solid #eee; padding-top: 12px; }
</style>
</head>
<body>
<div class="header">
  <h1>{{ $empresa['nome_fantasia'] ?? $empresa['razao_social'] ?? 'Oficina' }}</h1>
  @if(!empty($empresa['cnpj'])) <p>CNPJ: {{ $empresa['cnpj'] }}</p> @endif
  @if(!empty($empresa['telefone'])) <p>Tel: {{ $empresa['telefone'] }}</p> @endif
</div>

<div class="title">RECIBO DE PAGAMENTO</div>

<div class="grid">
  <div class="row"><div class="cell label">Nº OS</div><div class="cell value">#{{ $os->numero }}</div></div>
  <div class="row"><div class="cell label">Cliente</div><div class="cell value">{{ $os->cliente?->nome ?? '-' }}</div></div>
  <div class="row"><div class="cell label">Veículo</div><div class="cell value">{{ $os->veiculo_descricao ?? '-' }} {{ $os->veiculo_placa ? '— ' . $os->veiculo_placa : '' }}</div></div>
  <div class="row"><div class="cell label">Data do Recibo</div><div class="cell value">{{ now()->format('d/m/Y') }}</div></div>
  <div class="row"><div class="cell label">Forma de Pagamento</div><div class="cell value">{{ $os->forma_pagamento ?? '-' }}</div></div>
  <div class="row"><div class="cell label">Valor Total OS</div><div class="cell value">R$ {{ number_format($os->valor_total, 2, ',', '.') }}</div></div>
</div>

<div class="total-box">
  <p class="lbl">VALOR RECEBIDO</p>
  <p class="val">R$ {{ number_format($os->valor_pago, 2, ',', '.') }}</p>
  @if($os->getSaldoDevedorAttribute() > 0)
    <p style="color:#e53935; font-size:12px; margin-top:8px;">
      Saldo em aberto: R$ {{ number_format($os->getSaldoDevedorAttribute(), 2, ',', '.') }}
    </p>
  @else
    <p style="color:#43a047; font-size:12px; margin-top:8px;">✓ Pagamento quitado</p>
  @endif
</div>

<div class="footer">
  Emitido em {{ now()->format('d/m/Y \à\s H:i') }} — {{ $empresa['nome_fantasia'] ?? 'MecânicaPro' }}
</div>
</body>
</html>
```

- [ ] **Step 2: Adicionar método `recibo` no OrdemServicoController**

```php
public function recibo(string $id): \Illuminate\Http\Response
{
    $os = OrdemServico::with(['cliente', 'mecanico'])->findOrFail($id);

    if ($os->valor_pago <= 0) {
        abort(422, 'Esta OS não possui pagamento registrado.');
    }

    $empresa = \App\Models\Configuracao::first()?->toArray() ?? [];

    $pdf = Pdf::loadView('pdf.recibo', compact('os', 'empresa'))
        ->setPaper('a4', 'portrait');

    return $pdf->download('Recibo-OS-' . $os->numero . '.pdf');
}
```

- [ ] **Step 3: Adicionar botão de recibo na tela de detalhe da OS**

No `frontend/app/(dashboard)/os/[id]/page.tsx`, após o botão de PDF existente, adicionar:

```tsx
{(os.valor_pago ?? 0) > 0 && (
  <button onClick={downloadRecibo}
    style={{ padding: '6px 14px', background: 'transparent', border: '1px solid var(--success)', color: 'var(--success)', borderRadius: 8, cursor: 'pointer', fontSize: 13 }}>
    🧾 Recibo
  </button>
)}
```

E a função `downloadRecibo`:
```tsx
async function downloadRecibo() {
  try {
    const token = localStorage.getItem('auth_token')
    const response = await fetch(
      `${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'}/api/os/${id}/recibo`,
      { headers: { Authorization: `Bearer ${token}` } }
    )
    if (!response.ok) throw new Error()
    const blob = await response.blob()
    const url  = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url; a.download = `Recibo-OS-${os?.numero}.pdf`; a.click()
    URL.revokeObjectURL(url)
  } catch {
    alert('Erro ao gerar recibo.')
  }
}
```

- [ ] **Step 4: Commit**

```bash
git add backend/resources/views/pdf/recibo.blade.php \
        backend/app/Http/Controllers/OrdemServicoController.php \
        frontend/app/\(dashboard\)/os/\[id\]/page.tsx
git commit -m "feat: recibo de pagamento em PDF para OS com valor pago"
```

---

## TASK 11 (P2): Responsividade mobile/tablet

**Problema:** O layout nunca foi testado em telas menores. A sidebar fixa de 230px + conteúdo pode quebrar em tablet/mobile.

**Files:**
- Modify: `frontend/components/layout/Sidebar.tsx`
- Modify: `frontend/app/(dashboard)/layout.tsx`

- [ ] **Step 1: Ler o layout atual**

```bash
cat /c/Users/dougl/workspace6/frontend/app/\(dashboard\)/layout.tsx
cat /c/Users/dougl/workspace6/frontend/components/layout/Sidebar.tsx | head -80
```

- [ ] **Step 2: Adicionar hamburger menu para mobile no layout**

No `frontend/app/(dashboard)/layout.tsx`, adicionar estado `sidebarOpen` e botão de toggle no mobile. A sidebar recebe `transform: translateX(sidebarOpen ? '0' : '-100%')` e um overlay transparente fecha ao clicar fora.

A implementação precisa ser adaptada ao código existente — leia o arquivo antes de modificar.

> **Regra:** Usar media query via `useState + useEffect + window.matchMedia` para detectar mobile/desktop em vez de CSS puro (Next.js SSR).

- [ ] **Step 3: Commit após ajuste de responsividade**

```bash
git add frontend/app/\(dashboard\)/layout.tsx frontend/components/layout/Sidebar.tsx
git commit -m "feat: responsividade mobile — sidebar colapsável em telas < 768px"
```

---

## TASK 12 (P2): Modo claro/escuro

**Problema:** `next-themes` está no spec mas não implementado.

**Files:**
- Modify: `frontend/app/layout.tsx` (wrapper ThemeProvider)
- Modify: `frontend/styles/globals.css` (tokens para tema claro)
- Modify: `frontend/components/layout/Topbar.tsx` (botão de toggle)

- [ ] **Step 1: Verificar se next-themes está instalado**

```bash
cd frontend && grep "next-themes" package.json || npm install next-themes
```

- [ ] **Step 2: Adicionar ThemeProvider no root layout**

No `frontend/app/layout.tsx`:
```tsx
import { ThemeProvider } from 'next-themes'
// Envolver children com:
<ThemeProvider attribute="data-theme" defaultTheme="dark" enableSystem={false}>
  {children}
</ThemeProvider>
```

- [ ] **Step 3: Adicionar tokens de tema claro no globals.css**

```css
[data-theme='light'] {
  --bg:      #f4f5f7;
  --surface: #ffffff;
  --card:    #ffffff;
  --border:  #e2e4e9;
  --text:    #1a1c21;
  --muted:   #6b7280;
}
```

- [ ] **Step 4: Adicionar botão de toggle no Topbar**

```tsx
import { useTheme } from 'next-themes'
const { theme, setTheme } = useTheme()
// Botão:
<button onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
  style={{ background: 'none', border: '1px solid var(--border)', color: 'var(--muted)', borderRadius: 6, padding: '5px 10px', cursor: 'pointer', fontSize: 14 }}>
  {theme === 'dark' ? '☀' : '🌙'}
</button>
```

- [ ] **Step 5: Commit**

```bash
git add frontend/app/layout.tsx frontend/styles/globals.css frontend/components/layout/Topbar.tsx
git commit -m "feat: modo claro/escuro com next-themes"
```

---

## TASK 13 (P3): Testes PHPUnit adicionais

**Problema:** Agendamentos, NotaFiscal, RBAC e venda_a_prazo+DIVIDA_VENCIDA não têm cobertura de testes.

**Files:**
- Create: `backend/tests/Feature/AgendamentoTest.php`
- Create: `backend/tests/Feature/RbacTest.php`
- Modify: `backend/tests/Feature/ClienteStatusServiceTest.php` (adicionar test_status_divida_vencida)

- [ ] **Step 1: Adicionar test_status_divida_vencida no ClienteStatusServiceTest**

No arquivo existente, adicionar ao final:

```php
public function test_status_divida_vencida(): void
{
    $cliente = $this->criarCliente();

    $this->criarOs([
        'cliente_id'               => $cliente->id,
        'status'                   => 'CONCLUIDA',
        'venda_a_prazo'            => true,
        'prazo_pagamento_dias'     => 30,
        'data_vencimento_pagamento'=> now()->subDays(5)->toDateString(),
        'valor_total'              => 500,
        'valor_pago'               => 0,
    ]);

    $resultado = $this->service->recalcular($cliente->id);

    $this->assertSame('DIVIDA_VENCIDA', $resultado);
    $this->assertSame('DIVIDA_VENCIDA', $cliente->fresh()->status);
}

public function test_devedor_nao_vencido_permanece_devedor(): void
{
    $cliente = $this->criarCliente();

    $this->criarOs([
        'cliente_id'               => $cliente->id,
        'status'                   => 'CONCLUIDA',
        'venda_a_prazo'            => true,
        'prazo_pagamento_dias'     => 30,
        'data_vencimento_pagamento'=> now()->addDays(10)->toDateString(),
        'valor_total'              => 500,
        'valor_pago'               => 0,
    ]);

    $resultado = $this->service->recalcular($cliente->id);

    $this->assertSame('DEVEDOR', $resultado);
}
```

- [ ] **Step 2: Criar AgendamentoTest**

```php
<?php
// backend/tests/Feature/AgendamentoTest.php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgendamentoTest extends TestCase
{
    use RefreshDatabase;

    private function setup(): array
    {
        $admin = Usuario::create([
            'nome' => 'Admin', 'email' => 'a@t.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('pass'),
        ]);
        $cliente = Cliente::create(['nome' => 'João', 'cpf_cnpj' => '87748248800']);
        $token = $admin->createToken('t')->plainTextToken;
        return [$token, $admin->id, $cliente->id];
    }

    public function test_criar_agendamento(): void
    {
        [$token, $adminId, $clienteId] = $this->setup();

        $response = $this->withToken($token)->postJson('/api/agendamentos', [
            'cliente_id'       => $clienteId,
            'mecanico_id'      => $adminId,
            'tipo_servico'     => 'Revisão',
            'data_hora_inicio' => now()->addDay()->toDateTimeString(),
            'data_hora_fim'    => now()->addDay()->addHour()->toDateTimeString(),
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['data' => ['id', 'status']]);
    }

    public function test_confirmar_agendamento_cria_os(): void
    {
        [$token, $adminId, $clienteId] = $this->setup();

        $agendamento = $this->withToken($token)->postJson('/api/agendamentos', [
            'cliente_id'       => $clienteId,
            'mecanico_id'      => $adminId,
            'tipo_servico'     => 'Troca de óleo',
            'data_hora_inicio' => now()->addDay()->toDateTimeString(),
            'data_hora_fim'    => now()->addDay()->addHour()->toDateTimeString(),
        ])->json('data');

        $this->withToken($token)->postJson("/api/agendamentos/{$agendamento['id']}/confirmar")
             ->assertStatus(200);

        $this->assertDatabaseHas('ordens_servico', [
            'cliente_id' => $clienteId,
            'status'     => 'ABERTA',
        ]);
    }
}
```

- [ ] **Step 3: Criar RbacTest**

```php
<?php
// backend/tests/Feature/RbacTest.php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(string $role): array
    {
        static $counter = 0;
        $counter++;
        $cpfs = ['52998224725', '87748248800', '11144477735', '33700497734'];
        $u = Usuario::create([
            'nome'       => "User {$role}",
            'email'      => "user{$counter}@test.com",
            'cpf'        => $cpfs[$counter - 1] ?? '52998224725',
            'role'       => $role,
            'status'     => 'ATIVO',
            'senha_hash' => Hash::make('pass'),
        ]);
        return [$u, $u->createToken('t')->plainTextToken];
    }

    public function test_mecanico_nao_acessa_usuarios(): void
    {
        [, $token] = $this->criarUsuario('MECANICO');

        $this->withToken($token)->getJson('/api/usuarios')
             ->assertStatus(403);
    }

    public function test_admin_acessa_usuarios(): void
    {
        [, $token] = $this->criarUsuario('ADMIN');

        $this->withToken($token)->getJson('/api/usuarios')
             ->assertStatus(200);
    }

    public function test_financeiro_nao_cria_produto(): void
    {
        [, $token] = $this->criarUsuario('FINANCEIRO');

        $this->withToken($token)->postJson('/api/produtos', [
            'nome'      => 'Filtro',
            'sku'       => 'F001',
            'categoria' => 'Filtros',
            'qty_atual' => 10,
            'qty_minima'=> 3,
        ])->assertStatus(403);
    }

    public function test_atendente_acessa_clientes(): void
    {
        [, $token] = $this->criarUsuario('ATENDENTE');

        $this->withToken($token)->getJson('/api/clientes')
             ->assertStatus(200);
    }
}
```

- [ ] **Step 4: Rodar todos os testes**

```bash
cd backend && php artisan test
```

Expected: todos os testes passando, incluindo os 4 novos arquivos.

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Feature/AgendamentoTest.php \
        backend/tests/Feature/RbacTest.php \
        backend/tests/Feature/ClienteStatusServiceTest.php
git commit -m "test: cobertura para agendamentos, RBAC e status DIVIDA_VENCIDA"
```

---

## TASK 14 (P3): Multi-tenant — verificação de isolamento completo

**Problema:** Todos os models principais usam `HasTenantScope`, mas algumas queries em Services e Controllers podem bypassar o escopo (ex: `Cliente::lockForUpdate()` sem o scope).

**Files:**
- Modify: `backend/app/Services/EstoqueService.php` (verificar lockForUpdate com scope)
- Modify: `backend/app/Services/ClienteStatusService.php` (verificar que queries têm scope)
- Verify: `backend/app/Services/NfeService.php`

- [ ] **Step 1: Auditar queries que usam lockForUpdate**

```bash
grep -rn "lockForUpdate\|withoutGlobalScope\|withoutGlobalScopes\|unscoped" \
  /c/Users/dougl/workspace6/backend/app/ --include="*.php"
```

Qualquer `withoutGlobalScope('tenant')` ou `unscoped()` é uma brecha.

- [ ] **Step 2: Verificar EstoqueService**

```bash
cat /c/Users/dougl/workspace6/backend/app/Services/EstoqueService.php
```

O `Produto::lockForUpdate()->find($item->produto_id)` ainda respeita o scope global (pois `lockForUpdate()` é um modificador de query, não remove scopes). Confirmar que está OK.

- [ ] **Step 3: Auditar Controllers que fazem queries diretas sem passar pelo model**

```bash
grep -rn "DB::table\|DB::raw\|DB::select" /c/Users/dougl/workspace6/backend/app/ --include="*.php"
```

Queries `DB::table(...)` não usam Eloquent e portanto não têm o scope. Se existirem, adicionar `->where('oficina_id', TenancyContext::get())` manualmente.

- [ ] **Step 4: Verificar se RelatorioController (criado na Task 7) tem queries seguras**

O `RelatorioController` usa Eloquent models com `HasTenantScope`, portanto é seguro se o middleware de tenant estiver ativo na rota — o que foi garantido na Task 2.

- [ ] **Step 5: Registrar achados e corrigir se necessário**

Se encontrar `DB::table` sem filtro de `oficina_id`, corrigir inline.

- [ ] **Step 6: Commit (apenas se houve correção)**

```bash
git add backend/app/
git commit -m "fix: isolamento multi-tenant em queries DB::table sem Eloquent"
```

---

## TASK 15 (P3): Logs de auditoria (spatie/activitylog)

**Problema:** `spatie/laravel-activitylog` está instalado mas não está registrando nenhuma atividade nos models.

**Files:**
- Modify: `backend/app/Models/Cliente.php`
- Modify: `backend/app/Models/OrdemServico.php`
- Modify: `backend/app/Models/Produto.php`
- Modify: `backend/app/Models/Usuario.php`
- Modify: `backend/app/Models/NotaFiscal.php`

- [ ] **Step 1: Verificar se activitylog está configurado**

```bash
cd backend && php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations" 2>/dev/null || echo "already published"
php artisan migrate 2>/dev/null || echo "already migrated"
```

- [ ] **Step 2: Adicionar LogsActivity nos models principais**

Em cada model (`Cliente`, `OrdemServico`, `Produto`, `NotaFiscal`, `Usuario`), adicionar:

```php
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

// No corpo da classe, adicionar ao use existente:
use LogsActivity;

// Adicionar método:
public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs();
}
```

> **Importante:** `logOnlyDirty()` garante que só registra quando há mudança real. `dontSubmitEmptyLogs()` evita entradas vazias.

- [ ] **Step 3: Verificar que os logs são gravados**

```bash
cd backend && php artisan tinker
# $c = Cliente::first(); $c->update(['telefone' => '11999999999']);
# \Spatie\Activitylog\Models\Activity::latest()->first()->toArray()
```

Expected: um registro de activity com `subject_type = 'App\Models\Cliente'` e `properties.old` / `properties.attributes`.

- [ ] **Step 4: Commit**

```bash
git add backend/app/Models/
git commit -m "feat: logs de auditoria via spatie/activitylog em todos os models principais"
```

---

## TASK 16 (P3): Configuração de email real (Resend/SendGrid)

**Problema:** Jobs de email (`EnviarEmailRecuperacao`, `EnviarAlertaEstoque`, `EnviarNpsCliente`) usam `Mail::raw` mas o driver SMTP não está configurado para produção.

**Files:**
- Modify: `backend/.env` (adicionar MAIL_* vars com comentários de placeholder)
- Modify: `backend/app/Jobs/EnviarEmailRecuperacao.php` (verificar se usa Mailable ou Mail::raw)
- Modify: `backend/app/Jobs/EnviarAlertaEstoque.php` (idem)

- [ ] **Step 1: Verificar estado atual dos jobs de email**

```bash
cat /c/Users/dougl/workspace6/backend/app/Jobs/EnviarEmailRecuperacao.php
cat /c/Users/dougl/workspace6/backend/app/Jobs/EnviarAlertaEstoque.php
```

- [ ] **Step 2: Atualizar .env com vars de email documentadas**

No `backend/.env`, adicionar/atualizar a seção de mail:
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=465
MAIL_USERNAME=resend
MAIL_PASSWORD=re_SUBSTITUIR_POR_API_KEY_REAL
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=noreply@mecanicapro.com.br
MAIL_FROM_NAME="MecânicaPro"
```

- [ ] **Step 3: Garantir que os jobs usam a fila**

Confirmar que todos os jobs fazem `implements ShouldQueue` e estão despachados via `::dispatch()` e não `::dispatchSync()`.

- [ ] **Step 4: Commit**

```bash
git add backend/.env backend/app/Jobs/
git commit -m "config: variáveis de email SMTP (Resend) preparadas para produção"
```

---

## Ordem de execução

Execute as tasks na sequência:

```
Task 1  → Scheduler DIVIDA_VENCIDA
Task 2  → RBAC rotas
Task 3  → Editar itens OS
Task 4  → Recálculo listagem clientes
Task 5  → Filtros OS
Task 6  → Veículos múltiplos UI
Task 7  → Relatórios + Excel
Task 8  → Stat cards clicáveis
Task 9  → Filtros agendamentos
Task 10 → Recibo PDF
Task 11 → Responsividade
Task 12 → Dark/light mode
Task 13 → Testes PHPUnit
Task 14 → Multi-tenant audit
Task 15 → Activity log
Task 16 → Config email
```

**Regra de ouro:** `php artisan test` deve passar após cada task de backend. Cada task termina com commit.
