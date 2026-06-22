# Spec — Gatilhos configuráveis, aprovação de peças e link clicável do orçamento

**Data:** 2026-06-22
**Contexto:** Módulo de Alertas + Orçamento do MecânicaPro (Laravel 11 + Next.js).

Este spec cobre a **Tarefa 2** (Tarefa 1 — bug do alerta `OS_STATUS_MUDOU` — já foi corrigida em código, ver `OrdemServicoController::update` e teste `test_mudanca_de_status_dispara_alerta`).

---

## Decisões de design (já validadas)

| # | Decisão | Escolha |
|---|---------|---------|
| 1 | Escopo dos gatilhos | Genérico por entidade (organizado por entidade → evento) |
| 2 | Granularidade da condição de OS | Apenas status de **destino** (multi-seleção) |
| 3 | Convivência com pré-definidos | Filtro embutido no próprio alerta (`condicoes`); vazio = comportamento atual |
| 4 | Recusa de peça na aprovação | Marca `aprovado=false`, sai do total, **não** mexe no estoque |
| 5 | Cálculo do status do orçamento | Por **todos** os itens (serviços + peças) |
| 6 | Link do orçamento no WhatsApp | URL pública https (frontend), clicável automaticamente |

---

## Parte 1 — Modelo de dados

Nova coluna em `alerta_configs`:

- `condicoes` `jsonb` **nullable**.
- Formato genérico: mapa `campo → lista de valores aceitos`.
  - Ex. OS: `{"status_alvo": ["CANCELADA", "CONCLUIDA"]}`.
  - `null` ou `{}` ⇒ sem filtro (dispara sempre — preserva o comportamento do pré-definido `OS_STATUS_MUDOU`).
- Cast no model `AlertaConfig`: `'condicoes' => 'array'`, e adicionar `condicoes` ao `$fillable`.

Migration: `add_condicoes_to_alerta_configs` (jsonb nullable, sem default → null).

---

## Parte 2 — Dispatch (backend)

`AlertaDispatchService::dispatch(string $tipo, array $vars, array $extras)`:

- **Antes:** `->where('tipo', $tipo)->where('ativo', true)->first()` — disparava no máximo um config.
- **Depois:** busca **todos** os configs ativos daquele `tipo` (`->get()`) e, para cada um, avalia `condicoes` contra `$vars`; dispara os que casam.

Regra de casamento (`condicoesCasam(array $condicoes, array $vars): bool`):

```
para cada (campo => valoresAceitos) em condicoes:
    se valoresAceitos vazio  -> ignora o campo
    senão se (vars[campo] ?? null) NÃO está em valoresAceitos -> retorna false
retorna true
```

- `condicoes` null/vazio ⇒ retorna `true` (sempre casa).
- A comparação usa o valor da `var` correspondente (ex.: `vars['status']` contra `condicoes['status_alvo']`). O mapeamento campo-condição → chave-var é direto por convenção; para OS, `status_alvo` compara com `vars['status']`.

> O mapa `status_alvo → status` é o único par necessário no v1. Para manter genérico sem inventar campos, a avaliação trata `status_alvo` como caso especial que lê `vars['status']`; demais chaves de `condicoes` comparam com a var de mesmo nome. Isso evita acoplar a lista de status ao núcleo e mantém espaço para futuros campos.

O restante do método (canais, entitlements, montagem de alvos, render do template, logs) permanece inalterado, apenas passa a rodar dentro do loop por config.

---

## Parte 3 — UI de gatilhos (frontend `app/(dashboard)/alertas/page.tsx`)

### Agrupamento por entidade

Novo mapa estático no frontend, derivado dos `tipo` existentes:

| Entidade | Eventos (tipo) |
|----------|----------------|
| OS | OS_NOVA, OS_STATUS_MUDOU, OS_VENCIDA |
| Pagamento | PAGAMENTO_RECEBIDO, PAGAMENTO_PARCIAL |
| Nota Fiscal | NF_AUTORIZADA |
| Agendamento | AGENDAMENTO_CONFIRMADO, AGENDAMENTO_LEMBRETE |
| Estoque | ESTOQUE_BAIXO, ESTOQUE_CRITICO |
| Cliente | CLIENTE_DEVEDOR, DIVIDA_VENCIDA |
| Orçamento | ORCAMENTO_APROVADO, ORCAMENTO_RECUSADO |

### CreateModal — construtor em etapas

1. **Entidade** (select) → filtra os eventos.
2. **Evento** (select, dentro da entidade).
3. **Condição** (condicional): aparece somente quando o evento a suporta.
   - `OS_STATUS_MUDOU`: multi-seletor (checkboxes) de status-alvo: `ABERTA`, `EM_ANDAMENTO`, `AGUARDANDO_PECAS`, `CONCLUIDA`, `CANCELADA`. Nenhum marcado = qualquer status.
4. Demais campos inalterados (nome, template, telefones, canais, e-mails, enviar_cliente/mecanico).
5. No submit, envia `condicoes` (ex.: `{ status_alvo: [...] }`) quando aplicável; caso contrário omite/null.

