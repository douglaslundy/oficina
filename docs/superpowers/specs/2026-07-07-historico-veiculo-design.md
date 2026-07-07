# Histórico por Veículo — Design

## Contexto e motivação

Hoje já existe uma entidade `Veiculo` própria (migrada de campos soltos em `clientes`), com CRUD e
suporte a múltiplos veículos por cliente. Mas duas lacunas impedem qualquer noção de "histórico do
veículo":

1. **O vínculo OS↔Veículo não é persistido.** O frontend (`OSForm.tsx`) já coleta `veiculo_id` ao criar
   uma OS, mas `OrdemServicoController::store` não valida nem grava esse campo — só salva
   `veiculo_descricao`/`veiculo_placa` como texto livre. Não há como consultar "todas as OS de um
   veículo específico".
2. **Não existe conceito de troca de dono.** `veiculos.cliente_id` é fixado na criação;
   `VeiculoController::update` não permite alterá-lo. Se um carro muda de dono, hoje não há como
   registrar isso no sistema.

Pedido: uma tela de consulta onde o usuário digita a placa, abre o veículo e vê o histórico completo —
quem foram os donos e quais serviços (OS) o veículo já teve.

## Decisões (aprovadas com o usuário)

- **Transferência de propriedade é real**, não só leitura do dono atual: o sistema passa a suportar
  transferir um veículo para outro cliente, mantendo o histórico de cada período de propriedade.
- **Busca por placa via autocomplete** (busca parcial, conforme digita), não busca+submit.
- **Mesmo nível de acesso de Clientes/OS** (leitura liberada a todos os roles autenticados; escrita —
  cadastro/edição/transferência — restrita a `ADMIN,ATENDENTE`, mesmo padrão já usado nas rotas de
  `clientes`/`veiculos` existentes).

## Modelo de dados

### `veiculo_proprietarios` (nova)
```sql
CREATE TABLE veiculo_proprietarios (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  veiculo_id UUID NOT NULL REFERENCES veiculos(id) ON DELETE CASCADE,
  cliente_id UUID NOT NULL REFERENCES clientes(id),
  oficina_id UUID NOT NULL REFERENCES oficinas(id),
  data_inicio TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  data_fim TIMESTAMPTZ NULL          -- NULL = proprietário atual
);
-- índice (veiculo_id, data_fim) para achar rápido o período aberto
```

- Migração de backfill: para cada `veiculos` existente, insere 1 linha
  (`cliente_id = veiculos.cliente_id`, `data_inicio = veiculos.criado_em`, `data_fim = NULL`).
- `Veiculo::store()` (em `VeiculoController::store`) passa a criar, na mesma transação, o registro
  inicial de propriedade.

### `ordens_servico` (sem alteração de schema)
- A coluna `veiculo_id` já existe (migração `2026_06_01_100002`), só não é usada. Não precisa de nova
  migração — é correção de código (ver seção Backend).

### Validação de placa duplicada
- Em `VeiculoController::store`, se já existir outro veículo **ativo** (`ativo = true`) da mesma
  oficina com a mesma placa (normalizando maiúsculas e removendo hífen/espaço), bloquear com `422` e
  mensagem: "Já existe um veículo cadastrado com esta placa. Use a opção Transferir no veículo
  existente para trocar o proprietário." Placas nulas/vazias não entram nessa checagem (continuam
  permitidas múltiplas, como hoje).

## Backend

### Corrigir o vínculo OS↔Veículo
- `OrdemServico::$fillable` ganha `veiculo_id`.
- `OrdemServicoController::store`: adicionar `'veiculo_id' => ['nullable', 'string', 'exists:veiculos,id']`
  na validação e garantir que é persistido (já vem no payload do frontend).
- `OrdemServicoController::index`: novo filtro `if ($request->has('veiculo_id')) { $query->where('veiculo_id', $request->veiculo_id); }`.
- `OrdemServicoResource`: incluir `'veiculo_id' => $this->veiculo_id`.

### `VeiculoController` (alterações)
- `store`: checagem de placa duplicada (acima) + criação do registro em `veiculo_proprietarios`.
- Novo método `buscar(Request $request)`: `GET /veiculos/busca?placa=...`. Busca `ilike` parcial,
  normalizando a placa da query (remove hífen/espaço, uppercase) contra `placa` normalizada no banco
  (`REPLACE(UPPER(placa), '-', '')`). Limita a 10 resultados. Retorna
  `[{id, placa, modelo, ano, ativo, cliente_id, cliente_nome}]`, ordenado por `criado_em desc`.
