# PDV Melhorias — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corrigir 8 bugs/features no módulo PDV (Venda Balcão): NaN total, pagamentos múltiplos, troco, venda a prazo, bug DEVEDOR, débitos no cliente, placeholder e template de alerta.

**Architecture:** Todas as mudanças são isoladas — frontend `pdv/page.tsx` (issues 1–4), backend `OrdemServicoController` (issues 2–5), `ClienteStatusService` (issue 5), frontend `clientes/[id]/page.tsx` + `OrdemServicoController::index` (issue 6), frontend `alertas/page.tsx` (issue 7), frontend `alertas/page.tsx` + backend `ClienteStatusService` (issue 8).

**Tech Stack:** Next.js 14 / TypeScript, Laravel 11 / PHP 8.3

---

## Mapa de Arquivos

| Ação | Arquivo |
|------|---------|
| MODIFY | `frontend/app/(dashboard)/pdv/page.tsx` |
| MODIFY | `backend/app/Http/Controllers/OrdemServicoController.php` |
| MODIFY | `backend/app/Services/ClienteStatusService.php` |
| CREATE | `frontend/app/(dashboard)/contas-a-receber/page.tsx` |
| MODIFY | `frontend/components/layout/Sidebar.tsx` |
| MODIFY | `frontend/app/(dashboard)/clientes/[id]/page.tsx` |
| MODIFY | `frontend/app/(dashboard)/alertas/page.tsx` |

---

## Task 1: Fix NaN total (Issue 1)

**Root cause:** `parseFloat(e.target.value)` retorna `NaN` quando o campo de preço está vazio, propagando NaN para o total. O `reduce` com array vazio retorna 0 corretamente — o bug ocorre quando o usuário apaga o valor de um item.

**Files:**
- Modify: `frontend/app/(dashboard)/pdv/page.tsx`

- [ ] **Step 1.1 — Corrigir `atualizarQtd` e `atualizarPreco`**

Localizar as funções `atualizarQtd` (linha ~146) e `atualizarPreco` (linha ~155) e substituir:

```tsx
function atualizarQtd(idx: number, qty: number) {
  const safe = isNaN(qty) || qty <= 0 ? 0 : qty
  if (safe <= 0) { removerItem(idx); return }
  setItens(prev => {
    const next = [...prev]
    next[idx] = { ...next[idx], quantidade: safe }
    return next
  })
}

function atualizarPreco(idx: number, preco: number) {
  const safe = isNaN(preco) ? 0 : preco
  setItens(prev => {
    const next = [...prev]
    next[idx] = { ...next[idx], valor_unitario: safe }
    return next
  })
}
```

- [ ] **Step 1.2 — Corrigir cálculo do total**

Na linha 163, substituir:
```tsx
// ANTES:
const total = itens.reduce((s, i) => s + i.quantidade * i.valor_unitario, 0)

// DEPOIS:
const total = itens.reduce((s, i) => s + (isNaN(i.quantidade * i.valor_unitario) ? 0 : i.quantidade * i.valor_unitario), 0)
```

- [ ] **Step 1.3 — Commit**

```bash
git add frontend/app/\(dashboard\)/pdv/page.tsx
git commit -m "fix: total NaN quando preco/qtd do item é apagado no PDV"
```

---

## Task 2: Múltiplos meios de pagamento + Troco (Issues 2 e 3)

**Design:**
- Substituir o único `<select>` de forma de pagamento por uma lista dinâmica de entradas `{ forma_pagamento, valor }`.
- Troco = sum(pagamentos.valor) - total, quando positivo.
- O backend `store` já aceita `valor_pago`; também precisamos salvar cada OsPagamento separado.

**Files:**
- Modify: `frontend/app/(dashboard)/pdv/page.tsx`
- Modify: `backend/app/Http/Controllers/OrdemServicoController.php`

### Backend

- [ ] **Step 2.1 — Aceitar array de pagamentos no `store`**

Em `OrdemServicoController::store`, adicionar validação para `pagamentos` (após a validação de `itens`):

```php
// Adicionar nas regras de validação do $request->validate([...]):
'pagamentos'                  => ['nullable', 'array'],
'pagamentos.*.forma_pagamento' => ['required_with:pagamentos', 'string', 'max:30'],
'pagamentos.*.valor'           => ['required_with:pagamentos', 'numeric', 'min:0.01'],
```

Após `$os->update(['valor_total' => $total])`, inserir o bloco de processamento de pagamentos:

