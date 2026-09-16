# Limpeza e Polish Pós-Auditoria — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Executar os 5 achados restantes da auditoria completa de 2026-09-16 (Rodada 53 do `PROGRESSO.md`), exceto o item de cifra de backup (`BACKUP_PASSPHRASE`), que o usuário fará por conta própria.

**Architecture:** Duas limpezas de código morto no backend (Job não despachado, dependência Composer sem uso real), uma extração de módulo compartilhado no frontend pra eliminar duplicação entre `proxy.ts` e `Sidebar.tsx`, e dois polimentos de UI (animação de sucesso no login, botão de pré-visualização de PDF na emissão de NF) alinhando o sistema com o `CLAUDE.md`.

**Tech Stack:** Laravel 11 (PHP 8.3, backend/), Next.js 16 (App Router, TypeScript, frontend/) — **atenção:** este projeto está em Next.js 16, não 14 como o `CLAUDE.md` presume; ele renomeou `middleware.ts` para `proxy.ts` na v16.0.0. Ler `frontend/AGENTS.md` antes de qualquer mudança em `frontend/`.

**Spec:** Não há spec formal separada — este plano nasce direto dos achados registrados em `PROGRESSO.md`, seção "Rodada 53 (2026-09-16) — auditoria completa + fix de proteção de rota por role", subseção "Outros achados da auditoria — NÃO corrigidos ainda".

## Global Constraints

- TypeScript strict mode — sem `any` explícito no frontend (regra do `CLAUDE.md`, seção "CONSTRAINTS DE QUALIDADE").
- PHP 8.3 tipagem estrita — `declare(strict_types=1)` em todo arquivo PHP tocado/criado.
- Datas em pt-BR, valores monetários como `R$ X.XXX,XX` (não relevante a este plano especificamente, mas vale lembrar ao tocar JSX de telas).
- Este frontend **não tem framework de teste automatizado** (sem Jest/Vitest/Playwright no `package.json`, confirmado antes de escrever este plano) — a verificação de tarefas de frontend é `npx tsc --noEmit` + `npm run build`, nunca inventar um teste automatizado que o projeto não usa.
- Feature tests PHPUnit (`tests/Feature/`) exigem PostgreSQL — não rodam na máquina de desenvolvimento local (ver memória `feedback-local-testing`). Só a suíte `Unit` roda localmente. Isso não bloqueia nenhuma tarefa deste plano (nenhuma delas tem Feature test novo).
- Sempre atualizar `PROGRESSO.md` ao final de cada tarefa (regra permanente do `CLAUDE.md` da raiz do projeto) — não deixar acumulado pro fim do plano inteiro.
- Nunca commitar com `--no-verify` nem pular hooks.

---

### Task 1: Remover Job morto `EnviarAlertaEstoque`

**Contexto:** Confirmado na auditoria (Rodada 53) que este Job nunca é despachado em lugar nenhum do código — o alerta de estoque real passa por `AlertaDispatchService`. O `CLAUDE.md` ainda cita este Job pelo nome antigo na seção "ESTRUTURA DE ARQUIVOS" e num bloco de código de exemplo em "REGRAS DE NEGÓCIO CRÍTICAS" — **não editar `CLAUDE.md`**, é o documento de spec original do projeto, não um tracker de progresso.

**Files:**
- Delete: `backend/app/Jobs/EnviarAlertaEstoque.php`

**Interfaces:**
- Consumes: nada (é uma remoção pura).
- Produces: nada — nenhuma outra tarefa deste plano depende desta.

- [ ] **Step 1: Confirmar que não há nenhuma referência ao Job em lugar nenhum do backend**

Run: `grep -rn "EnviarAlertaEstoque" backend/app backend/tests backend/routes backend/database 2>/dev/null`

Expected: a única linha encontrada é a própria definição da classe, `backend/app/Jobs/EnviarAlertaEstoque.php:15:class EnviarAlertaEstoque implements ShouldQueue`. Se aparecer QUALQUER outra referência (um `dispatch()`, um teste, um `Queue::fake()->assertPushed`), **pare e não delete** — investigue esse uso antes de prosseguir.

- [ ] **Step 2: Deletar o arquivo**

```bash
rm backend/app/Jobs/EnviarAlertaEstoque.php
```

- [ ] **Step 3: Rodar a suíte Unit pra confirmar zero regressão**

Run (do diretório `backend/`): `OPENSSL_CONF=/mingw64/etc/ssl/openssl.cnf ./vendor/bin/phpunit --testsuite=Unit`

