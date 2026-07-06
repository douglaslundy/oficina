# Preview e Publicação de Notificações Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar botão "Visualizar" (preview fiel do layout que aparece na oficina) e fluxo de publicação (rascunho → publicada) na página `/saas-admin/notificacoes`, reaproveitando o campo `ativo` existente.

**Architecture:** Backend ganha um endpoint dedicado `PATCH /saas/notificacoes/{id}/ativo` para alternar publicação sem reenviar o payload inteiro, e `store()` passa a forçar `ativo=false` na criação. Frontend extrai o bloco visual de `NotificacaoModal.tsx` para um componente apresentacional `NotificacaoCard.tsx`, reaproveitado tanto pelo modal real da oficina quanto por um preview novo no admin; a listagem do admin ganha os botões Visualizar e Publicar/Despublicar, e o checkbox "Ativo" sai do form.

**Tech Stack:** Next.js 14 (App Router) + TypeScript + axios (`saasApi`) no frontend; Laravel 11 + PHPUnit no backend.

## Global Constraints

- Sem migration nova — reaproveitar o campo booleano `ativo` já existente em `notificacoes` como sinônimo de "publicado".
- Máquina dev não tem Docker/Postgres local: testes de **feature** Laravel (`RefreshDatabase`) não rodam localmente. Verificação backend nesta máquina se limita a `php -l` (lint) e leitura/raciocínio manual do código; testes de feature (se escritos) só rodam em ambiente com DB (CI/VPS).
- Frontend: verificar com `npx tsc --noEmit` e `npx eslint` (rodar a partir de `frontend/`).
- Textos em pt-BR, seguindo o padrão já usado na página (`Publicar`, `Despublicar`, `Rascunho`, `Publicada`).
- Nunca rodar suíte de feature Laravel dentro do container de produção.

---

### Task 1: Backend — endpoint de publicar/despublicar + rascunho por padrão

**Files:**
- Modify: `backend/app/Http/Controllers/SaaS/NotificacaoController.php`
- Modify: `backend/routes/api.php:122-125`

**Interfaces:**
- Produces: `NotificacaoController::publicar(Request $request, string $id): JsonResponse` — valida `{ ativo: bool }` (required), atualiza só esse campo, retorna `{ message, data }`.
- Produces: rota `PATCH /saas/notificacoes/{id}/ativo` (dentro do grupo `saas-admin` já existente, mesmo grupo das outras rotas de `notificacoes`).
- Consumes: nada de tasks anteriores (é a primeira task).

A máquina dev não tem DB local, então não dá para escrever um Feature test (`RefreshDatabase`) que bata na rota real, nem vale escrever um unit test que apenas duplique a regra de validação sem exercitar o controller de verdade — isso seria um teste fraco que não cobre o código de produção. Esta task se limita a lint (`php -l`) e leitura manual do código. A verificação de comportamento real acontece no teste manual do Task 3 (Step 9, via browser) e, opcionalmente, numa checagem segura na API de produção depois do deploy (criar → chamar o endpoint → conferir → reverter).

- [ ] **Step 1: Implementar `publicar()` no controller e a rota**

Em `backend/app/Http/Controllers/SaaS/NotificacaoController.php`, adicionar o método (após `destroy()`, antes de `validatePayload()`):

```php
    public function publicar(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['ativo' => ['required', 'boolean']]);
        $notificacao = Notificacao::findOrFail($id);
        $notificacao->update(['ativo' => $validated['ativo']]);
        return response()->json(['message' => 'Status atualizado.', 'data' => $notificacao]);
    }
```

Em `backend/app/Http/Controllers/SaaS/NotificacaoController.php`, método `store()` (linhas 19-24), forçar rascunho por padrão:

```php
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $data['ativo'] = false;
        $notificacao = Notificacao::create($data);
        return response()->json(['message' => 'Notificação criada.', 'data' => $notificacao], 201);
    }
```