```php
// Processar pagamentos múltiplos
$totalPago = 0;
foreach ($validated['pagamentos'] ?? [] as $pag) {
    \App\Models\OsPagamento::create([
        'os_id'           => $os->id,
        'forma_pagamento' => $pag['forma_pagamento'],
        'valor'           => $pag['valor'],
    ]);
    $totalPago += $pag['valor'];
}

// Para VENDA_BALCAO não-a-prazo: valor_pago = mínimo entre pago e total
// (garante que troco não gera saldo negativo)
if ($isVendaBalcao && empty($osData['venda_a_prazo'])) {
    $os->update(['valor_pago' => min($totalPago, $total)]);
} elseif ($totalPago > 0) {
    $os->update(['valor_pago' => min($totalPago, $total)]);
}
```

- [ ] **Step 2.2 — Commit backend**

```bash
git add backend/app/Http/Controllers/OrdemServicoController.php
git commit -m "feat: aceitar pagamentos multiplos na criacao de VENDA_BALCAO"
```

### Frontend

- [ ] **Step 2.3 — Substituir estado de pagamento por lista**

Em `pdv/page.tsx`, substituir:
```tsx
// REMOVER:
const [formaPagamento, setFormaPagamento] = useState('DINHEIRO')

// ADICIONAR:
interface PagamentoEntry {
  forma_pagamento: string
  valor: string  // string para facilitar input
}
const [pagamentos, setPagamentos] = useState<PagamentoEntry[]>([
  { forma_pagamento: 'DINHEIRO', valor: '' }
])
```

- [ ] **Step 2.4 — Funções de manipulação da lista de pagamentos**

Adicionar após a declaração de `pagamentos`:

```tsx
const totalPago = pagamentos.reduce((s, p) => {
  const v = parseFloat(p.valor)
  return s + (isNaN(v) ? 0 : v)
}, 0)

const troco = totalPago > total ? totalPago - total : 0

function adicionarPagamento() {
  setPagamentos(prev => [...prev, { forma_pagamento: 'DINHEIRO', valor: '' }])
}

function removerPagamento(idx: number) {
  setPagamentos(prev => prev.filter((_, i) => i !== idx))
}

function atualizarPagamento(idx: number, field: keyof PagamentoEntry, value: string) {
  setPagamentos(prev => {
    const next = [...prev]
    next[idx] = { ...next[idx], [field]: value }
    return next
  })
}

const FORMAS_PAG = ['DINHEIRO', 'PIX', 'CARTAO_DEBITO', 'CARTAO_CREDITO', 'BOLETO', 'TRANSFERENCIA']
```

- [ ] **Step 2.5 — Atualizar `finalizarVenda` para enviar lista de pagamentos**

Substituir a chamada ao `api.post('/os', {...})` por:

```tsx
async function finalizarVenda() {
  if (itens.length === 0) {
    showToast('Adicione pelo menos um produto.', 'danger')
    return
  }
  if (!vendaAPrazo) {
    const pagValidos = pagamentos.filter(p => parseFloat(p.valor) > 0)
    if (pagValidos.length === 0) {
      showToast('Informe pelo menos um pagamento.', 'danger')
      return
    }
  }
  setSalvando(true)
  try {
    const pagValidos = pagamentos
      .map(p => ({ forma_pagamento: p.forma_pagamento, valor: parseFloat(p.valor) }))
      .filter(p => !isNaN(p.valor) && p.valor > 0)

    await api.post('/os', {
      tipo:                  'VENDA_BALCAO',
      cliente_id:            clienteSelecionado?.id ?? null,
      venda_a_prazo:         vendaAPrazo,
      prazo_pagamento_dias:  vendaAPrazo ? prazoEmDias : undefined,
      valor_pago:            vendaAPrazo ? 0 : Math.min(totalPago, total),
      pagamentos:            vendaAPrazo ? [] : pagValidos,
      itens: itens.map(i => ({
        tipo:           'PECA',
        produto_id:     i.produto_id,
        descricao:      i.nome,
        quantidade:     i.quantidade,
        valor_unitario: i.valor_unitario,
      })),
    })
    showToast('Venda finalizada com sucesso!', 'success')
    setItens([])
    setClienteSelecionado(null)
    setClienteBusca('')
    setPagamentos([{ forma_pagamento: 'DINHEIRO', valor: '' }])
    setVendaAPrazo(false)
    setPrazoEmDias(30)
  } catch (e: unknown) {
    const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
    showToast(msg ?? 'Erro ao finalizar venda.', 'danger')
  } finally {
    setSalvando(false)
  }
}
```

- [ ] **Step 2.6 — Substituir painel de pagamento no render**

Localizar o bloco `{/* Forma de pagamento */}` (linha ~406) e substituir todo o bloco pelo seguinte (inclui também o estado de venda a prazo do Task 3):

