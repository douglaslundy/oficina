# Alerta de cobrança + modal de pagamento — design

## Contexto

Terceiro subsistema do pedido original (item 3), depende do motor de cobrança
recorrente (já completo — `docs/superpowers/plans/2026-07-18-motor-cobranca-recorrente.md`).
Pedido do usuário: quando existe uma cobrança pendente/vencida para a oficina,
mostrar um modal no próprio sistema (não WhatsApp/e-mail) avisando, com botões
de pagamento e um CTA para trocar pra assinatura anual com desconto. O modelo
desse alerta é definido pela plataforma (SaaS admin), só a frequência diária e
por quantos dias ele aparece são editáveis, não pode ser deletado pela oficina,
e liga/desliga sozinho conforme o estado do pagamento.

Esta spec foi escrita e é executada de forma autônoma (usuário pediu "faça e
avise quando finalizado") — decisões de design sem consenso explícito do
usuário estão marcadas como **[decisão]** com a justificativa, pra revisão
posterior se necessário.

## Por que não reaproveitar `Notificacao` nem `AlertaConfig`

Investigado antes de desenhar (ver relatório da sessão): `Notificacao` é um
broadcaster de anúncios manual (admin liga/desliga, sem lógica condicional,
deletável, estado de exibição só em `localStorage` do navegador — não dá pra
saber server-side quantas vezes já apareceu). `AlertaConfig`/`AlertaLog` é um
pipeline de disparo externo (só WhatsApp/e-mail, editável pela própria
oficina, log é só histórico de envio sem estado de leitura/dispensa). Nenhum
dos dois sustenta "não pode ser deletado + liga/desliga automático por estado
de cobrança + renderizado como modal in-app com estado de exibição
server-side". Construir um sistema novo, pequeno e dedicado.

## A) Modelo de dados

**`saas_config`** (2 colunas novas — mesmo padrão singleton já usado pra
`desconto_anual_pct` etc.):
- `alerta_cobranca_vezes_dia` INTEGER DEFAULT 1 — quantas vezes por dia o
  modal pode aparecer pra uma oficina enquanto a condição estiver ativa.
- `alerta_cobranca_dias_exibicao` INTEGER DEFAULT 30 — **[decisão]** limite de
  dias de exibição automática *só na fase "disponível para pagamento"* (antes
  do vencimento). Uma vez **vencida**, o alerta aparece todo santo dia
  (respeitando `vezes_dia`) até o pagamento — porque nesse ponto é
  informação crítica (risco de suspensão), não um lembrete cosmético que deva
  respeitar um teto configurável. Justificativa: o pedido original não deixa
  claro se "por quantos dias" deveria também limitar o aviso de vencida, e
  silenciar um aviso de "sua oficina será suspensa" por causa de um contador
  de dias pareceu um risco pior que manter o aviso ativo.

**`oficinas`** (2 colunas novas — throttle de exibição por oficina, mesmo
padrão de adicionar direto na tabela já usado no motor de cobrança):
- `alerta_cobranca_exibicoes_hoje` INTEGER DEFAULT 0
- `alerta_cobranca_ultima_exibicao_em` DATE NULLABLE (data, não timestamp —
  só precisa saber se já é outro dia pra resetar o contador)

**`cobrancas`** (1 coluna nova — necessária pro botão de pagamento funcionar):
- `link_pagamento` VARCHAR(500) NULLABLE — a URL de checkout hospedado do
  gateway (`invoiceUrl` no Asaas, `init_point` no Mercado Pago). Hoje essa
  URL é obtida na criação da cobrança mas só o Mercado Pago a devolve pro
  admin no toast de sucesso; não é persistida em lugar nenhum. Passa a ser
  salva em toda cobrança criada (motor recorrente e avulsa manual).

**Sem tabela nova.** O estado "ativo/inativo" do alerta NÃO é armazenado —
é sempre computado a partir de `Cobranca` (`tipo=ASSINATURA`,
`status in (PENDENTE, VENCIDA)`, mais recente por oficina). Isso já satisfaz
sozinho "desativa quando paga, ativa de novo quando surge uma nova cobrança
pendente", porque é exatamente isso que o motor de cobrança (feature
anterior) já faz com o campo `status` da `Cobranca`.

## B) Lógica de estado / mensagens

Duas variantes de mensagem, computadas a partir da cobrança `ASSINATURA` mais
recente com `status in (PENDENTE, VENCIDA)` da oficina — **[decisão]**: o
pedido do usuário descreve 3 frases ("disponível", "vencida", "será
suspensa em X dias"), mas lendo o pedido com atenção as duas últimas são a
*mesma* fase (vencida), só com o texto final variando; então ficam 2 estados
reais:

1. **PENDENTE** (ainda dentro do prazo): "Sua fatura de {valor} está
   disponível para pagamento. Vencimento: {data}."
2. **VENCIDA**: "Sua fatura venceu em {data}. Pague sua fatura e evite a
   suspensão dos seus serviços no sistema." + se
   `dias_suspensao_vencido - dias_desde_vencimento > 0`: complementa com
   "Sua oficina será suspensa em {N} dias e seu acesso será bloqueado até a
   identificação do pagamento." Caso contrário (N ≤ 0 — suspensão já
   deveria ter ocorrido, mas o job de suspensão automática ainda não existe,
   é o próximo subsistema): "Sua oficina pode ser suspensa a qualquer
   momento."