Expected: mesmo resultado de antes da mudança — 383 testes, 868 assertions, 7 erros (os mesmos de sempre, causados por `RefreshDatabase` sem Postgres local, não por esta mudança). Se o número de testes cair ou aparecer um erro NOVO, a exclusão quebrou algo que o grep do Step 1 não pegou — restaure o arquivo e investigue.

- [ ] **Step 4: Atualizar `PROGRESSO.md`**

Adicionar ao topo do arquivo (seção "Última atualização") e como nova entrada de rodada, registrando: o que foi feito (removido o Job morto `EnviarAlertaEstoque`, confirmado zero referências, suíte Unit sem regressão), e por que (código morto encontrado na auditoria da Rodada 53, nunca despachado — o pipeline real de alerta é `AlertaDispatchService`).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Jobs/EnviarAlertaEstoque.php PROGRESSO.md
git commit -m "chore(estoque): remove Job EnviarAlertaEstoque morto (nunca despachado, pipeline real é AlertaDispatchService)"
```

---

### Task 2: Remover dependência morta `spatie/laravel-permission`

**Contexto:** Confirmado na auditoria que este pacote está no `composer.json` mas tem ZERO uso real no código (`grep -rn "HasRoles\|assignRole\|->hasRole(\|Spatie\\\\Permission" backend/app` não retorna nada). O RBAC real do projeto funciona via `backend/app/Http/Middleware/CheckRole.php`, que compara a coluna `usuarios.role` direto — e o usuário confirmou (2026-09-16) que quer só remover a dependência morta, não migrar o RBAC pra usar o pacote de verdade.

**Files:**
- Modify: `backend/composer.json` (via comando `composer remove`, não editar manualmente)
- Modify: `backend/composer.lock` (gerado automaticamente pelo comando acima)

**Interfaces:**
- Consumes: nada.
- Produces: nada — nenhuma outra tarefa deste plano depende desta.

- [ ] **Step 1: Confirmar zero uso real do pacote antes de remover**

Run (do diretório `backend/`):
```bash
grep -rln "Spatie\\\\Permission\|HasRoles\|assignRole\|->hasRole(\|->hasPermissionTo(" app database routes
```

Expected: nenhum resultado. Se aparecer algo, **pare** — o pacote está em uso em algum lugar que a auditoria anterior não cobriu, e removê-lo quebraria isso.

- [ ] **Step 2: Remover a dependência**

Run (do diretório `backend/`): `composer remove spatie/laravel-permission`

Expected: saída confirmando a remoção, `composer.json` e `composer.lock` atualizados automaticamente (não editar esses arquivos à mão).

- [ ] **Step 3: Confirmar que o autoload ainda funciona e a suíte Unit passa**

Run (do diretório `backend/`):
```bash
composer dump-autoload
OPENSSL_CONF=/mingw64/etc/ssl/openssl.cnf ./vendor/bin/phpunit --testsuite=Unit
```

Expected: `composer dump-autoload` sem erro; suíte com o mesmo resultado de antes (383 testes, 868 assertions, 7 erros pré-existentes, zero erro novo). Um erro novo aqui indicaria que algo dependia do pacote de forma indireta (ex: um Service Provider auto-registrado que outro código assume presente) — investigar antes de prosseguir.

- [ ] **Step 4: Atualizar `PROGRESSO.md`**

Registrar: dependência `spatie/laravel-permission` removida (estava instalada, zero uso real confirmado por grep antes e depois da remoção), decisão do usuário de manter o RBAC custom (`CheckRole.php`) em vez de migrar.

- [ ] **Step 5: Commit**

```bash
git add backend/composer.json backend/composer.lock PROGRESSO.md
git commit -m "chore(deps): remove spatie/laravel-permission (zero uso real, RBAC via CheckRole custom)"
```

---

### Task 3: Extrair módulo compartilhado de regras de role (`frontend/lib/roleRules.ts`)

**Contexto:** `frontend/proxy.ts` já tem um array `ROLE_RULES` (adicionado na Rodada 53, ver `PROGRESSO.md`) que mapeia prefixo de rota → roles permitidos, usado pra bloquear navegação. A Task 4 (filtro de sidebar por role) precisa exatamente da mesma lógica — extrair pra um módulo compartilhado evita duplicar o mapeamento em dois lugares (violaria DRY e criaria risco real de os dois arquivos divergirem com o tempo).

**Files:**
- Create: `frontend/lib/roleRules.ts`
- Modify: `frontend/proxy.ts` (remover o `ROLE_RULES` local, importar do módulo novo)

**Interfaces:**
- Consumes: nada.
- Produces: `frontend/lib/roleRules.ts` exporta:
  - `interface RoleRule { prefix: string; roles: string[] }`
  - `export const ROLE_RULES: RoleRule[]`
  - `export function regraRoleDe(pathname: string): RoleRule | undefined`
  - `export function papelPermitido(pathname: string, role: string): boolean`
  A Task 4 importa `regraRoleDe` deste módulo.

- [ ] **Step 1: Ler o estado atual de `frontend/proxy.ts`**

Antes de editar, leia o arquivo inteiro (`frontend/proxy.ts`, ~70 linhas) pra confirmar que o `ROLE_RULES` e o bloco de bloqueio por role ainda estão exatamente como descritos abaixo — a Rodada 53 pode ter sido a última mudança, mas confirme antes de mexer.

O array atual, dentro de `proxy.ts`:
```ts
const ROLE_RULES: { prefix: string; roles: string[] }[] = [
  { prefix: '/configuracoes/categorias-fiscais', roles: ['ADMIN', 'ATENDENTE'] },
  { prefix: '/configuracoes', roles: ['ADMIN'] },
  { prefix: '/empresa', roles: ['ADMIN'] },
  { prefix: '/usuarios', roles: ['ADMIN'] },
  { prefix: '/auditoria', roles: ['ADMIN'] },
  { prefix: '/fiscal', roles: ['ADMIN', 'FINANCEIRO'] },
  { prefix: '/relatorios', roles: ['ADMIN', 'FINANCEIRO'] },
  { prefix: '/minhas-faturas', roles: ['ADMIN', 'FINANCEIRO'] },
  { prefix: '/alertas', roles: ['ADMIN', 'ATENDENTE'] },
]
```
e o uso, dentro da função `proxy()`:
```ts
if (logado) {
  const role = request.cookies.get('oficina_role')?.value
  const regra = ROLE_RULES.find(r => pathname.startsWith(r.prefix))
  if (regra && role && !regra.roles.includes(role)) {
    return NextResponse.redirect(new URL('/?acesso=negado', request.url))
  }
}
```

- [ ] **Step 2: Criar `frontend/lib/roleRules.ts`**

```ts
export interface RoleRule {
  prefix: string
  roles: string[]
}