```tsx
{/* Forma de pagamento */}
<div style={{
  background: 'var(--card)', border: '1px solid var(--border)',
  borderRadius: 10, padding: 20,
}}>
  <label style={labelStyle}>Pagamento</label>

  {/* Toggle venda a prazo */}
  <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12, cursor: 'pointer' }}>
    <input
      type="checkbox"
      checked={vendaAPrazo}
      onChange={e => setVendaAPrazo(e.target.checked)}
      style={{ width: 16, height: 16, accentColor: 'var(--accent)' }}
    />
    <span style={{ fontSize: 14, color: 'var(--text)' }}>Venda a prazo</span>
  </label>

  {vendaAPrazo ? (
    <div>
      <label style={labelStyle}>Prazo (dias)</label>
      <input
        type="number"
        min={1}
        max={365}
        value={prazoEmDias}
        onChange={e => setPrazoEmDias(parseInt(e.target.value) || 30)}
        style={{ ...inputStyle }}
      />
      <p style={{ fontSize: 12, color: 'var(--muted)', marginTop: 6 }}>
        Vencimento: {new Date(Date.now() + prazoEmDias * 86400000).toLocaleDateString('pt-BR')}
      </p>
    </div>
  ) : (
    <>
      {pagamentos.map((pag, idx) => (
        <div key={idx} style={{ display: 'flex', gap: 8, marginBottom: 8, alignItems: 'center' }}>
          <select
            value={pag.forma_pagamento}
            onChange={e => atualizarPagamento(idx, 'forma_pagamento', e.target.value)}
            style={{ ...inputStyle, flex: '0 0 140px' }}
          >
            {FORMAS_PAG.map(f => (
              <option key={f} value={f}>{f.replace(/_/g, ' ')}</option>
            ))}
          </select>
          <input
            type="number"
            min={0}
            step={0.01}
            placeholder="Valor"
            value={pag.valor}
            onChange={e => atualizarPagamento(idx, 'valor', e.target.value)}
            style={{ ...inputStyle, flex: 1, textAlign: 'right', fontFamily: 'monospace' }}
          />
          {pagamentos.length > 1 && (
            <button onClick={() => removerPagamento(idx)}
              style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 18, padding: '0 4px' }}>
              ✕
            </button>
          )}
        </div>
      ))}
      <button onClick={adicionarPagamento}
        style={{ background: 'none', border: '1px dashed var(--border)', color: 'var(--muted)', borderRadius: 7, padding: '6px 0', width: '100%', cursor: 'pointer', fontSize: 13 }}>
        + Adicionar meio de pagamento
      </button>
    </>
  )}
</div>
```

- [ ] **Step 2.7 — Atualizar painel de totais com troco**

Localizar o bloco `{/* Total + botão */}` e substituir o conteúdo de totais por:

```tsx
{/* Total + botão */}
<div style={{
  background: 'var(--card)', border: '1px solid var(--border)',
  borderRadius: 10, padding: 20,
}}>
  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
    <span style={{ fontSize: 13, color: 'var(--muted)' }}>Itens</span>
    <span style={{ fontSize: 13, fontFamily: 'monospace' }}>{itens.length}</span>
  </div>
  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingTop: 12, borderTop: '1px solid var(--border)', marginBottom: !vendaAPrazo && totalPago > 0 ? 6 : 18 }}>
    <span style={{ fontSize: 15, fontWeight: 700 }}>Total</span>
    <span style={{ fontSize: 22, fontWeight: 800, fontFamily: 'monospace', color: 'var(--accent)' }}>
      {formatarMoeda(total)}
    </span>
  </div>
  {!vendaAPrazo && totalPago > 0 && (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
      <span style={{ fontSize: 13, color: 'var(--muted)' }}>Pago</span>
      <span style={{ fontSize: 14, fontFamily: 'monospace', color: totalPago >= total ? 'var(--success)' : 'var(--accent)' }}>
        {formatarMoeda(totalPago)}
      </span>
    </div>
  )}
  {troco > 0 && (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18, padding: '8px 12px', background: 'rgba(67,160,71,.1)', border: '1px solid var(--success)', borderRadius: 7 }}>
      <span style={{ fontSize: 14, fontWeight: 700, color: 'var(--success)' }}>Troco</span>
      <span style={{ fontSize: 16, fontWeight: 800, fontFamily: 'monospace', color: 'var(--success)' }}>
        {formatarMoeda(troco)}
      </span>
    </div>
  )}
  {!vendaAPrazo && troco === 0 && totalPago > 0 && totalPago < total && (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18, padding: '8px 12px', background: 'rgba(229,57,53,.08)', border: '1px solid var(--danger)', borderRadius: 7 }}>
      <span style={{ fontSize: 13, color: 'var(--danger)' }}>Falta</span>
      <span style={{ fontSize: 14, fontWeight: 700, fontFamily: 'monospace', color: 'var(--danger)' }}>
        {formatarMoeda(total - totalPago)}
      </span>
    </div>
  )}

  <button
    onClick={finalizarVenda}
    disabled={salvando || itens.length === 0}
    style={{
      width: '100%', padding: '12px 0',
      background: salvando || itens.length === 0 ? 'var(--border)' : 'var(--accent)',
      color: salvando || itens.length === 0 ? 'var(--muted)' : '#000',
      border: 'none', borderRadius: 8, fontSize: 15,
      fontWeight: 800, cursor: salvando || itens.length === 0 ? 'not-allowed' : 'pointer',
      fontFamily: "'Barlow Condensed', sans-serif", letterSpacing: '0.02em',
      transition: 'background 0.15s',
    }}
  >
    {salvando ? '⟳ Finalizando...' : '✓ Finalizar Venda'}
  </button>
</div>
```