### EditModal

- Para alertas cujo `tipo` suporta condição (`OS_STATUS_MUDOU`, incluindo o **pré-definido**), exibir o mesmo multi-seletor de status-alvo, pré-carregado de `alerta.condicoes.status_alvo`.

### Listagem

- Mostrar a condição quando existir: ex. `Gatilho: 📋 OS Mudou de Status · quando vira Cancelada, Concluída`.

### Tipos TS

- `AlertaConfig` ganha `condicoes?: { status_alvo?: string[] } | null`.

---

## Parte 4 — Validação no controller (backend `AlertaConfigController`)

- `store` e `update` aceitam `condicoes` (`array`, nullable).
  - `condicoes.status_alvo` (`array`, opcional), `condicoes.status_alvo.*` ∈ lista de status válidos de OS.
- Persistir `condicoes` no create/update.

---

## Parte 5 — Aprovação de peças (Orçamento)

### Backend `OrcamentoController`

- **`enviar`**: ao reiniciar a rodada de aprovação, zerar `aprovado=null` para **serviços e peças** (hoje só serviços).
- **`showPublico`**: o array `pecas` passa a expor `id` e `aprovado` (igual a `servicos`).
- **`responder`**:
  - Validação: `servicos_aprovados` (present, array) **e** `pecas_aprovadas` (present, array, strings).
  - Marca `aprovado` em cada serviço e em cada peça conforme a inclusão nas respectivas listas.
  - **Total** = soma dos `valor_total` dos itens aprovados (serviços **+** peças aprovadas).
  - **Status** por todos os itens aprováveis (serviços + peças):
    - `qtdAprovados == 0` → `RECUSADO` / `ORCAMENTO_RECUSADO`
    - `qtdAprovados == total de itens` → `APROVADO` / `ORCAMENTO_APROVADO`
    - caso contrário → `PARCIAL` / `ORCAMENTO_PARCIAL`
  - **Estoque:** recusa de peça **não** dispara devolução (a oficina ajusta depois removendo o item, que já devolve estoque via `removeItem`).
  - Alerta `ORCAMENTO_APROVADO`/`ORCAMENTO_RECUSADO` mantido; `servicos_aprovados` (var do template) continua listando os serviços aprovados.

### Frontend `app/orcamento/[token]/page.tsx`

- Peças viram **checkboxes** (mesmo padrão visual dos serviços), default **marcado** (`aprovado !== false`).
- `selecionados` passa a incluir IDs de peças; o "Total selecionado" soma serviços marcados **+** peças marcadas.
- No envio: `pecas_aprovadas` = IDs de peças marcadas, junto de `servicos_aprovados`.
- Remover a nota "As peças necessárias estão inclusas no serviço" e o cabeçalho passa a "Peças — selecione as que deseja aprovar".

---

## Parte 6 — Link clicável do orçamento no WhatsApp

**Causa raiz:** `OrcamentoController::enviar` monta o link com `config('app.url')` = `http://localhost:8000` (o **backend**). `/orcamento/{token}` é rota do **frontend**; além disso `localhost` não tem TLD, então o WhatsApp não transforma em link tocável. `config('app.frontend_url')` é usado pelo reset de senha mas **não está definido** em `config/app.php` (cai sempre no fallback).

**Fix:**
1. `config/app.php`: adicionar `'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000')`.
2. `.env.example`: adicionar `FRONTEND_URL=http://localhost:3000` (em produção: domínio público https, ex. `https://app.mecanicapro.com`).
3. `OrcamentoController::enviar`: trocar `config('app.url')` por `config('app.frontend_url')` na montagem do `$link`.
4. Mensagem: link em linha própria, URL "crua" com `https://` → o WhatsApp linkifica automaticamente (sem markdown).

---

## Testes

- **Dispatch (unit/feature):**
  - Config `OS_STATUS_MUDOU` com `condicoes.status_alvo=['CANCELADA']` dispara quando status vira `CANCELADA` e **não** dispara quando vira `EM_ANDAMENTO`.
  - Config sem `condicoes` dispara em qualquer status.
  - Dois configs ativos do mesmo tipo com condições diferentes: apenas o que casa dispara.
- **Aprovação de peças (feature):**
  - Cliente aprova todos os serviços e todas as peças → `APROVADO`, total = soma de tudo.
  - Aprova serviços e recusa 1 peça → `PARCIAL`, total exclui a peça recusada, estoque inalterado.
  - Recusa tudo → `RECUSADO`.
- **Link:** unit garantindo que o link usa `config('app.frontend_url')`.

> Observação de ambiente: a suíte usa PostgreSQL. Não há Postgres/Docker na máquina de dev local atual — os testes devem rodar no ambiente com DB (Docker/VPS).

---

## Fora de escopo (YAGNI)

- Transições origem→destino (apenas destino).
- Condições para entidades além de OS (mecanismo fica pronto, mas sem UI nova).
- Devolução automática de estoque na recusa de peça.
- Deduplicação automática entre o catch-all e gatilhos específicos (responsabilidade do usuário via filtro/ativação).
