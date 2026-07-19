# Suspensão automática + página de bloqueio + voto de confiança — design

## Contexto

Quarto e último subsistema do pedido original. Depende do motor de cobrança
recorrente e do alerta de cobrança (ambos completos e deployados). Pedido do
usuário: oficina com fatura vencida há mais de X dias (já configurável desde
o motor de cobrança — `dias_suspensao_vencido`/`cobranca_dias_suspensao_padrao`,
só faltava o job que realmente suspende) é bloqueada automaticamente; reativada
automaticamente ao pagar; ao tentar usar o sistema suspensa, cai numa página de
bloqueio com PIX/cartão; "voto de confiança" (liberação temporária) concedível
pelo admin da plataforma (sem restrição) ou pela própria oficina (self-service,
uma vez por fatura).

Escrita e executada de forma autônoma ("faça, avise quando finalizado").
Decisões de design sem consenso explícito estão marcadas **[decisão]**.

## A) Modelo de dados

**`saas_config`**: `voto_confianca_dias` INTEGER DEFAULT 3 — dias de liberação
por voto de confiança, configurável globalmente.

**`oficinas`**: `voto_confianca_ate` DATE NULLABLE — enquanto no futuro, a
oficina não é suspensa mesmo com fatura vencida há mais dias que o limite
configurado (é a "carência extra" concedida).

**`cobrancas`**: `voto_confianca_usado_em` TIMESTAMP NULLABLE — marca que a
fatura (cobrança `ASSINATURA` `VENCIDA` específica) já teve um voto de
confiança concedido. **[decisão]**: essa trava vale independente de quem
concedeu (admin ou self-service) — evita empilhar votos na mesma fatura — mas
só o caminho self-service do tenant é bloqueado por ela; o admin da
plataforma pode conceder de novo a qualquer momento (equivalente ao padrão já
usado em `mudarCiclo`: ação do tenant é guardada, ação do admin não).

## B) Suspensão automática (job)

Novo método `CobrancaRecorrenteService::suspenderVencidas(): int`, chamado
como 3º passo do comando diário `cobrancas:gerar` (já existe, mesma cadência
— não cria agendamento novo):

```
para cada oficina em (ATIVA, INADIMPLENTE):
    cobranca = Cobranca mais recente (tipo=ASSINATURA, status=VENCIDA) da oficina
    se não existe: pula
    diasVencida = dias desde cobranca.vencimento
    diasSuspensao = oficina.dias_suspensao_vencido ?? saas_config.cobranca_dias_suspensao_padrao
    se diasVencida < diasSuspensao: pula
    se oficina.voto_confianca_ate existe e está no futuro: pula (carência ativa)
    oficina.status = SUSPENSA
```

Reativação ao pagar: **já funciona sem mudança nenhuma** —
`WebhookController::reconciliarPagamento()` já reativa qualquer oficina com
`status !== 'ATIVA'` ao confirmar pagamento de uma cobrança `ASSINATURA`
(código existente, não tocado nesta spec). Só adiciono limpar
`voto_confianca_ate` nesse mesmo ponto, por higiene (evita uma data de
carência futura ficando órfã numa oficina que já pagou e pode passar por um
novo ciclo de cobrança antes dela expirar).

## C) Middleware — rotas que continuam acessíveis mesmo suspensa

`InitializeTenancyByHeader` já bloqueia (403) toda a API quando
`oficina.status === SUSPENSA` (comportamento correto e mantido — é o bloqueio
de verdade). Duas rotas novas precisam ser exceção, porque são exatamente as
ações que a página de bloqueio oferece: consultar a fatura em aberto e pedir
voto de confiança. **[decisão]**: a exceção é feita checando `$request->path()`
contra uma lista fixa (`api/assinatura/status-bloqueio`,
`api/assinatura/voto-confianca`) — todas as outras rotas (incluindo
`assinatura/alerta` e `assinatura/mudar-ciclo`) continuam bloqueadas.

A resposta 403 de "suspensa" ganha um campo `code: 'OFICINA_SUSPENSA'`
(hoje só tem `message`) — é o que o frontend usa pra saber que deve
redirecionar pra página de bloqueio em vez de só mostrar um erro genérico.

## D) Endpoints novos

- `GET /assinatura/status-bloqueio` (tenant, sem restrição de role, **exceção
  do bloqueio SUSPENSA**) — retorna `{suspensa, fase, mensagem, valor,
  vencimento, link_pagamento, voto_confianca_disponivel}`. Sem lógica de
  throttle (ao contrário de `GET /assinatura/alerta`) — a página de bloqueio
  não é um lembrete dispensável, tem que mostrar a informação completa
  sempre que chamada.
- `POST /assinatura/voto-confianca` (tenant, `role:ADMIN`, **exceção do
  bloqueio SUSPENSA**) — valida que a oficina está `SUSPENSA`, que existe
  cobrança `VENCIDA` e que ela ainda não teve voto de confiança usado; se
  ok, libera (`oficina.status = ATIVA`, `voto_confianca_ate = hoje +
  voto_confianca_dias`) e marca `cobranca.voto_confianca_usado_em`.
- `POST /saas/oficinas/{id}/voto-confianca` (SaaS admin, sem restrição
  nenhuma) — mesma liberação, chamável a qualquer momento independente do
  histórico de uso da fatura.

## E) Frontend

- **Interceptor** (`lib/api.ts`): resposta 403 com `code === 'OFICINA_SUSPENSA'`
  redireciona pra `/bloqueado` (mesmo padrão já usado pro redirect de 401 →
  `/login`).
- **Página `/bloqueado`** (fora do grupo `(dashboard)`, sem sidebar/topbar —
  tela cheia): busca `GET /assinatura/status-bloqueio` ao montar; se
  `suspensa === false`, redireciona pra `/` (já foi resolvido, ou chegou aqui
  por engano); senão mostra a mensagem + valor + botões PIX/Cartão (mesmo
  `link_pagamento`, mesmo padrão do modal de alerta) + botão "Deseja liberar
  seu acesso em voto de confiança?" (só `role === 'ADMIN'`, só se
  `voto_confianca_disponivel`). Ao conceder, troca o botão por "Seu acesso
  foi liberado por N dias em voto de confiança." + botão pra voltar ao
  sistema. Se `voto_confianca_disponivel === false`, mostra nota explicando
  que já foi usado nesta fatura, sem botão.
- **SaaS Admin → Oficinas** (lista): botão "Voto de Confiança" na linha,
  visível só quando `status === SUSPENSA`, mesmo padrão de confirmação
  inline já usado pra Suspender/Reativar.
- **SaaS Admin → Configurações**: campo `voto_confianca_dias` na seção
  "Cobrança Recorrente" já existente.

## Fora de escopo

- Qualquer fluxo nativo de PIX/cartão (mesma decisão da spec anterior — os
  botões abrem o checkout hospedado do gateway).
- Notificar a oficina (e-mail/WhatsApp) no momento da suspensão — o pedido
  original só menciona a página de bloqueio; alertas de canal externo
  continuam fora do escopo desta spec.