- [ ] **Step 2.8 — Commit frontend**

```bash
git add frontend/app/\(dashboard\)/pdv/page.tsx
git commit -m "feat: multiplos meios de pagamento e troco no PDV"
```

---

## Task 3: Venda a prazo no PDV + Página Contas a Receber (Issue 4)

**Files:**
- Modify: `frontend/app/(dashboard)/pdv/page.tsx` (estados `vendaAPrazo` e `prazoEmDias`)
- Create: `frontend/app/(dashboard)/contas-a-receber/page.tsx`
- Modify: `frontend/components/layout/Sidebar.tsx`
- Modify: `backend/app/Http/Controllers/OrdemServicoController.php` (filtro `em_aberto`)

- [ ] **Step 3.1 — Adicionar estados de venda a prazo no PDV**

Em `pdv/page.tsx`, adicionar após `const [toast, setToast] = useState(...)`:

```tsx
const [vendaAPrazo, setVendaAPrazo]   = useState(false)
const [prazoEmDias, setPrazoEmDias]   = useState(30)
```

(O render já foi adicionado no Task 2, Step 2.6)

- [ ] **Step 3.2 — Adicionar filtro `em_aberto` no backend**

Em `OrdemServicoController::index`, após o bloco de filtro `tipo`, adicionar:

```php
if ($request->boolean('em_aberto')) {
    $query->whereColumn('valor_pago', '<', 'valor_total')
          ->where('valor_total', '>', 0)
          ->where('status', 'CONCLUIDA');
}
```

Também atualizar o filtro de `tipo` para suportar múltiplos valores separados por vírgula:

```php
if ($request->has('tipo')) {
    $tipos = array_filter(explode(',', (string)$request->tipo));
    count($tipos) === 1
        ? $query->where('tipo', $tipos[0])
        : $query->whereIn('tipo', $tipos);
} else {
    $query->where('tipo', 'OS');
}
```

- [ ] **Step 3.3 — Commit backend**

```bash
git add backend/app/Http/Controllers/OrdemServicoController.php
git commit -m "feat: filtro em_aberto e suporte a multiplos tipos no index de OS"
```

- [ ] **Step 3.4 — Criar página Contas a Receber**