`dias_suspensao_vencido` já existe (`oficina.dias_suspensao_vencido` ??
`saas_config.cobranca_dias_suspensao_padrao`, ambos criados no motor de
cobrança) — reaproveitado aqui só pra calcular o texto, sem nenhum efeito de
bloqueio real (isso é o próximo subsistema).

## C) Endpoints tenant-side (novos)

Middleware `['tenant', 'auth:sanctum']` (sem restrição de role — qualquer
usuário logado vê o alerta, igual `notificacoes/ativas`), exceto a troca de
ciclo que é `role:ADMIN` (decisão financeira).

- `GET /assinatura/alerta` — retorna `{show: bool, fase, mensagem, valor,
  vencimento, link_pagamento, ciclo_atual, desconto_anual_pct}`. Se `show`
  for `true`, essa mesma chamada já incrementa
  `alerta_cobranca_exibicoes_hoje` (resetando se a data mudou) — uma
  chamada só, sem endpoint de "marcar como exibido" separado.
- `POST /assinatura/mudar-ciclo` (`role:ADMIN`) — mesma lógica de
  `SaaS\OficinaController::mudarCiclo`, mas resolvendo a oficina via
  `TenancyContext` em vez de um `{id}` de rota. Extraída pra um service
  compartilhado (`AssinaturaService::mudarCiclo`) usado pelos dois
  controllers, pra não duplicar a lógica de cancelar cobrança pendente +
  recalcular vencimento.

## D) Frontend

- Novo componente `frontend/components/AssinaturaAlertaModal.tsx`, montado
  em `app/(dashboard)/layout.tsx` ao lado do `<NotificacaoModal />`
  existente (mesmo padrão: fetch on mount, mostra se `show===true`).
- Layout "parecido com planos da plataforma": dentro do modal, um bloco de
  upsell com 2 cards lado a lado (Mensal vs Anual), estilo already
  estabelecido no design system (cards escuros, borda `--border`, destaque
  `--accent` no plano recomendado/anual, mostrando "economize X%"),
  reproduzindo a linguagem visual de card de plano já usada em outras telas
  do produto (mesmas variáveis CSS, tipografia Barlow Condensed nos
  títulos).
- Botões "Pagar com PIX" e "Pagar com Cartão" — **[decisão]**: ambos abrem
  `link_pagamento` (a mesma URL de checkout hospedado do gateway) numa nova
  aba. Não existe hoje (nem é criado nesta spec) um fluxo nativo separado de
  QR code PIX ou tokenização de cartão dentro do próprio sistema — tanto
  Asaas quanto Mercado Pago já entregam uma página hospedada onde o pagador
  escolhe o método na hora. Dois botões com o mesmo destino preserva a
  intenção do pedido (dar a opção de PIX ou cartão) sem inventar uma
  integração de pagamento nativa fora do escopo combinado.
- Botão "Trocar para anual e economizar {desconto}%" → confirma → chama
  `POST /assinatura/mudar-ciclo`.
- Fechar o modal não marca nada como "lido" permanentemente — ele volta a
  aparecer no próximo dia elegível (`alerta_cobranca_vezes_dia`/dia), ao
  contrário do `NotificacaoModal` que usa `localStorage`. Aqui o controle é
  todo server-side (é isso que viabiliza "não pode ser deletado" e o
  liga/desliga automático).

## E) SaaS Admin — configuração

Adiciona 2 campos (`alerta_cobranca_vezes_dia`,
`alerta_cobranca_dias_exibicao`) na mesma seção "Cobrança Recorrente" já
existente em `/saas-admin/configuracoes` (Task 11 do motor de cobrança),
reaproveitando `SaasConfigController::updateCobranca` (adiciona os 2 campos
na validação existente em vez de criar um endpoint novo).

## Bug pré-existente descoberto e corrigido como pré-requisito

Ao desenhar o endpoint `GET /assinatura/alerta`, percebi que
`InitializeTenancyByHeader` já bloqueia com `402` **qualquer** rota tenant
assim que `oficina.status === 'INADIMPLENTE'` — e o motor de cobrança
recorrente (feature anterior, já em produção) marca a oficina como
`INADIMPLENTE` automaticamente no primeiro dia de atraso. Sem corrigir isso,
o próprio endpoint desta feature nunca seria alcançável no estado "vencida",
justamente o caso mais importante. Fix (Task 1 do plano): o middleware para
de bloquear em `INADIMPLENTE` (continua bloqueando `SUSPENSA`/`CANCELADA`).
`INADIMPLENTE` passa a significar "cobrança em aberto, nagueada pelo alerta,
mas funcionando" — o período de carência que o pedido original descreve.

## Fora de escopo (fica pro próximo subsistema)

- Suspensão automática de fato (a mensagem já calcula "será suspensa em N
  dias", mas nenhum job bloqueia a oficina ainda).
- Voto de confiança.
- Página de bloqueio da oficina suspensa.
- Fluxo nativo de PIX (QR code embutido) ou tokenização de cartão dentro do
  sistema — os botões abrem o checkout hospedado do gateway.