- Novo método `show(string $id)`: `GET /veiculos/{id}`. Retorna:
  - dados do veículo (`modelo`, `ano`, `placa`, `chassi`, `ativo`)
  - `proprietario_atual`: `{id, nome, telefone}` (via `veiculo_proprietarios` com `data_fim IS NULL`,
    fallback para `veiculos.cliente_id` se por algum motivo não houver período aberto)
  - `historico_proprietarios`: lista de `{cliente_id, cliente_nome, data_inicio, data_fim}` ordenada
    por `data_inicio desc`
  - `historico_os`: lista de OS com `veiculo_id = id` (número, data, status, valor_total, valor_pago,
    mecânico, resumo dos itens), ordenada por `criado_em desc`
  - `resumo`: `{total_os, valor_total_gasto, ultima_visita}` — `total_os` conta todas exceto
    `CANCELADA`; `valor_total_gasto` soma `valor_pago` das OS não canceladas (dinheiro efetivamente
    recebido, não o valor bruto); `ultima_visita` é a data (`criado_em`) da OS não cancelada mais
    recente
- Novo método `transferir(Request $request, string $id)`: `POST /veiculos/{id}/transferir`. Payload
  `{novo_cliente_id}`. Em `DB::transaction`:
  1. Valida que `novo_cliente_id` existe e é diferente do `cliente_id` atual do veículo (senão `422`).
  2. Fecha o período aberto em `veiculo_proprietarios` (`data_fim = now()`).
  3. Atualiza `veiculos.cliente_id = novo_cliente_id`.
  4. Cria novo período (`cliente_id = novo_cliente_id`, `data_inicio = now()`, `data_fim = null`).
  - OS já existentes **não são alteradas** — continuam apontando para o `cliente_id` que era dono na
    época, preservando a integridade financeira/histórica de cada OS.

### Rotas (`routes/api.php`)
```php
// leitura — todos os roles (mesmo grupo de clientes/produtos)
Route::get('veiculos/busca',  [VeiculoController::class, 'buscar']);
Route::get('veiculos/{id}',   [VeiculoController::class, 'show']);

// escrita — role:ADMIN,ATENDENTE (mesmo grupo já existente em torno de veiculos)
Route::post('veiculos/{id}/transferir', [VeiculoController::class, 'transferir']);
```

## Frontend

### Nova rota `/veiculos`
- Campo de busca com autocomplete (debounce ~300ms) chamando `GET /veiculos/busca?placa=`. Lista os
  resultados (placa, modelo/ano, nome do cliente atual); clicar navega para `/veiculos/[id]`.
- Item novo na `Sidebar.tsx`: `{ href: '/veiculos', label: 'Veículos', icon: '🚗' }`, posicionado perto
  de "Clientes".

### Nova rota `/veiculos/[id]`
- Segue o design system do protótipo (stat cards com barra colorida no topo, pills de status,
  `JetBrains Mono` para placa/valores):
  - Cabeçalho: modelo/ano/placa + pill "Ativo/Inativo".
  - Card "Proprietário atual": nome (link para `/clientes/[id]`), telefone; botão "Transferir
    Proprietário" (só ADMIN/ATENDENTE) abrindo modal com busca de cliente (reaproveita o mesmo padrão
    de busca de cliente já usado em `OSForm.tsx`).
  - Card "Histórico de Proprietários": lista cliente + período (`dd/mm/aaaa` → `dd/mm/aaaa` ou "atual").
  - Tabela "Histórico de OS": mesmas colunas/estilo da tabela "Histórico de OS" já existente em
    `clientes/[id]/page.tsx` (número, data, status com `StatusPill`, valor, mecânico).
  - 3 stat cards de resumo: Total de OS, Valor total gasto, Última visita.

### `clientes/[id]/page.tsx`
- Cada linha da lista de veículos ganha um link "Ver histórico completo →" apontando para
  `/veiculos/[id]`.

## Fora de escopo
- Não altera os campos legados `clientes.veiculo_modelo/ano/placa` (mantidos por compatibilidade).
- Não adiciona edição/exclusão de um período de propriedade específico (só o fluxo forward de
  transferência); correção de erro de cadastro continua sendo feita editando o veículo diretamente.
- Não faz merge/deduplicação automática de veículos já duplicados que existirem hoje na base — a
  validação de placa duplicada só previne casos novos daqui pra frente.
- Não altera o fluxo de criação de OS além de persistir `veiculo_id` (o campo já é coletado hoje).