Em `backend/routes/api.php`, logo após a linha 125 (`Route::delete('notificacoes/{id}', ...)`), adicionar:

```php
        Route::patch('notificacoes/{id}/ativo', [\App\Http\Controllers\SaaS\NotificacaoController::class, 'publicar']);
```

- [ ] **Step 2: Verificar sintaxe (sem DB disponível, `php -l` é a checagem possível nesta máquina)**

Run: `cd backend && php -l app/Http/Controllers/SaaS/NotificacaoController.php && php -l routes/api.php`
Expected: `No syntax errors detected` para os dois arquivos.

- [ ] **Step 3: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/NotificacaoController.php backend/routes/api.php
git commit -m "feat(saas-admin): endpoint de publicar/despublicar notificacao e rascunho por padrao"
```

---

### Task 2: Frontend — extrair `NotificacaoCard` e reutilizar no `NotificacaoModal`

**Files:**
- Create: `frontend/components/NotificacaoCard.tsx`
- Modify: `frontend/components/NotificacaoModal.tsx`

**Interfaces:**
- Produces: `NotificacaoCard({ notificacao, onFechar }: { notificacao: { titulo: string; subtitulo: string | null; texto: string; imagem: string | null }; onFechar: () => void })` — componente apresentacional puro, sem fetch nem localStorage.
- Consumes: nada de tasks anteriores.

- [ ] **Step 1: Criar `frontend/components/NotificacaoCard.tsx`**

```tsx
'use client'

interface NotificacaoCardData {
  titulo: string
  subtitulo: string | null
  texto: string
  imagem: string | null
}

