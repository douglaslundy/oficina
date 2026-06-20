# Catálogo de Serviços — Design Spec

**Data:** 2026-06-20
**Status:** Aprovado

---

## Objetivo

Permitir que a oficina cadastre um catálogo de serviços reutilizáveis (nome + valor padrão). Ao criar ou editar uma OS, o usuário pode selecionar um serviço do catálogo — que preenche automaticamente a descrição e o valor — ou digitar manualmente caso o serviço não esteja cadastrado.

---

## Banco de Dados

### Tabela `servicos`

```sql
CREATE TABLE servicos (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  nome          VARCHAR(120) NOT NULL,
  valor_padrao  NUMERIC(10,2) NOT NULL DEFAULT 0,
  ativo         BOOLEAN NOT NULL DEFAULT TRUE,
  oficina_id    UUID NOT NULL REFERENCES oficinas(id),
  criado_em     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

- Escopo multi-tenant via `oficina_id` (trait `HasTenantScope` igual a `Produto`)
- Sem soft-delete: desativar = `ativo = false`
- Sem `updated_at` (padrão do projeto — ver `produtos`)

---

## Backend

### Model `Servico`

- `$fillable`: `nome`, `valor_padrao`, `ativo`, `oficina_id`
- `$casts`: `ativo => boolean`, `valor_padrao => float`, `criado_em => datetime`
- Traits: `HasTenantScope`
- UUID auto-gerado no `boot()` (padrão do projeto)

### Migration

Arquivo: `2026_06_20_000001_create_servicos_table.php`

### `ServicoController`

| Método | Rota | Role | Descrição |
|--------|------|------|-----------|
| `index` | `GET /servicos` | todos autenticados | Lista serviços. Suporta `?ativo=1` para filtrar apenas ativos. Retorna `id, nome, valor_padrao, ativo`. |
| `store` | `POST /servicos` | ADMIN, ATENDENTE | Cria serviço. Valida: `nome` required string max:120, `valor_padrao` numeric min:0. |
| `update` | `PUT /servicos/{id}` | ADMIN, ATENDENTE | Edita nome, valor_padrao ou ativo. Mesmas validações do store. |
| `destroy` | `DELETE /servicos/{id}` | ADMIN | Desativa (`ativo = false`). Não apaga registro para preservar histórico de OS. |

### Rotas em `api.php`

```php
// Serviços — leitura: todos; escrita: ADMIN, ATENDENTE; desativar: ADMIN
Route::middleware(['tenant', 'auth:sanctum'])->group(function () {
    Route::get('servicos', [ServicoController::class, 'index']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])->group(function () {
    Route::post('servicos',         [ServicoController::class, 'store']);
    Route::put('servicos/{id}',     [ServicoController::class, 'update']);
});
Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::delete('servicos/{id}',  [ServicoController::class, 'destroy']);
});
```

---

## Frontend

### Página `/servicos`

**Arquivo:** `frontend/app/(dashboard)/servicos/page.tsx`

- Tabela com colunas: **Nome · Valor padrão · Status · Ações**
- Status: pill verde (Ativo) / vermelho (Inativo)
- Ações: botão Editar + botão Desativar (com confirm inline, igual ao padrão de planos)
- Botão "Novo Serviço" no canto superior direito → abre `ServicoModal`
- Loading skeleton e empty state
- Reativa serviço desativado via botão "Reativar" (chama `PUT /servicos/{id}` com `ativo: true`)

**`ServicoModal`** — modal inline na própria página (padrão estabelecido em saas-admin/planos):
- Campos: Nome (required), Valor padrão R$ (required, number step 0.01)
- Botão Cancelar + Salvar/Criar
- Exibe erro inline se API retornar falha

### Sidebar

Adicionar entrada em `NAV_ITEMS` em `Sidebar.tsx`:
```ts
{ href: '/servicos', label: 'Serviços', icon: '🛠️' }
```
Posição: entre `/produtos` e `/os`.

### OSForm — integração na OS

**Afeta dois locais:**

#### 1. `NewItemInline` (modo edição de OS existente)

Quando `tipo === 'SERVICO'`:
- Substituir o `<input type="text">` de descrição por um `<select>` com os serviços ativos buscados em `GET /servicos?ativo=1`
- Última opção do select: `value="__manual__"` com label `"✏️ Outro (digitar manualmente)"`
- Se `__manual__` selecionado: renderiza `<input type="text">` em vez do select
- Ao selecionar um serviço do catálogo: preenche `descricao` com `servico.nome` e `valorUnitario` com `servico.valor_padrao`
- Se `tipo === 'PECA'`: comportamento atual (select de produtos) inalterado

#### 2. Campos inline no modo **nova OS** (`useFieldArray`)

Para cada field com `tipo === 'SERVICO'`, o campo `descricao` segue a mesma lógica:
- Select de serviços + opção `__manual__`
- Ao selecionar serviço: preenche `descricao` e `valor_unitario` do field correspondente
- Estado local por field: `manualFields: Set<number>` — indica quais fields estão em modo manual

---

## Fluxo de Seleção (UX)

```
Usuário clica "+ Serviço"
       ↓
Select aparece com lista de serviços ativos
       ↓
   ┌───────────────────────────────────┐
   │ Seleciona serviço do catálogo     │ → preenche nome + valor automaticamente
   │                                   │   → usuário confirma ou ajusta valor
   └───────────────────────────────────┘
   ┌───────────────────────────────────┐
   │ Seleciona "✏️ Outro (manual)"     │ → select vira input de texto livre
   │                                   │   → usuário digita descrição e valor
   └───────────────────────────────────┘
```

---

## O que NÃO está no escopo

- Categorias de serviço (pode ser adicionado futuramente)
- Tempo estimado por serviço
- Relatório de serviços mais realizados
- Vinculação retroativa de itens de OS existentes ao catálogo

---

## Ordem de Implementação

1. Migration + Model `Servico`
2. `ServicoController` + rotas
3. Página `/servicos` + `ServicoModal`
4. Entrada na Sidebar
5. Integração no `NewItemInline` (edição de OS)
6. Integração nos campos inline de nova OS (`useFieldArray`)
7. Build, push e deploy