// Telas cujas rotas de LEITURA no backend já são restritas por role (não é
// só a mutação) — ver backend/routes/api.php. Nunca duplicar telas cuja
// leitura é aberta a todos os roles (ex: clientes, produtos, OS): lá a
// proteção real já é 100% no backend, via middleware `role:` nas rotas de
// escrita, e bloquear a tela inteira aqui seria mais restritivo que o
// próprio backend. Ordem importa: prefixos mais específicos primeiro (ex:
// categorias-fiscais antes de configuracoes, que tem uma role diferente).
export const ROLE_RULES: RoleRule[] = [
  { prefix: '/configuracoes/categorias-fiscais', roles: ['ADMIN', 'ATENDENTE'] },
  { prefix: '/configuracoes', roles: ['ADMIN'] },
  { prefix: '/empresa', roles: ['ADMIN'] },
  { prefix: '/usuarios', roles: ['ADMIN'] },
  { prefix: '/auditoria', roles: ['ADMIN'] },
  { prefix: '/fiscal', roles: ['ADMIN', 'FINANCEIRO'] },
  { prefix: '/relatorios', roles: ['ADMIN', 'FINANCEIRO'] },
  { prefix: '/minhas-faturas', roles: ['ADMIN', 'FINANCEIRO'] },
  { prefix: '/alertas', roles: ['ADMIN', 'ATENDENTE'] },
]

export function regraRoleDe(pathname: string): RoleRule | undefined {
  return ROLE_RULES.find(r => pathname.startsWith(r.prefix))
}

