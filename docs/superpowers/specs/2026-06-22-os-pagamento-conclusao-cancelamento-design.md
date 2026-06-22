# Spec — OS: conclusão/cancelamento por pagamento, devolução de estoque opcional e botões de ação

**Data:** 2026-06-22
**Contexto:** Página de detalhe da OS (`frontend/app/(dashboard)/os/[id]/page.tsx` + `components/forms/OSForm.tsx`) e `OrdemServicoController`.

## Decisões validadas

| # | Decisão | Escolha |
|---|---------|---------|
| 1 | Remover pagamento com outros restantes | Modal de cancelar só aparece ao remover o **último** (sobra 0). Restando ≥1, OS segue parcialmente paga. |
| 2 | Estoque ao cancelar | **Sempre perguntar** (modal) em qualquer cancelamento. Unifica Tarefa 1 e 2. |
| 3 | Modal "concluir?" após pagamento | Aparece **sempre**; conclui mesmo com saldo devedor. |

## Parte 1 — Backend

`OrdemServicoController::update`:
- Aceitar `devolver_estoque` (`sometimes`, `boolean`).
- No cancelamento (`novoStatus === 'CANCELADA'` e status anterior ≠ CANCELADA), só chamar `estoqueService->devolverEstoqueOs($os)` quando `devolver_estoque` for `true`. Se o campo **não** vier na requisição, manter o comportamento atual (devolver) para compatibilidade.
- Nada mais muda (alerta `OS_STATUS_MUDOU`, recálculo de cliente, NPS na conclusão permanecem).

Sem endpoints novos: conclusão = `PUT /os/{id} {status:'CONCLUIDA'}`; cancelamento = `PUT /os/{id} {status:'CANCELADA', devolver_estoque:bool}`.

## Parte 2 — Frontend: handlers e modais (página `os/[id]`)

Handlers reutilizáveis:
- `concluirOS()` → `PUT /os/{id} {status:'CONCLUIDA'}` → toast + `fetchOs()`.
- `cancelarOS(devolverEstoque: boolean)` → `PUT /os/{id} {status:'CANCELADA', devolver_estoque}` → toast + `fetchOs()`.

Modais (padrão visual do modal `confirmOrc` já existente):
- **Concluir OS?** — Sim/Não.
- **Cancelar OS?** — inclui checkbox "Devolver o estoque das peças desta OS" (default: marcado); botões Voltar/Confirmar cancelamento.

Estado: `confirmConcluir: boolean`, `confirmCancelar: boolean`, `devolverEstoque: boolean`.

## Parte 3 — Tarefa 1: fluxo de pagamento

- `handleAddPagamento`: após sucesso → `setConfirmConcluir(true)`. Confirmar → `concluirOS()`.
- `handleRemovePagamento(pagId)`: após sucesso → se o nº de pagamentos restantes (`pagamentos.length - 1`) for **0** → `setConfirmCancelar(true)`; confirmar → `cancelarOS(devolverEstoque)`. Se restar ≥1 → apenas `fetchOs()` (parcial).

## Parte 4 — Tarefa 2: cancelar sempre pergunta estoque

- O modal **Cancelar OS?** com a escolha de estoque é o caminho único de cancelamento, compartilhado pela remoção do último pagamento e pelo botão Cancelar OS.

## Parte 5 — Tarefa 3: botões no `OSForm`

- `OSForm` recebe props opcionais `onConcluir?: () => void` e `onCancelar?: () => void`.
- No rodapé (modo edição), ao lado de **Atualizar OS**:
  - **Concluir OS** (verde) — visível se `status` ∉ {CONCLUIDA, CANCELADA}.
  - **Cancelar OS** (vermelho) — visível se `status` ≠ CANCELADA.
- `type="button"` (não submetem o form). Disparam os callbacks, que abrem os modais na página.
- A página passa `onConcluir={() => setConfirmConcluir(true)}` e `onCancelar={() => setConfirmCancelar(true)}`.

## Testes

- **Backend (feature):**
  - `PUT status=CANCELADA, devolver_estoque=true` devolve o estoque das peças.
  - `PUT status=CANCELADA, devolver_estoque=false` **não** devolve o estoque.
  - (compat) `PUT status=CANCELADA` sem o campo devolve o estoque.
- Frontend: verificação manual do fluxo (pagamento→concluir; remover último→cancelar; botões).

> Ambiente: testes usam PostgreSQL; rodar no ambiente com DB (Docker/VPS).

## Fora de escopo (YAGNI)

- Remover CANCELADA/CONCLUIDA do dropdown de Status (mantido; caminho recomendado são os botões).
- Status de "pagamento parcial" como campo próprio — "parcial" = OS aberta com saldo devedor (estado natural).