```tsx
// frontend/app/(dashboard)/contas-a-receber/page.tsx
'use client'
import { useState, useEffect, useCallback } from 'react'
import { useRouter } from 'next/navigation'
import api from '@/lib/api'
import { formatarMoeda, formatarData } from '@/lib/formatters'
import { StatusPill } from '@/components/ui/StatusPill'

interface ContaReceber {
  id: string
  numero: number
  tipo: string
  status: string
  cliente?: { id: string; nome: string } | null
  valor_total: number
  valor_pago: number
  saldo_devedor: number
  venda_a_prazo: boolean
  prazo_pagamento_dias?: number
  data_vencimento_pagamento?: string
  criado_em: string
}

function diasParaVencimento(dataVenc: string): number {
  const [d, m, y] = dataVenc.split('/')
  const venc = new Date(Number(y), Number(m) - 1, Number(d))
  const hoje = new Date()
  hoje.setHours(0, 0, 0, 0)
  return Math.ceil((venc.getTime() - hoje.getTime()) / (1000 * 60 * 60 * 24))
}

export default function ContasAReceberPage() {
  const router = useRouter()
  const [contas, setContas]     = useState<ContaReceber[]>([])
  const [loading, setLoading]   = useState(true)
  const [filtro, setFiltro]     = useState<'todos' | 'vencidas' | 'a_vencer'>('todos')

  const fetchContas = useCallback(async () => {
    setLoading(true)
    try {
      const r = await api.get<{ data: ContaReceber[] }>('/os', {
        params: { em_aberto: 1, tipo: 'OS,VENDA_BALCAO', per_page: 100 },
      })
      setContas(r.data.data ?? [])
    } catch { setContas([]) }
    finally { setLoading(false) }
  }, [])

  useEffect(() => { fetchContas() }, [fetchContas])

  const hoje = new Date()
  hoje.setHours(0, 0, 0, 0)

  const contasFiltradas = contas.filter(c => {
    if (filtro === 'todos') return true
    if (!c.data_vencimento_pagamento) return filtro === 'a_vencer'
    const dias = diasParaVencimento(c.data_vencimento_pagamento)
    if (filtro === 'vencidas') return dias < 0
    if (filtro === 'a_vencer') return dias >= 0
    return true
  })

  const totalGeral  = contasFiltradas.reduce((s, c) => s + c.saldo_devedor, 0)
  const totalVencido = contas.filter(c => c.data_vencimento_pagamento && diasParaVencimento(c.data_vencimento_pagamento) < 0).reduce((s, c) => s + c.saldo_devedor, 0)
  const totalAVencer = contas.filter(c => !c.data_vencimento_pagamento || diasParaVencimento(c.data_vencimento_pagamento) >= 0).reduce((s, c) => s + c.saldo_devedor, 0)

  const labelStyle: React.CSSProperties = { fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em' }

  return (
    <div style={{ padding: '28px 32px', maxWidth: 1000, margin: '0 auto', color: 'var(--text)' }}>
      <div style={{ marginBottom: 24 }}>
        <h1 className="font-display" style={{ fontSize: 26, fontWeight: 800, margin: 0 }}>Contas a Receber</h1>
        <p style={{ color: 'var(--muted)', fontSize: 14, margin: '4px 0 0' }}>OS e vendas com saldo em aberto</p>
      </div>

      {/* Stat cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 24 }}>
        {[
          { label: 'Total em Aberto', valor: totalGeral,   cor: 'var(--text)' },
          { label: 'Vencido',         valor: totalVencido, cor: 'var(--danger)' },
          { label: 'A Vencer',        valor: totalAVencer, cor: 'var(--accent)' },
        ].map(s => (
          <div key={s.label} style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: '16px 20px' }}>
            <p style={{ ...labelStyle, margin: '0 0 6px' }}>{s.label}</p>
            <p className="font-mono" style={{ margin: 0, fontSize: 20, fontWeight: 800, color: s.cor }}>{formatarMoeda(s.valor)}</p>
          </div>
        ))}
      </div>

      {/* Filtros */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        {(['todos', 'vencidas', 'a_vencer'] as const).map(f => (
          <button key={f} onClick={() => setFiltro(f)}
            style={{
              padding: '7px 16px', borderRadius: 6, fontSize: 13, fontWeight: 600, cursor: 'pointer',
              background: filtro === f ? 'var(--accent)' : 'transparent',
              border: `1px solid ${filtro === f ? 'var(--accent)' : 'var(--border)'}`,
              color: filtro === f ? '#000' : 'var(--muted)',
            }}>
            {f === 'todos' ? 'Todos' : f === 'vencidas' ? 'Vencidas' : 'A Vencer'}
          </button>
        ))}
        <button onClick={fetchContas} style={{ marginLeft: 'auto', padding: '7px 14px', borderRadius: 6, fontSize: 13, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer' }}>
          ↻ Atualizar
        </button>
      </div>

      {/* Tabela */}
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr>
              {['#', 'Tipo', 'Cliente', 'Data', 'Total', 'Pago', 'Saldo', 'Vencimento', 'Status'].map(h => (
                <th key={h} style={{ padding: '10px 14px', textAlign: 'left', fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', borderBottom: '1px solid var(--border)', background: 'var(--surface)', whiteSpace: 'nowrap' }}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              Array.from({ length: 5 }).map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {Array.from({ length: 9 }).map((_, j) => (
                    <td key={j} style={{ padding: '12px 14px' }}>
                      <div style={{ height: 12, borderRadius: 3, background: 'var(--border)', width: 60 }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : contasFiltradas.length === 0 ? (
              <tr>
                <td colSpan={9} style={{ padding: 48, textAlign: 'center', color: 'var(--muted)', fontSize: 14 }}>
                  Nenhuma conta em aberto.
                </td>
              </tr>
            ) : (
              contasFiltradas.map((c, idx) => {
                const dias = c.data_vencimento_pagamento ? diasParaVencimento(c.data_vencimento_pagamento) : null
                const vencida = dias !== null && dias < 0
                const corSaldo = vencida ? 'var(--danger)' : 'var(--accent)'
                const rowBg = vencida ? 'rgba(229,57,53,.05)' : ''

                return (
                  <tr key={c.id}
                    onClick={() => router.push(c.tipo === 'VENDA_BALCAO' ? `/pdv` : `/os/${c.id}`)}
                    style={{
                      borderBottom: idx < contasFiltradas.length - 1 ? '1px solid var(--border)' : 'none',
                      background: rowBg, cursor: 'pointer',
                    }}
                    onMouseEnter={e => { (e.currentTarget as HTMLTableRowElement).style.background = vencida ? 'rgba(229,57,53,.08)' : 'rgba(255,255,255,.02)' }}
                    onMouseLeave={e => { (e.currentTarget as HTMLTableRowElement).style.background = rowBg }}
                  >
                    <td style={{ padding: '11px 14px', fontSize: 13, fontWeight: 700 }}>#{c.numero}</td>
                    <td style={{ padding: '11px 14px' }}>
                      <span style={{ fontSize: 11, padding: '2px 8px', borderRadius: 4, background: c.tipo === 'VENDA_BALCAO' ? 'rgba(30,136,229,.15)' : 'rgba(245,166,35,.15)', color: c.tipo === 'VENDA_BALCAO' ? 'var(--info)' : 'var(--accent)', fontWeight: 700 }}>
                        {c.tipo === 'VENDA_BALCAO' ? 'Balcão' : 'OS'}
                      </span>
                    </td>
                    <td style={{ padding: '11px 14px', fontSize: 13 }}>{c.cliente?.nome ?? '—'}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12, color: 'var(--muted)' }}>{formatarData(c.criado_em)}</td>
                    <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 13 }}>{formatarMoeda(c.valor_total)}</td>
                    <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 13, color: 'var(--success)' }}>{formatarMoeda(c.valor_pago)}</td>
                    <td style={{ padding: '11px 14px', fontFamily: 'monospace', fontSize: 13, fontWeight: 700, color: corSaldo }}>{formatarMoeda(c.saldo_devedor)}</td>
                    <td style={{ padding: '11px 14px', fontSize: 12 }}>
                      {dias === null ? (
                        <span style={{ color: 'var(--muted)' }}>—</span>
                      ) : vencida ? (
                        <span style={{ color: 'var(--danger)', fontWeight: 700 }}>Venceu há {Math.abs(dias)}d</span>
                      ) : (
                        <span style={{ color: 'var(--accent)' }}>Em {dias}d</span>
                      )}
                    </td>
                    <td style={{ padding: '11px 14px' }}><StatusPill status={c.status} /></td>
                  </tr>
                )
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
```