export function papelPermitido(pathname: string, role: string): boolean {
  const regra = regraRoleDe(pathname)
  return !regra || regra.roles.includes(role)
}
```

- [ ] **Step 3: Atualizar `frontend/proxy.ts` pra usar o módulo novo**

Remover o array `ROLE_RULES` local (o bloco inteiro mostrado no Step 1) e adicionar no topo do arquivo, junto dos outros imports:
```ts
import { papelPermitido } from './lib/roleRules'
```

Trocar o bloco de uso por:
```ts
if (logado) {
  const role = request.cookies.get('oficina_role')?.value
  if (role && !papelPermitido(pathname, role)) {
    return NextResponse.redirect(new URL('/?acesso=negado', request.url))
  }
}
```

- [ ] **Step 4: Verificar tipos**

Run (do diretório `frontend/`): `npx tsc --noEmit`

Expected: nenhuma saída (sem erros). Se houver erro de import (ex: resolução de path `./lib/roleRules` a partir da raiz), confirme se `proxy.ts` está na raiz de `frontend/` (mesmo nível de `app/`) — `find frontend -maxdepth 1 -iname "proxy.ts"` deve confirmar isso antes de investigar mais.

- [ ] **Step 5: Build de produção**

Run (do diretório `frontend/`): `npm run build`

Expected: build conclui sem erro. Isso é importante especificamente pra `proxy.ts` — ele roda em runtime de servidor (Node, não Edge, nesta versão do Next.js — ver `node_modules/next/dist/docs/01-app/03-api-reference/03-file-conventions/proxy.md`), e um erro de import ali só aparece no build, não no `tsc` isolado.

- [ ] **Step 6: Atualizar `PROGRESSO.md`**

Registrar: `ROLE_RULES` extraído de `proxy.ts` pra `frontend/lib/roleRules.ts`, motivo (a Task 4 — filtro de sidebar por role — precisa da mesma lógica; evita duplicação/divergência entre os dois arquivos).

- [ ] **Step 7: Commit**

```bash
git add frontend/lib/roleRules.ts frontend/proxy.ts PROGRESSO.md
git commit -m "refactor(auth): extrai ROLE_RULES de proxy.ts para lib/roleRules.ts compartilhado"
```

---

### Task 4: Sidebar não mostra itens de navegação que o usuário não pode acessar

**Contexto:** Confirmado na auditoria: `Sidebar.tsx` mostra o mesmo menu pra todos os roles — um MECANICO vê o link "Usuários" na barra lateral mesmo sabendo que vai ser bloqueado (pelo `proxy.ts`, desde a Rodada 53) ao clicar. Não é falha de segurança (o bloqueio real já existe), é UX ruim. Esta tarefa filtra os itens do menu usando a mesma fonte de verdade da Task 3.

**Files:**
- Modify: `frontend/components/layout/Sidebar.tsx:1-6` (imports), `:90-101` (pipeline de filtro `itemsWithBadges`)

**Interfaces:**
- Consumes: `regraRoleDe` de `frontend/lib/roleRules.ts` (Task 3).
- Produces: nada — última tarefa que depende de Task 3.

- [ ] **Step 1: Ler o estado atual do arquivo**

Leia `frontend/components/layout/Sidebar.tsx` inteiro antes de editar — a variável `user` (tipo `AuthUser | null`, com campo `role`) já existe (linha 71, populada no `useEffect` da linha 75-76 via `getUser()`). O pipeline de filtro atual (linhas 90-101):
```ts
const itemsWithBadges = NAV_ITEMS
  // Esconde itens de recursos não liberados no plano (enquanto carrega, oculta gated).
  .filter(item => !item.gate || (ent ? gateLiberado(item.gate, ent) : false))
  .map(item => {
    if (item.href === '/clientes' && clientesDevedores > 0)
      return { ...item, badge: clientesDevedores }
    if (item.href === '/produtos' && produtosAlerta > 0)
      return { ...item, badge: produtosAlerta }
    if (item.href === '/minhas-faturas' && faturasPendentes > 0)
      return { ...item, badge: faturasPendentes }
    return item
  })
```

- [ ] **Step 2: Importar `regraRoleDe`**

No topo do arquivo, junto dos outros imports:
```ts
import { regraRoleDe } from '@/lib/roleRules'
```

- [ ] **Step 3: Adicionar o filtro de role ao pipeline, seguindo o MESMO padrão do filtro de `gate` já existente** (esconde enquanto `user` ainda não carregou, mesma lógica conservadora já usada pro `ent`/`gate`):

```ts
const itemsWithBadges = NAV_ITEMS
  // Esconde itens de recursos não liberados no plano (enquanto carrega, oculta gated).
  .filter(item => !item.gate || (ent ? gateLiberado(item.gate, ent) : false))
  // Esconde itens cuja LEITURA já é role-restrita no backend, pro usuário
  // sem o papel certo (enquanto `user` ainda não carregou, oculta — mesmo
  // padrão conservador do filtro de `gate` acima).
  .filter(item => {
    const regra = regraRoleDe(item.href)
    return !regra || (user ? regra.roles.includes(user.role) : false)
  })
  .map(item => {
    if (item.href === '/clientes' && clientesDevedores > 0)
      return { ...item, badge: clientesDevedores }
    if (item.href === '/produtos' && produtosAlerta > 0)
      return { ...item, badge: produtosAlerta }
    if (item.href === '/minhas-faturas' && faturasPendentes > 0)
      return { ...item, badge: faturasPendentes }
    return item
  })
