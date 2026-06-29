# Editar Oficina no SaaS Admin

**Data:** 2026-06-29
**Escopo:** Adicionar edição de dados da oficina (nome, plano) e do usuário admin (nome, e-mail, senha) nas páginas de listagem e detalhe do SaaS Admin.

---

## Contexto

A página `/saas-admin/oficinas/` lista as oficinas com ações de suspender/reativar, mas não permite edição dos dados. A página de detalhes `/saas-admin/oficinas/[id]/` exibe informações mas também não tem formulário de edição. O backend já possui `PUT /saas/oficinas/{id}`, mas aceita apenas `nome`, `plano_id`, `status` e `admin_email`.

---

## Solução: Modal compartilhado (Opção A)

Um único componente `EditOficinaModal` reutilizado em ambas as telas. Nenhuma nova página ou rota é criada.

---

## Componente: `EditOficinaModal`

**Arquivo:** `frontend/components/saas/EditOficinaModal.tsx`

### Interface

```ts
interface EditOficinaModalProps {
  oficina: Oficina         // dados atuais para pré-preencher
  planos: Plano[]          // lista de planos para o select
  onClose: () => void
  onSuccess: (updated: Oficina) => void
}
```

O tipo `Oficina` existente precisa incluir `admin_nome: string | null` (adicionado pelo backend).

### Campos

**Bloco "Dados da Oficina":**
- Nome* — input uppercase, obrigatório
- Plano — select com os planos disponíveis

**Bloco "Admin":**
- Nome completo — input uppercase
- E-mail* — input type=email, obrigatório
- Nova senha — input type=password, opcional; hint "deixe em branco para não alterar"; validado min:8 apenas se preenchido

### Comportamento

- Todos os campos pré-preenchidos com `oficina` recebida via props
- Payload enviado: `PUT /saas/oficinas/{id}` com apenas os campos presentes (campos vazios de senha não são enviados)
- Loading: botão mostra "⟳ Salvando…" e desabilita
- Erro: banner vermelho dentro do modal
- Sucesso: chama `onSuccess(updatedOficina)` — o pai fecha o modal e exibe o toast

---

## Integração na Listagem (`page.tsx`)

- Novo estado: `editingOficina: Oficina | null`
- Botão **"Editar"** (estilo azul `--info`, borda) adicionado à coluna de ações de cada linha, antes do link "Detalhes →"
- `planos` já são carregados na montagem — reutilizados no modal
- `onSuccess`: fecha modal → toast "Dados atualizados." → `fetchOficinas(meta.current_page)`

---

## Integração na Página de Detalhes (`[id]/page.tsx`)

- Novo estado: `showEditModal: boolean`
- Novo estado: `planos: Plano[]` (não existe hoje nessa página)
- `planos` buscados em `GET /saas/planos` na montagem, junto com `fetchOficina()`
- Botão **"Editar Dados"** adicionado ao bloco de ações do header (junto com Suspender/Reativar/Cancelar)
- `onSuccess`: fecha modal → toast "Dados atualizados." → `fetchOficina()`

---

## Backend: `PUT /saas/oficinas/{id}` expandido

**Arquivo:** `backend/app/Http/Controllers/SaaS/OficinaController.php`

### Validação adicional

```php
'admin_nome'  => 'sometimes|string|max:120',
'admin_email' => 'sometimes|email|max:120',
'admin_senha' => 'sometimes|nullable|string|min:8',
```

Nota: a unicidade de `admin_email` é validada implicitamente (o backend já persiste no model Oficina e no Usuario).

### Lógica

Após atualizar a oficina, buscar e atualizar o usuário admin:

```php
$adminUser = Usuario::withoutGlobalScopes()
    ->where('oficina_id', $id)
    ->where('role', 'ADMIN')
    ->first();

if ($adminUser) {
    if (!empty($validated['admin_nome']))  $adminUser->nome  = strtoupper($validated['admin_nome']);
    if (!empty($validated['admin_email'])) $adminUser->email = $validated['admin_email'];
    if (!empty($validated['admin_senha'])) $adminUser->senha_hash = Hash::make($validated['admin_senha']);
    $adminUser->save();
}
```

---

## Backend: `formatOficina()` — adicionar `admin_nome`

**Arquivo:** `backend/app/Http/Controllers/SaaS/OficinaController.php`

Buscar o usuário admin e incluir o nome na resposta:

```php
$adminUser = Usuario::withoutGlobalScopes()
    ->where('oficina_id', $oficina->id)
    ->where('role', 'ADMIN')
    ->select('id', 'nome')
    ->first();

// na array de retorno:
'admin_nome' => $adminUser?->nome,
```

Essa query adicional ocorre no `show()`, no `update()` e no `index()`. Para a listagem, o impacto em performance é aceitável dado o `PER_PAGE = 15`.

---

## Arquivos alterados

| Arquivo | Mudança |
|---|---|
| `frontend/components/saas/EditOficinaModal.tsx` | Novo componente |
| `frontend/app/saas-admin/(protected)/oficinas/page.tsx` | Botão Editar + estado + modal |
| `frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx` | Botão Editar Dados + planos + modal |
| `backend/app/Http/Controllers/SaaS/OficinaController.php` | `update()` expandido + `formatOficina()` com admin_nome |

---

## O que está fora do escopo

- Edição de CNPJ e slug (excluídos explicitamente pelo usuário)
- Criação de nova rota no backend (rota `PUT` existente é expandida)
- Edição de CPF do admin