export function NotificacaoCard({ notificacao, onFechar }: { notificacao: NotificacaoCardData; onFechar: () => void }) {
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.7)', zIndex: 2000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
      <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 16, width: 460, maxWidth: '100%', maxHeight: '90vh', overflowY: 'auto', boxShadow: '0 12px 48px rgba(0,0,0,.5)' }}>
        {notificacao.imagem && (
          <img src={notificacao.imagem} alt={notificacao.titulo}
            style={{ width: '100%', maxHeight: 220, objectFit: 'cover', display: 'block', borderTopLeftRadius: 16, borderTopRightRadius: 16 }} />
        )}
        <div style={{ padding: 28 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
            <h2 className="font-display" style={{ fontSize: 22, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
              {notificacao.titulo}
            </h2>
            <button onClick={onFechar} aria-label="Fechar"
              style={{ background: 'none', border: 'none', color: 'var(--muted)', fontSize: 24, cursor: 'pointer', lineHeight: 1, padding: 0 }}>×</button>
          </div>
          {notificacao.subtitulo && (
            <p style={{ color: 'var(--accent)', fontSize: 14, fontWeight: 600, margin: '6px 0 0' }}>{notificacao.subtitulo}</p>
          )}
          <p style={{ color: 'var(--text)', fontSize: 14, lineHeight: 1.6, marginTop: 14, whiteSpace: 'pre-wrap' }}>
            {notificacao.texto}
          </p>
          <button onClick={onFechar}
            style={{ width: '100%', marginTop: 22, padding: '11px', borderRadius: 9, border: 'none', background: 'var(--accent)', color: '#000', fontSize: 15, fontWeight: 800, cursor: 'pointer', fontFamily: "'Barlow Condensed', sans-serif" }}>
            Fechar
          </button>
        </div>
      </div>
    </div>
  )
}
```

- [ ] **Step 2: Reescrever `frontend/components/NotificacaoModal.tsx` para usar `NotificacaoCard`**

Substituir o conteúdo do arquivo por:

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
  vezes_dia: number
  intervalo_minutos: number
}

interface Registro { day: string; count: number; last: number }

function hoje() {
  return new Date().toISOString().slice(0, 10)
}

function ler(id: string): Registro {
  try {
    const raw = localStorage.getItem(`mp_notif_${id}`)
    if (raw) return JSON.parse(raw) as Registro
  } catch { /* ignore */ }
  return { day: '', count: 0, last: 0 }
}

function elegivel(n: Notificacao): boolean {
  const r = ler(n.id)
  const countHoje = r.day === hoje() ? r.count : 0
  if (countHoje >= n.vezes_dia) return false
  if (Date.now() - r.last < n.intervalo_minutos * 60_000) return false
  return true
}

export function NotificacaoModal() {
  const [atual, setAtual] = useState<Notificacao | null>(null)

  useEffect(() => {
    api.get<{ data: Notificacao[] }>('/notificacoes/ativas')
      .then(r => {
        const elegiveis = (r.data.data ?? []).filter(elegivel)
        if (elegiveis.length > 0) setAtual(elegiveis[0])
      })
      .catch(() => { /* silencioso */ })
  }, [])

  function fechar() {
    if (atual) {
      const r = ler(atual.id)
      const count = r.day === hoje() ? r.count + 1 : 1
      try {
        localStorage.setItem(`mp_notif_${atual.id}`, JSON.stringify({ day: hoje(), count, last: Date.now() }))
      } catch { /* ignore */ }
    }
    setAtual(null)
  }

  if (!atual) return null

  return <NotificacaoCard notificacao={atual} onFechar={fechar} />
}
```

- [ ] **Step 3: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros novos relacionados a `NotificacaoCard.tsx` ou `NotificacaoModal.tsx`.

- [ ] **Step 4: Lint**

Run: `cd frontend && npx eslint components/NotificacaoCard.tsx components/NotificacaoModal.tsx`
Expected: sem erros.

- [ ] **Step 5: Commit**

```bash
git add frontend/components/NotificacaoCard.tsx frontend/components/NotificacaoModal.tsx
git commit -m "refactor(notificacoes): extrai NotificacaoCard apresentacional do NotificacaoModal"
```

---

### Task 3: Frontend — botões Visualizar e Publicar/Despublicar na listagem do admin

**Files:**
- Modify: `frontend/app/saas-admin/(protected)/notificacoes/page.tsx`

**Interfaces:**
- Consumes: `NotificacaoCard` de `@/components/NotificacaoCard` (Task 2); rota `PATCH /saas/notificacoes/{id}/ativo` (Task 1).
- Produces: nada consumido por outras tasks (última task do plano).

- [ ] **Step 1: Remover o checkbox "Ativo" do modal de criação/edição e do payload**

Em `frontend/app/saas-admin/(protected)/notificacoes/page.tsx`, dentro de `salvar()` (linhas 49-58), remover `ativo: f.ativo` do payload:

```tsx
    const payload = {
      titulo: f.titulo, subtitulo: f.subtitulo || null, texto: f.texto, imagem: f.imagem,
      alvo_tipo: f.alvo_tipo, plano_id: f.alvo_tipo === 'PLANO' ? f.plano_id : null,
      oficina_ids: f.alvo_tipo === 'OFICINAS' ? f.oficina_ids : [],
      vezes_dia: f.vezes_dia, intervalo_minutos: f.intervalo_minutos,
      data_inicio: f.data_inicio || null, data_fim: f.data_fim || null,
    }
```

Remover o bloco do checkbox (linhas 138-141):

```tsx
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontSize: 14, color: 'var(--text)' }}>
            <input type="checkbox" checked={f.ativo} onChange={e => set('ativo', e.target.checked)} style={{ width: 16, height: 16, accentColor: 'var(--accent)' }} />
            Ativa
          </label>
```

(deletar esse bloco inteiro, sem substituição — o `div` de campos fecha logo depois).

- [ ] **Step 2: Adicionar import do `NotificacaoCard` e estado de preview**

No topo do arquivo, junto aos imports existentes:

```tsx
import { NotificacaoCard } from '@/components/NotificacaoCard'
```

Dentro de `NotificacoesPage`, junto aos outros `useState` (após a linha `const [loading, setLoading] = useState(true)`):

```tsx
  const [previewing, setPreviewing] = useState<Notif | null>(null)
  const [togglingId, setTogglingId] = useState<string | null>(null)
```

- [ ] **Step 3: Adicionar a função de publicar/despublicar**

Logo após a função `remover()` (linhas 176-179):

```tsx
  async function alternarAtivo(n: Notif) {
    setTogglingId(n.id)
    try {
      await saasApi.patch(`/saas/notificacoes/${n.id}/ativo`, { ativo: !n.ativo })
      carregar()
    } catch { /* ignore */ }
    finally { setTogglingId(null) }
  }
```

- [ ] **Step 4: Renderizar o preview quando `previewing` estiver setado**

Logo abaixo da linha `{editando && <Modal .../>}` (linha 185):

```tsx
      {previewing && (
        <NotificacaoCard
          notificacao={{ titulo: previewing.titulo, subtitulo: previewing.subtitulo, texto: previewing.texto, imagem: previewing.imagem }}
          onFechar={() => setPreviewing(null)}
        />
      )}
```

- [ ] **Step 5: Atualizar a pill de status (linhas 219-221)**

```tsx
                <td style={{ padding: '12px 16px' }}>
                  <span className={`pill ${n.ativo ? 'pill-success' : 'pill-muted'}`}>{n.ativo ? 'Publicada' : 'Rascunho'}</span>
                </td>
```

- [ ] **Step 6: Adicionar os botões Visualizar e Publicar/Despublicar na coluna Ações (linhas 222-227)**

```tsx
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
```

- [ ] **Step 7: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros novos em `app/saas-admin/(protected)/notificacoes/page.tsx`.

- [ ] **Step 8: Lint**

Run: `cd frontend && npx eslint "app/saas-admin/(protected)/notificacoes/page.tsx"`
Expected: sem erros.

- [ ] **Step 9: Teste manual no navegador (dev server)**

Run: `cd frontend && npm run dev` (deixar rodando)

No navegador, acessar `/saas-admin/notificacoes` logado como super-admin e confirmar:
1. Criar uma notificação nova → aparece na lista com pill **Rascunho**.
2. Clicar **👁 Visualizar** → abre o card com o layout idêntico ao que aparece na oficina (imagem se houver, título, subtítulo âmbar, texto, botão Fechar).
3. Clicar **Fechar** no preview → modal fecha, volta pra listagem.
4. Clicar **Publicar** → pill muda para **Publicada**, botão vira **Despublicar**.
5. Editar a notificação (alterar só o texto) → salvar → confirmar que continua **Publicada** (o campo `ativo` não foi mexido pela edição).
6. Clicar **Despublicar** → pill volta para **Rascunho**.

Expected: os 6 passos acima se comportam exatamente como descrito, sem erros no console do navegador.

- [ ] **Step 10: Commit**

```bash
git add "frontend/app/saas-admin/(protected)/notificacoes/page.tsx"
git commit -m "feat(saas-admin): botoes Visualizar e Publicar/Despublicar na listagem de notificacoes"
```

---

## Self-Review

**Spec coverage:**
- Reaproveitar `ativo` como publicado → Task 1 (sem migration).
- Rascunho por padrão na criação → Task 1, `store()`.
- Botão Despublicar simétrico → Task 3, Step 6.
- Checkbox "Ativo" removido do form → Task 3, Step 1.
- Botão Visualizar com preview fiel → Task 2 (extração do `NotificacaoCard`) + Task 3 (uso na listagem).
- Endpoint dedicado sem reenviar payload completo → Task 1, `publicar()`.

Todos os pontos do spec têm task correspondente.

**Placeholder scan:** nenhum "TBD"/"similar à task anterior" — todo código é mostrado por completo em cada step.

**Type consistency:** `NotificacaoCard({ notificacao, onFechar })` é definido na Task 2 e consumido identicamente na Task 3 (mesmos nomes de prop). `alternarAtivo(n: Notif)` e `togglingId`/`previewing` são únicos e usados de forma consistente dentro da própria Task 3.