```

- [ ] **Step 4: Verificar tipos**

Run (do diretório `frontend/`): `npx tsc --noEmit`

Expected: nenhuma saída.

- [ ] **Step 5: Verificação manual no navegador**

Suba o dev server (`npm run dev` em `frontend/`, backend já rodando) e confirme visualmente, logado como um usuário `MECANICO`: os itens "Usuários", "Configurações", "Empresa", "Auditoria", "Emitir NF", "Histórico NF", "Relatórios", "Minhas Faturas" e "Alertas"/"Histórico Alertas" NÃO aparecem na sidebar. Logado como `ADMIN`, todos aparecem normalmente (comportamento inalterado). Se não houver acesso a um navegador nesta execução, documente esta verificação como pendente no `PROGRESSO.md` (não pule silenciosamente).

- [ ] **Step 6: Atualizar `PROGRESSO.md`**

Registrar: Sidebar agora esconde itens de navegação que o role do usuário logado não pode acessar (usa `regraRoleDe` de `lib/roleRules.ts`, mesma fonte de verdade do `proxy.ts`); resultado da verificação manual (ou a pendência, se não foi possível verificar nesta execução).

- [ ] **Step 7: Commit**

```bash
git add frontend/components/layout/Sidebar.tsx PROGRESSO.md
git commit -m "feat(sidebar): esconde itens de navegacao que o role do usuario nao pode acessar"
```

---

### Task 5: Animação de sucesso no login ("✓ Acesso liberado!")

**Contexto:** O `CLAUDE.md` especifica: "Sucesso: texto '✓ Acesso liberado!' + background `--success` por 600ms → redireciona". Confirmado na auditoria que isso nunca foi implementado — `useAuth.ts` redireciona direto (`router.push('/')`) assim que o login retorna 200, sem nenhum feedback visual de sucesso.

**Files:**
- Modify: `frontend/hooks/useAuth.ts` (função `login`, e o retorno do hook)
- Modify: `frontend/app/(auth)/login/page.tsx` (destructuring do hook e o botão de submit)

**Interfaces:**
- Consumes: nada.
- Produces: `useAuth()` passa a retornar também `success: boolean` (além de `login`, `logout`, `getUser`, `loading`, `error` que já existiam) — só a Task 5 usa isso, nenhuma outra tarefa deste plano depende.

- [ ] **Step 1: Ler o estado atual de `frontend/hooks/useAuth.ts`**

A função `login` atual:
```ts
async function login(email: string, senha: string, lembrar: boolean) {
  setLoading(true)
  setError(null)
  try {
    const { data } = await api.post('/auth/login', { email, senha })
    if (data.oficina_slug) localStorage.setItem('oficina_slug', data.oficina_slug)
    localStorage.setItem('auth_user', JSON.stringify(data.user))
    if (lembrar) localStorage.setItem('remember_email', email)
    router.push('/')
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    setError(e.response?.data?.message ?? 'Erro ao fazer login.')
    localStorage.removeItem('oficina_slug')
  } finally {
    setLoading(false)
  }
}
```
e o retorno do hook: `return { login, logout, getUser, loading, error }`.

- [ ] **Step 2: Adicionar estado `success` e o delay de 600ms**

Adicionar, junto dos outros `useState` do hook:
```ts
const [success, setSuccess] = useState(false)
```

Reescrever `login` — note que `setLoading(false)` sai do `finally` e só acontece no `catch` agora (no caminho de sucesso, o botão continua desabilitado/travado durante os 600ms e a navegação, que é o comportamento pedido pelo `CLAUDE.md`: "pointer-events: none" durante o estado de sucesso):
```ts
async function login(email: string, senha: string, lembrar: boolean) {
  setLoading(true)
  setError(null)
  try {
    const { data } = await api.post('/auth/login', { email, senha })
    if (data.oficina_slug) localStorage.setItem('oficina_slug', data.oficina_slug)
    localStorage.setItem('auth_user', JSON.stringify(data.user))
    if (lembrar) localStorage.setItem('remember_email', email)
    setSuccess(true)
    await new Promise(resolve => setTimeout(resolve, 600))
    router.push('/')
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    setError(e.response?.data?.message ?? 'Erro ao fazer login.')
    localStorage.removeItem('oficina_slug')
    setLoading(false)
  }
}
```

Atualizar o retorno do hook:
```ts
return { login, logout, getUser, loading, success, error }
```

- [ ] **Step 3: Ler o estado atual de `frontend/app/(auth)/login/page.tsx`**

Linha 7: `const { login, loading, error } = useAuth()`.
Linhas 89-98, o botão de submit:
```tsx
<button type="submit" disabled={loading}
  className="font-display"
  style={{
    width: '100%', padding: 12, borderRadius: 8,
    background: loading ? 'var(--muted)' : 'var(--accent)',
    color: '#000', fontWeight: 800, fontSize: 17, border: 'none',
    cursor: loading ? 'not-allowed' : 'pointer', marginTop: 8,
  }}>
  {loading ? '⟳ Verificando...' : 'Entrar'}