- [ ] **Step 3.5 — Adicionar "Contas a Receber" na Sidebar**

Em `frontend/components/layout/Sidebar.tsx`, no array `NAV_ITEMS`, adicionar após `/pdv`:

```ts
{ href: '/contas-a-receber', label: 'Contas a Receber', icon: '💰' },
```

- [ ] **Step 3.6 — Commit**

```bash
git add frontend/app/\(dashboard\)/contas-a-receber/page.tsx \
        frontend/components/layout/Sidebar.tsx \
        frontend/app/\(dashboard\)/pdv/page.tsx
git commit -m "feat: venda a prazo no PDV e pagina contas a receber"
```

---

## Task 4: Fix bug DEVEDOR em venda balcão (Issue 5)

**Root cause:** Na criação da VENDA_BALCAO, o frontend envia `valor_pago: total` usando aritmética float de JS, enquanto o backend calcula `valor_total` com PHP float. A mínima divergência de ponto flutuante faz `valor_pago < valor_total` no banco, disparando o status DEVEDOR.

**Fix:** Após calcular `$total` no backend, para VENDA_BALCAO não-a-prazo, forçar `valor_pago = valor_total` no banco.

**Files:**
- Modify: `backend/app/Http/Controllers/OrdemServicoController.php`

- [ ] **Step 4.1 — Garantir valor_pago = valor_total para balcão não-a-prazo**

Em `store`, o bloco já tem o processamento de pagamentos do Task 2. Garantir que o `min($totalPago, $total)` cobre o caso. Se o cliente paga em múltiplos meios cobrindo o total, `$totalPago >= $total` e `min($totalPago, $total) = $total = $valor_total`.

Confirmar que o bloco de processamento de pagamentos (adicionado no Task 2) está assim:

```php
// Processar pagamentos múltiplos
$totalPago = 0;
foreach ($validated['pagamentos'] ?? [] as $pag) {
    \App\Models\OsPagamento::create([
        'os_id'           => $os->id,
        'forma_pagamento' => $pag['forma_pagamento'],
        'valor'           => $pag['valor'],
    ]);
    $totalPago += (float)$pag['valor'];
}

// Para VENDA_BALCAO não-a-prazo: valor_pago = valor_total (sem divergência de float)
if ($isVendaBalcao && empty($osData['venda_a_prazo'])) {
    $os->update(['valor_total' => $total, 'valor_pago' => $total]);
} elseif ($totalPago > 0) {
    $os->update(['valor_pago' => min($totalPago, $total)]);
}
```

