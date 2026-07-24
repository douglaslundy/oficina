# Log de notificações visualizadas (leitura por oficina/usuário/IP) — design

## Contexto

Pedido do usuário: uma página que mostre todas as notificações que as
oficinas abriram/leram, quem leu, e — para notificações que se repetem por
vários dias — um toggle que revela o histórico completo (usuário, data, IP)
como um log de auditoria. Escopo: notificações criadas pelo admin
manualmente **e** as geradas pelo motor de cobrança.

Investigação prévia (mesma sessão): nenhuma das duas fontes registra hoje
quem visualizou. `Notificacao` (broadcast manual do admin) controla
"quantas vezes já apareceu" inteiramente em `localStorage` do navegador —
zero rastro no servidor. O alerta de cobrança (`AssinaturaAlertaModal` /
`AssinaturaAlertaService::status()`) já tem um throttle server-side
(`oficinas.alerta_cobranca_exibicoes_hoje`), mas é um contador agregado por
oficina, não um log — não sabe quem, nem quando exatamente, nem o IP.

Decisões confirmadas com o usuário antes desta spec:
1. Cobrem o log tanto o alerta de cobrança (`AssinaturaAlertaModal`) quanto
   toda notificação manual criada em `/saas-admin/notificacoes`.
2. A página fica em SaaS Admin (única visão que cruza dados de todas as
   oficinas — cada oficina é isolada por `oficina_id`, SaaS admin é o único
   papel que já opera cross-tenant).
3. Para as notificações manuais, o throttle de exibição
   (`vezes_dia`/`intervalo_minutos`) migra do `localStorage` para o
   backend, usando a nova tabela de log como fonte de verdade.

## A) Modelo de dados

Nova tabela **`notificacao_visualizacoes`** (banco compartilhado — mesma
convenção de `notificacoes`/`oficinas`, não usa `HasTenantScope`, porque a
consulta cross-oficina é o próprio propósito da tabela):

```
id                 uuid PK
tipo               varchar(10)   -- 'MANUAL' | 'COBRANCA'
notificacao_id     uuid nullable -- FK notificacoes.id, só quando tipo=MANUAL
cobranca_id        uuid nullable -- FK cobrancas.id, só quando tipo=COBRANCA
titulo             varchar(150)  -- snapshot do que foi mostrado
mensagem           text          -- snapshot (texto da manual, ou a mensagem
                                  --   já formatada do alerta de cobrança)
oficina_id         uuid FK oficinas.id
usuario_id         uuid nullable FK usuarios.id
ip                 varchar(45)   -- IPv4/IPv6
user_agent         text nullable
visualizado_em     timestamptz default now()

índices: (tipo, notificacao_id), (tipo, cobranca_id, oficina_id),
         (oficina_id), (visualizado_em)
```

Snapshot de `titulo`/`mensagem` é **[decisão]**: um log de auditoria deve
mostrar o que foi exibido *naquele momento*, não re-renderizar a partir do
estado atual — o admin pode editar/apagar a `Notificacao` depois, e a
mensagem da cobrança muda de fase (disponível → vencida) ao longo do tempo.

**Pré-requisito — `TrustProxies`:** o Laravel não confia em nenhum proxy
hoje; atrás do Traefik, `$request->ip()` sempre devolveria o IP interno do
container, não o do usuário. Como o backend não é exposto diretamente (só
o Traefik tem porta publicada), configurar
`Request::setTrustedProxies(['*'], ...)` é seguro nesta topologia
(padrão comum para app atrás de um único reverse proxy). Sem isso o campo
IP do log fica sem valor nenhum — é bloqueante para a feature em si.

## B) Notificações manuais — mudança de fonte de verdade

- Novo endpoint tenant `POST /notificacoes/{id}/visualizar`
  (`['tenant','auth:sanctum']`, sem restrição de role — mesmo padrão de
  `notificacoes/ativas`). Grava uma linha `tipo=MANUAL` com
  `oficina_id` (via `TenancyContext`), `usuario_id` (`auth()->id()`),
  `ip`/`user_agent` da request, snapshot de título/texto.
- `NotificacaoController::ativas()` (endpoint existente) passa a filtrar
  elegibilidade consultando `notificacao_visualizacoes` em vez de confiar
  no filtro client-side: conta visualizações de hoje (`vezes_dia`) e a mais
  recente (`intervalo_minutos`), agrupado por `(notificacao_id, oficina_id)`
  — **throttle por oficina, não por usuário individual** (mesmo espírito
  do design atual: não repetir o mesmo aviso pra equipe toda várias vezes).
- `frontend/components/NotificacaoModal.tsx`: `onFechar` passa a chamar
  `POST /notificacoes/{id}/visualizar` em vez de só gravar no
  `localStorage`. A função `elegivel()` client-side é removida — o backend
  já devolve só notificações elegíveis em `ativas()`.

## C) Alerta de cobrança — só adiciona o log, não toca no throttle

`AssinaturaAlertaService::status()` mantém sua lógica de elegibilidade
exatamente como está (`podeExibirHoje`/`registrarExibicao`, contador em
`oficinas.alerta_cobranca_exibicoes_hoje`) — é caminho crítico de cobrança
já em produção, sem motivo pra mexer. Logo depois de `registrarExibicao()`,
quando `show=true`, adiciona uma chamada que grava a linha
`tipo=COBRANCA` (`cobranca_id`, `oficina_id`, `usuario_id=auth()->id()`,
`ip`, `user_agent`, snapshot `fase`+`mensagem`).

**Fora de escopo**: `statusBloqueio()` (tela `/bloqueado`) não loga — é uma
tela de bloqueio total, não uma notificação dispensável. Pode entrar depois
se o usuário pedir.

## D) SaaS Admin — endpoints novos

- `GET /saas/notificacoes/{id}/log` — paginado, linhas de
  `notificacao_visualizacoes` (tipo=MANUAL) daquele `notificacao_id`, com
  join em `oficinas`/`usuarios` pra nome. Resumo (`GET /saas/notificacoes`
  existente ganha `total_visualizacoes`/`oficinas_distintas` por linha via
  subquery, sem quebrar o contrato atual — só campos novos).
- `GET /saas/notificacoes/cobranca` — lista agrupada por
  `(oficina_id, cobranca_id)`: oficina, valor, vencimento, fase mais
  recente, contagem de exibições.
- `GET /saas/notificacoes/cobranca/log?oficina_id=&cobranca_id=` —
  paginado, linhas individuais (usuário, data/hora, IP, fase naquele
  momento).

## E) Frontend — `/saas-admin/notificacoes`

Duas abas na mesma página (substitui a tabela única atual):

- **Manuais** — tabela CRUD existente + coluna "Leituras" (total +
  nº de oficinas distintas) + botão de toggle por linha que expande um
  log paginado (Oficina · Usuário · Data/Hora · IP) abaixo da linha.
- **Cobrança** (nova, somente leitura — não tem CRUD porque não é
  criada pelo admin) — tabela agrupada (Oficina · Valor · Vencimento ·
  Fase · Nº de exibições) com o mesmo padrão de toggle/log.

## Fora de escopo (registrar para o futuro, não implementar agora)

- Purga/retenção automática de linhas antigas do log (alerta de cobrança
  pode gerar muitas linhas ao longo de meses numa fatura vencida há tempo
  — não é um problema imediato, mas vale revisitar se o volume incomodar).
- Log da tela `/bloqueado`.
- Throttle por usuário individual (hoje fica por oficina, como já era).