</button>
```

- [ ] **Step 4: Atualizar `LoginForm` pra consumir e exibir `success`**

Trocar a linha 7 por:
```ts
const { login, loading, success, error } = useAuth()
```

Trocar o botão (linhas 89-98) por:
```tsx
<button type="submit" disabled={loading}
  className="font-display"
  style={{
    width: '100%', padding: 12, borderRadius: 8,
    background: success ? 'var(--success)' : loading ? 'var(--muted)' : 'var(--accent)',
    color: '#000', fontWeight: 800, fontSize: 17, border: 'none',
    cursor: loading ? 'not-allowed' : 'pointer', marginTop: 8,
  }}>
  {success ? '✓ Acesso liberado!' : loading ? '⟳ Verificando...' : 'Entrar'}
</button>
```

- [ ] **Step 5: Verificar tipos**

Run (do diretório `frontend/`): `npx tsc --noEmit`

Expected: nenhuma saída.

- [ ] **Step 6: Verificação manual no navegador**

Suba o dev server e faça login com credenciais válidas — confirme que o botão fica verde com o texto "✓ Acesso liberado!" por ~600ms antes de redirecionar pro dashboard. Teste também login com credenciais inválidas — confirme que o comportamento de erro (mensagem genérica, sem indicar qual campo está errado, conforme regra de segurança do `CLAUDE.md`) continua funcionando normalmente (o `catch` não foi alterado em espírito, só ganhou `setLoading(false)` explícito).

- [ ] **Step 7: Atualizar `PROGRESSO.md`**

Registrar: animação de sucesso no login implementada (`useAuth.ts` + `login/page.tsx`), conforme spec do `CLAUDE.md`.

- [ ] **Step 8: Commit**

```bash
git add frontend/hooks/useAuth.ts "frontend/app/(auth)/login/page.tsx" PROGRESSO.md
git commit -m "feat(auth): adiciona animacao de sucesso no login antes do redirect"
```

---

### Task 6: Botão "Pré-visualizar PDF" antes de emitir Nota Fiscal

**Contexto:** O `CLAUDE.md` especifica um "Botão secundário: Pré-visualizar PDF (abre em nova aba)" na tela de emissão de NF, além do botão principal "EMITIR NOTA FISCAL". Confirmado na auditoria que só existe o botão principal — não há como ver o PDF antes de decidir emitir de verdade (o que envia a nota pra SEFAZ/ADN).

Investigação feita antes deste plano: o endpoint `GET /notas-fiscais/{id}/pdf`
(`NotaFiscalController::pdf()`) não tem nenhuma restrição de `status` — gera o
PDF pra qualquer nota, inclusive `RASCUNHO` (os templates Blade usam
`$nota->numero ?? $nota->id` e tratam `chave_acesso`/`qrcode_url` ausentes
como `null` sem quebrar — ver `NotaFiscalDocumentoService.php:96-118`). Ou
seja, dá pra gerar um PDF de pré-visualização criando a nota como
`RASCUNHO` (o mesmo primeiro passo que `emitir()` já faz hoje via
`POST /notas-fiscais`) e SEM chamar `POST /notas-fiscais/{id}/emitir` —
a nota fica como rascunho, nada é enviado à SEFAZ.

**Files:**
- Modify: `frontend/components/forms/NotaFiscalForm.tsx`

**Interfaces:**
- Consumes: nada.
- Produces: nada — última tarefa deste plano.

- [ ] **Step 1: Ler o estado atual do arquivo**

Leia `frontend/components/forms/NotaFiscalForm.tsx` inteiro (537 linhas) antes
de editar. Pontos relevantes já confirmados nesta investigação:
- Linha 79: `const [loading, setLoading] = useState(false)`.
- Linhas 134-153: função `abrirPdf(notaId, numero)` — usada hoje só
  DEPOIS de emitir de verdade, força DOWNLOAD (`a.download = ...; a.click()`).
  Não reaproveitar tal qual pro botão de preview — o `CLAUDE.md` pede "abre em
  nova aba" (visualização), não download forçado.
- Linhas 197-240: função `emitir()` — monta `payload`, faz
  `POST /notas-fiscais` (cria a nota, pegando `notaId = nf.data.data.id`),
  depois `POST /notas-fiscais/{notaId}/emitir`.
- Linhas 516-533: o botão "EMITIR NOTA FISCAL" (JSX final do formulário).

- [ ] **Step 2: Extrair a validação de `emitir()` pra uma função reutilizável**

Dentro de `emitir()`, as 3 primeiras linhas (dentro do `if`) são:
```ts
if (!clienteId) { toast('Selecione um cliente.', 'danger'); return }
if (ehVenda && itens.some(i => !i.produto_id)) { toast('Selecione um produto para todos os itens (ou remova as linhas vazias).', 'danger'); return }
if (!ehVenda && itens.every(i => !i.descricao)) { toast('Adicione pelo menos um item.', 'danger'); return }
```

Adicionar, ANTES da função `emitir()`, uma função nova:
```ts
function validarFormulario(): boolean {
  if (!clienteId) { toast('Selecione um cliente.', 'danger'); return false }
  if (ehVenda && itens.some(i => !i.produto_id)) { toast('Selecione um produto para todos os itens (ou remova as linhas vazias).', 'danger'); return false }
  if (!ehVenda && itens.every(i => !i.descricao)) { toast('Adicione pelo menos um item.', 'danger'); return false }
  return true
}
```

- [ ] **Step 3: Extrair a montagem do payload pra uma função reutilizável**

Dentro de `emitir()`, o bloco que monta `payload` (linhas 203-220) é:
```ts
const payload: Record<string, unknown> = {
  cliente_id: clienteId,
  natureza_operacao: natureza,
  forma_pagamento: formaPgto || undefined,
  observacoes: obs || undefined,
}
if (ehVenda) {
  payload.itens = itens
    .filter((i): i is ItemNF & { produto_id: string } => !!i.produto_id)
    .map(i => ({
      produto_id: i.produto_id, quantidade: i.quantidade, valor_unitario: i.valor_unitario,
    }))
  payload.forcar_nfe = forcarNfe
} else {
  payload.subtotal = subtotal
  payload.desconto = desconto
  payload.aliquota_iss = aliquota
}
```

Adicionar, logo depois de `validarFormulario()`, uma função nova:
```ts
function montarPayload(): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    cliente_id: clienteId,
    natureza_operacao: natureza,
    forma_pagamento: formaPgto || undefined,
    observacoes: obs || undefined,
  }
  if (ehVenda) {
    payload.itens = itens
      .filter((i): i is ItemNF & { produto_id: string } => !!i.produto_id)
      .map(i => ({
        produto_id: i.produto_id, quantidade: i.quantidade, valor_unitario: i.valor_unitario,
      }))
    payload.forcar_nfe = forcarNfe
  } else {
    payload.subtotal = subtotal
    payload.desconto = desconto
    payload.aliquota_iss = aliquota
  }
  return payload
}
```

- [ ] **Step 4: Reescrever `emitir()` pra usar as duas funções novas**

Substituir `emitir()` inteira por:
```ts
async function emitir() {
  if (!validarFormulario()) return
  setLoading(true)
  try {
    const payload = montarPayload()
    const nf = await api.post('/notas-fiscais', payload)
    const notaId = nf.data.data.id
    const resultado = await api.post(`/notas-fiscais/${notaId}/emitir`)
    const status = resultado.data.data.status

    if (status === 'AUTORIZADA') {
      toast(`NF #${resultado.data.data.numero} emitida com sucesso!`, 'success')
      abrirPdf(notaId, resultado.data.data.numero)
      limparFormulario()
    } else if (status === 'PROCESSANDO') {
      toast('Aguardando confirmação da SEFAZ...', 'info')
      aguardarConfirmacao(notaId)
    }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    toast(e.response?.data?.message ?? 'Erro ao emitir NF.', 'danger')
  } finally {
    setLoading(false)
  }
}
```
(Comportamento idêntico ao original — só a validação e a montagem do payload
saíram pra funções nomeadas.)

- [ ] **Step 5: Adicionar estado e a função de pré-visualização**

Junto dos outros `useState` do componente (perto da linha 84,
`const [aguardandoConfirmacao, setAguardandoConfirmacao] = useState(false)`):
```ts
const [loadingPreview, setLoadingPreview] = useState(false)
```

Depois da função `emitir()`, adicionar:
```ts
async function visualizarPdf() {
  if (!validarFormulario()) return
  setLoadingPreview(true)
  try {
    const payload = montarPayload()
    const nf = await api.post('/notas-fiscais', payload)
    const notaId = nf.data.data.id
    const res = await fetch(`${window.location.origin}/api/notas-fiscais/${notaId}/pdf`, {
      credentials: 'include',
      headers: { 'X-Tenant': localStorage.getItem('oficina_slug') ?? '' },
    })
    if (!res.ok) throw new Error()
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    window.open(url, '_blank')
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    toast(e.response?.data?.message ?? 'Erro ao gerar pré-visualização.', 'danger')
  } finally {
    setLoadingPreview(false)
  }
}
```
(Nota: propositalmente NÃO chama `URL.revokeObjectURL(url)` — a aba nova
precisa que o blob continue acessível depois que `window.open` retorna, e não
há um hook confiável de "a aba terminou de carregar" pra revogar depois. É um
pequeno vazamento de memória por clique, aceitável — mesmo padrão usado por
apps que abrem blob PDF em nova aba.)

- [ ] **Step 6: Adicionar o botão na JSX, antes do botão "EMITIR NOTA FISCAL"**

Localizar o bloco do botão principal (por volta da linha 516, mas confirme a
posição atual lendo o arquivo antes de editar):
```tsx
<button
  onClick={emitir}
  disabled={loading || aguardandoConfirmacao}
  ...