A chave é `$os->update(['valor_total' => $total, 'valor_pago' => $total])` — ambos definidos com o MESMO valor PHP `$total`, eliminando qualquer divergência.

- [ ] **Step 4.2 — Commit**

```bash
git add backend/app/Http/Controllers/OrdemServicoController.php
git commit -m "fix: valor_pago sempre igual a valor_total em VENDA_BALCAO nao-a-prazo"
```

---

## Task 5: Mostrar débitos na tela do cliente (Issue 6)

**Root cause:** A query em `clientes/[id]/page.tsx` busca `/os?cliente_id={id}` sem especificar `tipo`, e o backend usa `tipo='OS'` como padrão — excluindo VENDA_BALCAO com saldo em aberto da lista.

**Files:**
- Modify: `frontend/app/(dashboard)/clientes/[id]/page.tsx`

- [ ] **Step 5.1 — Incluir VENDA_BALCAO na query de OS do cliente**

Na linha ~70 de `clientes/[id]/page.tsx`, localizar a chamada ao `/os` e alterar:

```tsx
// ANTES:
api.get(`/os?cliente_id=${id}`),

// DEPOIS:
api.get(`/os?cliente_id=${id}&tipo=OS,VENDA_BALCAO`),
```

O filtro de tipo no backend (Task 3, Step 3.2) já suporta múltiplos valores separados por vírgula com `whereIn`.

- [ ] **Step 5.2 — Commit**

```bash
git add frontend/app/\(dashboard\)/clientes/\[id\]/page.tsx
git commit -m "fix: incluir VENDA_BALCAO no historico e debitos do cliente"
```

---

## Task 6: Corrigir placeholder de telefone (Issue 7)

**Files:**
- Modify: `frontend/app/(dashboard)/alertas/page.tsx`

- [ ] **Step 6.1 — Substituir placeholder e texto de exemplo**

Em `alertas/page.tsx`, fazer 3 substituições:

**Substituição 1** — linha ~134 (EditModal, textarea de destinatários):
```tsx
// ANTES:
placeholder="35984297193&#10;35999887766"

// DEPOIS:
placeholder="35984000000&#10;35999887766"
```

**Substituição 2** — linha ~136 (parágrafo de exemplo abaixo do textarea):
```tsx
// ANTES:
<p style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>Apenas números, com DDD. Ex: 35984297193</p>

// DEPOIS:
<p style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>Apenas números, com DDD. Ex: 35984000000</p>
```

**Substituição 3** — linha ~242 (CreateModal, textarea de destinatários):
```tsx
// ANTES:
placeholder="35984297193"

// DEPOIS:
placeholder="35984000000"
```

- [ ] **Step 6.2 — Commit**

```bash
git add frontend/app/\(dashboard\)/alertas/page.tsx
git commit -m "fix: corrige placeholder de telefone no cadastro de alerta"
```

---

## Task 7: Template de itens no alerta CLIENTE_DEVEDOR (Issue 8)

**Goal:** Adicionar variável `{itens}` disponível no template de alerta para `CLIENTE_DEVEDOR` e `DIVIDA_VENCIDA`, que lista as peças/serviços da OS com dívida.

**Files:**
- Modify: `frontend/app/(dashboard)/alertas/page.tsx`
- Modify: `backend/app/Services/ClienteStatusService.php`

### Frontend

- [ ] **Step 7.1 — Adicionar `{itens}` em VARIAVEIS_POR_TIPO**

Em `alertas/page.tsx`, na constante `VARIAVEIS_POR_TIPO`, alterar as entradas de `CLIENTE_DEVEDOR` e `DIVIDA_VENCIDA`:

```tsx
// ANTES:
CLIENTE_DEVEDOR: ['{cliente}', '{valor}', '{os_numero}'],
DIVIDA_VENCIDA:  ['{cliente}', '{valor}', '{os_numero}', '{vencimento}'],

// DEPOIS:
CLIENTE_DEVEDOR: ['{cliente}', '{valor}', '{os_numero}', '{itens}'],
DIVIDA_VENCIDA:  ['{cliente}', '{valor}', '{os_numero}', '{vencimento}', '{itens}'],
```

- [ ] **Step 7.2 — Commit frontend**

```bash
git add frontend/app/\(dashboard\)/alertas/page.tsx
git commit -m "feat: variavel {itens} disponivel nos templates de alerta de divida"
```

### Backend

- [ ] **Step 7.3 — Passar variável `itens` no dispatch de CLIENTE_DEVEDOR**

Em `ClienteStatusService.php`, no bloco `if ($temDebito)`, a OS já é carregada para obter dados. Adicionar o carregamento dos itens e formatação:

```php
if ($temDebito) {
    $cliente = Cliente::find($clienteId);
    if ($cliente && !in_array($cliente->status, ['DEVEDOR', 'DIVIDA_VENCIDA'], true)) {
        $os = OrdemServico::where('cliente_id', $clienteId)
            ->where('status', 'CONCLUIDA')
            ->whereColumn('valor_pago', '<', 'valor_total')
            ->with('itens')      // ← Carregar itens
            ->first();

        // Formatar lista de itens como texto para o template
        $itensTexto = $os?->itens
            ->map(fn($i) => "• {$i->descricao} (x{$i->quantidade})")
            ->join("\n") ?? '-';

        $this->alertas->dispatch('CLIENTE_DEVEDOR', [
            'cliente'           => $cliente->nome,
            'valor'             => 'R$ ' . number_format(max(0, $os?->valor_total - $os?->valor_pago), 2, ',', '.'),
            'os_numero'         => $os?->numero ?? '-',
            'itens'             => $itensTexto,          // ← NOVO
            '_telefone_cliente' => $cliente->telefone ?? '',
        ]);
    }
    Cliente::where('id', $clienteId)->update(['status' => 'DEVEDOR']);
    return 'DEVEDOR';
}
```

Fazer o mesmo para `DIVIDA_VENCIDA` no bloco anterior:

```php
if ($temDividaVencida) {
    $cliente = Cliente::find($clienteId);
    if ($cliente && $cliente->status !== 'DIVIDA_VENCIDA') {
        $os = OrdemServico::where('cliente_id', $clienteId)
            ->where('status', 'CONCLUIDA')->where('venda_a_prazo', true)
            ->whereColumn('valor_pago', '<', 'valor_total')
            ->with('itens')     // ← Carregar itens
            ->first();

        $itensTexto = $os?->itens
            ->map(fn($i) => "• {$i->descricao} (x{$i->quantidade})")
            ->join("\n") ?? '-';

        $this->alertas->dispatch('DIVIDA_VENCIDA', [
            'cliente'            => $cliente->nome,
            'valor'              => 'R$ ' . number_format(max(0, $os?->valor_total - $os?->valor_pago), 2, ',', '.'),
            'os_numero'          => $os?->numero ?? '-',
            'vencimento'         => $os?->data_vencimento_pagamento?->format('d/m/Y') ?? '-',
            'itens'              => $itensTexto,         // ← NOVO
            '_telefone_cliente'  => $cliente->telefone ?? '',
        ]);
    }
    Cliente::where('id', $clienteId)->update(['status' => 'DIVIDA_VENCIDA']);
    return 'DIVIDA_VENCIDA';
}
```

- [ ] **Step 7.4 — Commit backend**

```bash
git add backend/app/Services/ClienteStatusService.php
git commit -m "feat: variavel {itens} no alerta CLIENTE_DEVEDOR e DIVIDA_VENCIDA"
```

---

## Task 8: Push e Deploy

- [ ] **Step 8.1 — TypeScript check**

```bash
cd frontend && npx tsc --noEmit
```

Esperado: 0 erros.

- [ ] **Step 8.2 — PHP syntax check**

```bash
cd backend && php -l app/Http/Controllers/OrdemServicoController.php && php -l app/Services/ClienteStatusService.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 8.3 — Push**

```bash
git push origin main
```

- [ ] **Step 8.4 — Deploy na VPS**

```bash
ssh lundy@192.168.0.115 "cd /home/lundy/mecanicapro && git pull origin main && docker compose -p mecanicapro -f docker-compose.prod.yml build backend frontend && docker compose -p mecanicapro -f docker-compose.prod.yml up -d backend frontend"
```

- [ ] **Step 8.5 — Verificar containers**

```bash
ssh lundy@192.168.0.115 "docker compose -p mecanicapro -f docker-compose.prod.yml ps"
```

Esperado: backend e frontend `(healthy)`.

---

## Self-Review

**Spec coverage:**
1. ✅ NaN total → Task 1
2. ✅ Múltiplos meios de pagamento → Task 2
3. ✅ Troco → Task 2 (totalPago > total mostra troco)
4. ✅ Venda a prazo + contas a receber → Task 3
5. ✅ Bug DEVEDOR em balcão → Task 4
6. ✅ Mostrar débito na tela do cliente → Task 5
7. ✅ Placeholder telefone → Task 6
8. ✅ Template compra no alerta → Task 7

**Dependências entre tasks:**
- Task 2 (pagamentos múltiplos) deve ser implementada ANTES do Task 4 (fix DEVEDOR), pois o Task 4 se baseia no bloco de processamento de pagamentos adicionado no Task 2.
- Task 3 backend (filtro `tipo` por vírgula) deve ser implementado ANTES do Task 5 (frontend usa `tipo=OS,VENDA_BALCAO`).
- Todas as outras tasks são independentes.