>
  {loading ? '⟳ Processando...' : aguardandoConfirmacao ? '⟳ Aguardando confirmação...' : 'EMITIR NOTA FISCAL'}
</button>
```

Adicionar IMEDIATAMENTE ANTES desse botão:
```tsx
<button
  onClick={visualizarPdf}
  disabled={loading || loadingPreview || aguardandoConfirmacao}
  className="font-display"
  style={{
    width: '100%',
    padding: 12,
    borderRadius: 10,
    background: 'transparent',
    color: 'var(--text)',
    border: '1px solid var(--border)',
    fontWeight: 700,
    fontSize: 15,
    cursor: (loading || loadingPreview || aguardandoConfirmacao) ? 'not-allowed' : 'pointer',
    marginBottom: 10,
  }}
>
  {loadingPreview ? '⟳ Gerando...' : '👁 Pré-visualizar PDF'}
</button>
```

E atualizar o `disabled` do botão principal (EMITIR) pra também considerar
`loadingPreview`, evitando os dois disparando ao mesmo tempo:
```tsx
<button
  onClick={emitir}
  disabled={loading || loadingPreview || aguardandoConfirmacao}
  ...
```

- [ ] **Step 7: Verificar tipos**

Run (do diretório `frontend/`): `npx tsc --noEmit`

Expected: nenhuma saída.

- [ ] **Step 8: Verificação manual no navegador**

Suba o dev server, abra `/fiscal/emitir`, preencha um cliente e ao menos um
item/serviço, clique em "Pré-visualizar PDF" — confirme que uma nova aba abre
com o PDF (sem chave de acesso/QR Code, já que a nota ainda é RASCUNHO) e que
NENHUMA emissão real foi disparada (confira em `/fiscal/historico` — a nota
deve aparecer como `RASCUNHO`, nunca `AUTORIZADA`/`PROCESSANDO`). Depois
clique em "EMITIR NOTA FISCAL" numa nota nova e confirme que o fluxo de
emissão de verdade continua funcionando como antes.

- [ ] **Step 9: Atualizar `PROGRESSO.md`**

Registrar: botão "Pré-visualizar PDF" implementado em `NotaFiscalForm.tsx`
(cria a nota como RASCUNHO e abre o PDF em nova aba, sem chamar `/emitir`);
`validarFormulario()`/`montarPayload()` extraídos de `emitir()` pra serem
reutilizados pelos dois botões.

- [ ] **Step 10: Commit**

```bash
git add frontend/components/forms/NotaFiscalForm.tsx PROGRESSO.md
git commit -m "feat(fiscal): adiciona botao de pre-visualizar PDF antes de emitir NF"
```

---

## Self-Review (feito ao escrever este plano)

- **Cobertura dos 5 itens pedidos:** Task 1 = Job morto; Task 2 = dependência
  spatie; Tasks 3+4 = Sidebar sem filtro de role (a Task 3 é um passo
  intermediário necessário pra não duplicar `ROLE_RULES`); Task 5 = animação
  de sucesso no login; Task 6 = botão de pré-visualizar PDF. Os 5 itens da
  mensagem do usuário estão cobertos; o item de cifra de backup foi
  deliberadamente excluído, como pedido.
- **Sem placeholders:** todo Step de código tem o código real, não descrição
  do que fazer.
- **Consistência de tipos/nomes:** `regraRoleDe`/`papelPermitido`/`RoleRule`
  usados de forma idêntica entre Task 3 (onde nascem) e Task 4 (onde são
  consumidos); `validarFormulario()`/`montarPayload()` usados de forma
  idêntica entre Task 6 Step 4 (`emitir`) e Step 5 (`visualizarPdf`).
