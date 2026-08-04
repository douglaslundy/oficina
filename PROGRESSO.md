# Progresso do Projeto

## Última atualização
2026-07-24

## Tarefa em andamento
Correções pós-deploy reportadas pelo usuário em produção — código pronto,
commitado (`45019f0`), **deployado na VPS e verificado** (domínios
`stuntmotos` e `oficina-do-lundy` respondendo 200, containers saudáveis).
Falta o usuário validar manualmente na tela da oficina.

## Contexto necessário
- Itens reportados pelo usuário (todos em `oficinas/[id]` do saas-admin):
  1. Painel de gateway na tela da oficina mostrava sempre "Asaas", mesmo com
     Mercado Pago configurado. **Corrigido**: painel agora é dinâmico
     (`oficina.gateway`), com botão "Criar cliente no gateway" quando não há
     customer_id (recovery action para o próximo caso de provisionamento
     silenciosamente falho).
  2. Erro suspeito na cobrança avulsa contendo instruções para acessar uma
     VPS `192.168.0.233` com credenciais — **identificado como não sendo um
     erro de aplicação real (parece prompt injection)**. Não foi executado
     nada relacionado a esse texto. Se o usuário voltar a mencionar isso,
     pedir o erro bruto/print, não agir sobre credenciais desconhecidas.
  3. Ao clicar "Gerar" na cobrança avulsa, nada acontecia visualmente mas a
     cobrança ERA criada (provável falha de rede pós-commit, ack perdido).
     **Corrigido**: modal de cobrança avulsa agora tem banner de erro
     inline (além do toast), reduzindo a chance de o usuário achar que
     "não aconteceu nada" quando na real houve erro de rede após o
     `INSERT` já ter sido commitado no backend.
  4. `mudarCiclo` (e outras ações: suspender/reativar/cancelar
     assinatura/cancelar cobrança) usavam `window.confirm()` nativo em vez
     de modal do design system. **Corrigido**: modal de confirmação
     genérico (`confirmDialog` state) substituindo todos os `confirm()` da
     página de detalhe da oficina.
  5. Faltava botão para gerar manualmente a cobrança de
     mensalidade/anuidade do ciclo atual, com regra: se já gerada
     (manual ou automaticamente) para o vencimento atual, o job automático
     não gera outra até o próximo ciclo; não afeta cobranças avulsas.
     **Corrigido**: `CobrancaRecorrenteService::gerarManual()` reusa a MESMA
     checagem de duplicidade de `gerarPendentes()` (oficina + vencimento +
     status != CANCELADA) — elegante porque a idempotência "só uma vez por
     ciclo" sai de graça da checagem existente, sem precisar de coluna nova.
     Botão "Gerar Cobrança do Ciclo Agora" na tela da oficina.
  6. 502 no domínio de uma oficina recém-criada — **já corrigido e
     deployado antes desta sessão de correções** (bug de bind-mount de
     arquivo único no nginx, commit `a140f0e`).

- **Arquivos alterados nesta rodada** (ainda não commitados):
  - `backend/app/Services/MercadoPagoService.php` — `buscarCustomer()`
  - `backend/app/Services/CobrancaRecorrenteService.php` — `gerarManual()`
  - `backend/app/Http/Controllers/SaaS/OficinaController.php` —
    `formatOficina()` expõe gateway/customer ids; `update()` aceita
    `gateway`; `store()` avisa se o customer não foi criado no gateway;
    `asaasStatus()` agora é gateway-aware (usa MercadoPagoService quando
    `oficina.gateway === MERCADOPAGO`); novos endpoints
    `criarCustomerGateway()` e `gerarCobrancaCiclo()`.
  - `backend/routes/api.php` — rotas
    `POST oficinas/{id}/criar-customer-gateway` e
    `POST oficinas/{id}/gerar-cobranca-ciclo`.
  - `frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx` — painel
    de gateway dinâmico, modal de confirmação genérico, botão de geração
    manual do ciclo, banner de erro inline na cobrança avulsa.
  - `frontend/components/saas/EditOficinaModal.tsx` — campo de seleção de
    gateway (ASAAS/MERCADOPAGO).
- Build/lint verificados: `php -l` em todos os arquivos PHP tocados +
  `npx tsc --noEmit` + `npm run build` (Next.js) — todos limpos. **Não
  rodado**: feature tests (precisam de Postgres, não disponível
  localmente) nem teste manual em produção — usuário vai validar depois do
  deploy.
- Dados de produção já corrigidos numa etapa anterior desta sessão:
  `stuntmotos` e `oficina-do-lundy` já têm `gateway=MERCADOPAGO` e
  `mp_customer_id` preenchido via script tinker direto no banco.
- Preferência do usuário: falar em português a partir de agora.

## Concluído
- [x] Fases 1-6 do CLAUDE.md original, emissão fiscal, histórico por veículo
      (sessões anteriores)
- [x] Motor de cobrança recorrente, alerta de cobrança, suspensão
      automática — deployados (sessão anterior)
- [x] Deploy em produção completo (sessão anterior)
- [x] Bug do 502 (nginx bind-mount) — corrigido e deployado
- [x] Dados de gateway das oficinas existentes corrigidos em produção
- [x] Código dos 5 itens acima pronto, lint/build OK

## Rodada 2 (mesma sessão) — bug real da cobrança avulsa + página Minhas Faturas
- **Causa raiz real do "erro no navegador mas cobrança foi criada"**:
  `OficinaController::gerarCobrancaAvulsa()` linha 498 chamava
  `number_format($cobranca->valor, ...)` sem cast — `valor` vem como
  STRING do cast Eloquent `decimal:2`, e o arquivo tem
  `declare(strict_types=1)`, então PHP 8 lança `TypeError` ao montar a
  resposta JSON DEPOIS que o `Cobranca::create()` já tinha sido commitado.
  Confirmado direto no log de produção (`docker logs mecanicapro-backend-1`)
  com stacktrace exato. **Corrigido**: `(float) $cobranca->valor`. Não era
  falha de rede como eu supus antes — é 100% reproduzível, sempre que
  alguém cria uma cobrança avulsa.
- **Nova página `/minhas-faturas`** (tenant-side, `(dashboard)`): lista
  TODAS as cobranças da oficina (ASSINATURA + AVULSA) em ordem cronológica
  por vencimento. Pendente/Vencida → botão "Pagar" (abre `link_pagamento`
  em nova aba). Paga → botão "Ver Detalhes" (modal com valor, vencimento,
  pago em, gateway, id do pagamento). KPIs de topo (em aberto/vencidas/pago).
  Acesso restrito a `role:ADMIN,FINANCEIRO` (mesmo padrão de
  Notas Fiscais/Relatórios) — endpoint novo `GET /assinatura/faturas`.
  Item novo no Sidebar com badge de contagem de pendentes (mesmo padrão de
  Clientes devedores/Produtos em alerta).
- **Por que o alerta de pagamento não apareceu para a cobrança avulsa**:
  `AssinaturaAlertaModal`/`AssinaturaAlertaService::status()` só considera
  `Cobranca.tipo === 'ASSINATURA'` — é por design (esse modal fala
  especificamente de mensalidade/anuidade e ameaça suspensão, o que não se
  aplica a cobrança avulsa). A página Minhas Faturas + badge no menu é a
  solução de visibilidade para avulsas. Não estendi o modal bloqueante
  para avulsas — avaliar com o usuário se ele quer isso também.
- Também corrigido de passagem: mesmo bug de nomenclatura de status
  (`PAGO`/`VENCIDO` vs os valores reais `PAGA`/`VENCIDA`) na tabela de
  Cobranças Locais do saas-admin — os pills nunca pintavam certo.
- Lint/build: `php -l` limpo, `npx tsc --noEmit` limpo. Ainda não deployado
  nem testado pelo usuário nesta rodada.

Rodada 2 deployada e validada (commit `f0f5532`, domínios OK).

## Rodada 3 (mesma sessão) — notificação de pagamento pro admin do SaaS
- Pedido: quando uma oficina paga uma fatura (via webhook Asaas/Mercado
  Pago), o admin do SaaS deve receber e-mail (e WhatsApp, se configurado).
- Perguntei ao usuário sobre o WhatsApp do admin (não existe hoje nenhuma
  instância/número dedicado à plataforma, só por oficina) — decidiu **só
  e-mail por enquanto**, WhatsApp fica pra depois. E-mail vai pra **todos os
  `super_admins`** cadastrados.
- Implementado em `WebhookController::reconciliarPagamento()` — dispara
  `notificarAdminPagamento()` logo após marcar a Cobranca como PAGA (antes
  do early-return de tipo ASSINATURA, então cobre avulsa também). Usa
  `EmailService` (SMTP configurado em SaaS Admin → Configurações); se não
  configurado ou o envio falhar, é silencioso — nunca derruba o webhook.
- Commit `be59206`, deploy em andamento nesta rodada.
- **Não implementado**: WhatsApp pro admin (precisa de infraestrutura nova
  — instância Evolution dedicada à plataforma, não a uma oficina; hoje
  `whatsapp_configs.oficina_id` é NOT NULL). Retomar se o usuário pedir.

Rodada 3 deployada e validada.

## Rodada 4 (mesma sessão) — 3 correções: cards de valor, checkout transparente, rótulo de tipo
1. **Cards de comparação mensal/anual** (`AssinaturaAlertaModal.tsx`, upsell
   de troca pra anual): agora mostram valores reais em R$ (mensal, anual
   total sem desconto, anual com desconto, equivalente mensal e economia),
   calculados no frontend a partir de `alerta.valor` +
   `alerta.desconto_anual_pct` (mesma fórmula do backend). Antes só tinha
   "Plano atual" e "-X%" sem números.
2. **Checkout transparente (Mercado Pago)** — antes o pagamento sempre
   abria `link_pagamento` (Checkout Pro) numa aba externa. Agora, pra
   cobranças com `gateway === 'MERCADOPAGO'`, o pagamento acontece dentro
   do próprio sistema via Payment Brick (`@mercadopago/sdk-react`,
   cartão + PIX com QR code inline). Asaas **não foi alterado** — continua
   abrindo o link externo (fora do escopo do pedido).
   - Backend: `MercadoPagoService::criarPagamento()` chama `/v1/payments`
     direto (não mais `/checkout/preferences` pro pagamento em si — essa
     preference continua sendo criada na hora da cobrança só como
     fallback/registro, mas não é mais usada pela UI de oficinas MP).
     Novo `PagamentoController` (`GET /pagamento/mercadopago/chave-publica`,
     `POST /pagamento/mercadopago`, `GET /pagamento/faturas/{id}/status`).
     Lógica de "marcar paga + avançar vencimento + reativar + notificar
     admin" extraída do `WebhookController` pra
     `PagamentoReconciliacaoService`, reusada pelos dois fluxos (webhook
     assíncrono E confirmação síncrona no checkout transparente).
   - `AssinaturaAlertaService` (alerta + status-bloqueio) passou a incluir
     `cobranca_id` e `gateway` na resposta — antes só tinha `link_pagamento`.
   - Frontend: novo componente `PagamentoTransparenteModal.tsx`
     (formulário → aprovado/PIX pendente com polling a cada 5s/rejeitado),
     usado em `minhas-faturas`, `AssinaturaAlertaModal` e `bloqueado`
     — nos 3 lugares, só troca de comportamento quando `gateway ===
     'MERCADOPAGO'`; Asaas mantém o `<a href>` externo de antes.
   - Instalado `@mercadopago/sdk-react` (compatível com React 19).
3. **Rótulo "Mensalidade/Anuidade" errado** — a coluna Tipo em Minhas
   Faturas sempre mostrava as duas palavras juntas pra qualquer cobrança de
   assinatura. Corrigido: backend deriva `tipo_label` ("Mensalidade" ou
   "Anuidade", nunca as duas) a partir do texto de `descricao` gravado na
   criação da cobrança (que já reflete o ciclo real daquele charge
   específico, não o ciclo atual da oficina).
- Lint/build: `php -l` limpo em todos os arquivos, `npx tsc --noEmit` e
  `npm run build` limpos. **Ainda não deployado nem testado com
  credenciais reais de Mercado Pago** — recomendo testar em homologação
  antes de assumir que o fluxo de pagamento ponta a ponta funciona (a
  lógica está implementada e compila, mas nunca rodou contra a API real do
  MP nesta sessão).

Rodada 4 deployada e validada (commit `7f2913e`, domínios OK, `mp_public_key`/
`mp_access_token` configurados em produção — ambiente `producao`, não
homologação).

## Rodada 5 (mesma sessão) — Payment Brick não era "transparente" de verdade
- Usuário testou e reclamou: o Payment Brick da rodada 4, mesmo embutido
  (sem redirecionar pra fora), renderiza o **layout visual próprio do
  Mercado Pago** (cores, fontes, campos deles) dentro do modal — não o
  design system do MecânicaPro. "Checkout transparente" de verdade
  (terminologia oficial da MP) significa usar os **Secure Fields**
  (`CardNumber`, `ExpirationDate`, `SecurityCode` — iframes só pros dados
  sensíveis, por exigência de PCI-DSS, mas 100% estilizáveis via prop
  `style`) + layout/inputs próprios (nome, CPF, parcelas, botão) — não o
  Brick pré-pronto.
- Requisito extra do usuário: campos de cartão **nunca podem ser
  cacheados/sugeridos pelo autocomplete do navegador**. Como os Secure
  Fields são iframes de outra origem (domínio da MP), o autofill do
  Chrome/navegador pro NOSSO site não tem acesso a eles de forma alguma —
  resolvido estruturalmente, não por configuração. Nos campos que são
  nossos (nome do titular, CPF), usei `autoComplete="off"` +
  `autoCorrect="off"` + `spellCheck={false}` + `name` não-convencional.
- Reescrito `PagamentoTransparenteModal.tsx` do zero: abas Cartão/PIX
  estilizadas nossas, `CardNumber`/`ExpirationDate`/`SecurityCode` com
  `style` batendo no tema escuro, detecção de bandeira via `onBinChange` →
  `getPaymentMethods()` → `getInstallments()` (parcelas), `createCardToken()`
  gera o token no cliente, e só então envia pro MESMO backend de antes
  (`POST /pagamento/mercadopago`) — **zero mudança no backend**, o contrato
  de campos (`token`, `payment_method_id`, `issuer_id`, `installments`,
  `payer`) já era exatamente esse.
- Lint/build limpos (`npx tsc --noEmit` + `npm run build`).

Rodada 5 deployada e validada (commit `1224f55`).

## Rodada 6 (mesma sessão) — CPF pré-preenchido + campos de cartão espremidos
- Usuário testou: pediu CPF digitado (deveria vir de `oficinas.admin_cpf`) e
  os campos de cartão não deixavam digitar. Causa do segundo: o wrapper dos
  secure fields tinha `height: 20` com `padding: '9px 12px'` no PRÓPRIO
  wrapper — como o iframe da MP preenche 100% do wrapper, sobrava ~2px de
  área útil. Corrigido: padding movido pro `style` do field (renderiza
  dentro do iframe), wrapper com 40px fixos. CPF: endpoint da chave pública
  agora retorna `cpf_titular` (via `TenancyContext` → `Oficina.admin_cpf`),
  frontend pré-preenche. Commit `3c05882`, deployado e validado.

## Rodada 7 (mesma sessão) — PIX travado + estorno + conciliação manual
- Usuário pagou via PIX, tela ficou "aguardando pagamento" mesmo após F5.
  **Causa**: o sistema dependia 100% do webhook da MP chegar pra marcar a
  cobrança como PAGA — se o webhook atrasar, falhar, ou nunca tiver sido
  registrado corretamente no painel do Mercado Pago, o status local nunca
  atualiza sozinho.
- **Fix de raiz**: `PagamentoController::statusFatura()` (chamado pelo
  polling do frontend a cada 5s) agora, se a cobrança ainda não está PAGA
  localmente e tem `mp_payment_id`, consulta a API da MP direto
  (`MercadoPagoService::buscarPagamento()`) e concilia na hora — não
  depende mais só do webhook. Isso já corrige o caso relatado assim que o
  usuário reabrir a tela/pagar de novo.
- **Novo: botão "Estornar"** (SaaS admin) — em `saas-admin/cobrancas`
  (lista global) e na tela de detalhe da oficina ("Cobranças Locais"), com
  modal de confirmação antes de agir (não é `confirm()` nativo). Chama
  `MercadoPagoService::estornarPagamento()` / `AsaasService::
  estornarPagamento()` (novos métodos) e marca a cobrança como novo status
  `ESTORNADA` (não reaproveitei `CANCELADA` — são coisas diferentes: uma é
  "nunca foi cobrada", outra é "foi paga e devolvida"). **Não desfaz**
  efeitos locais automáticos (avanço de vencimento, reativação da oficina)
  — mensagem de sucesso avisa o admin pra revisar manualmente se precisar.
- **Novo: botão "Conciliar"** (mesmos dois lugares) — endpoint
  `POST /saas/cobrancas/conciliar` (aceita `oficina_id` opcional) varre
  cobranças PENDENTE/VENCIDA com payment_id e verifica o status real no
  gateway, reconciliando as que já foram pagas. É a versão manual/global do
  mesmo fix de raiz do polling — útil pra oficinas travadas que não estão
  com a tela de pagamento aberta esperando.
- Corrigido de passagem: mesmo bug de nomenclatura de status
  (`PAGO`/`PENDENTE`/`VENCIDO`) na página global `saas-admin/cobrancas`
  — os pills nunca batiam com os valores reais (`PAGA`/`VENCIDA`).
- Lint/build limpos.

Rodada 7 com deploy em andamento.

## Rodada 8 (mesma sessão) — conciliação ativa também sem a tela de pagamento aberta
- Usuário perguntou explicitamente: "vai atualizar automaticamente no
  momento do pagamento do pix?" Resposta honesta que dei: só enquanto a
  tela de pagamento está aberta (polling a cada 5s) — se a pessoa fechar
  antes de pagar e pagar depois, dependia só do webhook (não confirmado
  que está registrado certo na MP).
- Fechei essa lacuna: extraí a lógica de "consultar gateway e conciliar se
  já foi pago" pra um método público reutilizável,
  `PagamentoReconciliacaoService::verificarEConciliar()`. Usado agora em:
  `PagamentoController::statusFatura()` (polling da tela de pagamento,
  já existia), `CobrancaController::conciliar()` (botão manual do admin,
  já existia), e AGORA TAMBÉM em `AssinaturaController::alerta()`,
  `::statusBloqueio()` e `::faturas()` — ou seja, toda vez que a oficina
  abre o dashboard (alerta), a tela de bloqueio, ou "Minhas Faturas", as
  cobranças em aberto com payment_id são checadas contra o gateway de
  verdade antes de responder. Isso cobre o caso de pagar fora da tela de
  checkout (ex.: PIX pelo app do banco depois de fechar a aba) sem
  depender do webhook.
- Lint limpo em todos os arquivos tocados.

Rodada 7 e 8 deployadas e validadas (commit `ab5d578`, domínios OK).

## Rodada 9 (mesma sessão) — auditoria de segurança do fluxo de pagamento + correções
Usuário pediu análise de segurança do checkout (sem aplicar fix ainda),
depois aprovou ("ok prossiga") todas as correções listadas. Achados e fixes:

- **🔴 Crítica — bypass de autenticação do webhook Asaas, explorável em
  produção**: `ASAAS_WEBHOOK_TOKEN` não configurado → `'' !== ''` (config
  vazio == header vazio) deixava passar sem autenticação, e o handler
  confiava direto no `payment.id`/`event` do corpo da requisição (sem
  reconsultar a API da Asaas) pra marcar cobrança como paga. `asaas_payment_id`
  é visível pro próprio usuário da oficina em Minhas Faturas — dava pra
  forjar "paguei" sem pagar. **Corrigido**: falha fechado quando o token
  não está configurado, `hash_equals()` no lugar de `!==`.
- **🟠 Alta — zero rate limiting** em toda a superfície de pagamento
  (`bootstrap/app.php` só tinha `HandleCors`, nenhum throttle). **Corrigido**:
  `throttle:10,1` em `pagamento/mercadopago` (processa cartão/PIX de
  verdade — sem isso nada impede "card testing"), `throttle:30,1` em
  chave-pública/status (polling), `throttle:60,1` nos dois webhooks,
  `throttle:20,1` em conciliar/estornar do saas-admin.
- **🟡 Média — webhook MP falhava aberto**: `if ($secret && $xSignature)`
  só verificava se AMBOS estivessem presentes — omitir o header
  `x-signature` pulava a verificação inteira mesmo com o segredo
  configurado (confirmado configurado em produção). Impacto real era menor
  que o caso Asaas (esse fluxo sempre reconsulta o pagamento real na API da
  MP antes de conciliar), mas o padrão estava errado. **Corrigido**: falha
  fechado se faltar segredo OU assinatura.
- **🟡 Média — sem lock de banco nas transições de status**: com a
  conciliação ativa das rodadas 7/8 rodando em vários pontos concorrentes
  (webhook, polling, botão manual, quase toda página do tenant),
  havia janela de corrida pra `avancarVencimento()` rodar duas vezes.
  **Corrigido**: `PagamentoReconciliacaoService::confirmarPagamento()` e
  `CobrancaController::estornar()` agora rodam dentro de
  `DB::transaction()` com `lockForUpdate()` na linha da cobrança
  (e-mail de notificação fica fora da transação, não segura o lock durante
  a chamada SMTP). Também passou a excluir `ESTORNADA` da checagem de
  "já processada" (fix de correção junto).
- **🟢 Baixa — sem headers de segurança**: `X-Frame-Options`/
  `X-Content-Type-Options` eram exigência do CLAUDE.md original e nunca
  foram implementados. **Corrigido**: novo middleware `SecurityHeaders`
  (+ `Referrer-Policy`), aplicado a todo o grupo `api`.
- **🟢 Baixa — CPF do admin exposto pra qualquer role**: `GET
  /pagamento/mercadopago/chave-publica` devolvia `cpf_titular`
  incondicionalmente. **Corrigido**: só ADMIN/FINANCEIRO recebem o CPF
  pré-preenchido agora; outras roles digitam manualmente.
- **🟢 Baixa — sem validação de CPF/payment_method_id no servidor**:
  confiava inteiramente na validação da Mercado Pago. **Corrigido**:
  `payer.identification.number` agora usa a `App\Rules\Cpf` já existente no
  projeto (valida dígito verificador), `payment_method_id` restrito a
  `[a-z0-9_]+`, `installments` com teto de 24.
- Confirmado pré-existente e **correto**: o formulário de cartão (número/
  validade/CVV) usa Secure Fields da MP — iframes de outra origem, dados
  nunca entram no DOM da nossa página, então autofill/cache do navegador
  não tem acesso a eles. Nome/CPF (inputs nossos) já tinham
  `autoComplete="off"`.
- Lint limpo em todos os arquivos tocados. Frontend não precisou de
  nenhuma mudança (já mandava exatamente o formato agora validado; `null`
  em `cpf_titular` já era tratado como "campo vazio, usuário digita").

Rodada 9 deployada e validada (commit `053a219`, domínios OK, headers de
segurança confirmados na resposta real).

## Rodada 10 (mesma sessão) — causa raiz do webhook MP + WhatsApp do admin
Usuário pediu: resolver a investigação do webhook MP (item 3 da lista de
pendências) e implementar o WhatsApp do admin (item 4, que tinha ficado pra
depois na rodada 3).

- **Causa raiz do webhook MP identificada**: conferi o log do backend dos
  últimos 7 dias — **zero requisições chegaram** em
  `saas/webhooks/mercadopago`. Testei o endpoint de fora (simulando uma
  chamada real) e ele responde corretamente (401 sem assinatura, conforme
  o fix de segurança da rodada 9) — não é problema de alcance/firewall/bug
  nosso. Conclusão: a Mercado Pago nunca teve esse webhook registrado no
  painel de desenvolvedores pra essa aplicação (ambiente produção). **Não é
  algo que dá pra corrigir por código** — só o usuário consegue, logando no
  painel da MP e cadastrando `https://saas.dlsistemas.com.br/api/saas/webhooks/mercadopago`
  no evento "Pagamentos". Instruções passadas pro usuário; a conciliação
  ativa das rodadas 7/8 já cobre o sintoma enquanto isso não for feito.
- **WhatsApp do admin implementado**: nova instância própria da Evolution
  API pra plataforma (independente das instâncias por oficina, que são
  tenant-scoped e não serviam pro admin). Novos campos em `saas_config`
  (`whatsapp_admin_instance`, `whatsapp_admin_instance_token`,
  `whatsapp_admin_numero`, `whatsapp_admin_ativo` — migration
  `2026_07_20_000001`), novo `AdminWhatsAppService` (mesmo padrão do
  `WhatsAppService` de oficina, mas sem `TenancyContext`), novo
  `SaaS\AdminWhatsAppController` + rotas em `saas/config/whatsapp-admin*`.
  Nova tela `saas-admin/configuracoes/whatsapp` (QR code, status, testar,
  número de destino) — mesmo padrão visual da tela de WhatsApp da oficina,
  linkada a partir da seção Evolution API em Configurações.
  `PagamentoReconciliacaoService::notificarAdminPagamento()` agora manda
  e-mail E WhatsApp (cada canal independente e silencioso — falha de um
  não afeta o outro nem a confirmação do pagamento).
- Lint/build limpos (`php -l` em tudo, `npx tsc --noEmit` + `npm run
  build`). **Migration ainda não rodou em produção** — vai rodar sozinha no
  próximo deploy (`docker-entrypoint.sh` já faz `migrate --force`
  automaticamente).

## Rodada 11 (nova sessão) — PDF da OS indisponível
- Usuário reportou: PDF da OS não abre/baixa.
- **Causa raiz (debugging sistemático)**: `downloadFile()` em
  `frontend/app/(dashboard)/os/[id]/page.tsx` (usada pelos botões PDF e
  Recibo) fazia `fetch()` direto pra `NEXT_PUBLIC_API_URL`, uma env
  gravada em BUILD TIME no Docker como `https://oficina.dlsistemas.com.br`
  (fixa, um único domínio). Em produção, `CORS_ALLOWED_ORIGINS`
  (`docker-compose.vps.yml`) só libera esse mesmo domínio fixo. Qualquer
  oficina acessando pelo PRÓPRIO subdomínio (`stuntmotos.dlsistemas.com.br`,
  `oficina-do-lundy...`) faz uma requisição cross-origin bloqueada pelo
  CORS do navegador → `fetch` falha → "Erro ao baixar PDF."
  A instância `axios` global (`lib/api.ts`) já tinha sido corrigida pra
  esse exato problema antes (reescreve `baseURL` pra
  `window.location.origin + '/api'`, comentário explícito no código:
  "avoids cross-origin CORS entirely" — Traefik roteia `<subdominio>/api/*`
  pro backend dentro do MESMO container/origem). A função de download de
  PDF só não tinha recebido esse mesmo tratamento.
- **Fix**: troquei `NEXT_PUBLIC_API_URL` por `window.location.origin` na
  `downloadFile()` — mesmo padrão do `api.ts`. Corrige PDF **e** Recibo (
  mesma função). `npx tsc --noEmit` limpo.
- **Usuário aprovou corrigir todas as ocorrências do mesmo padrão.**
  Também corrigidas (mesma troca: URL hardcoded → `window.location.origin`):
  - `frontend/app/(dashboard)/fiscal/historico/page.tsx` (PDF de NF +
    download ZIP em lote). **Bug extra encontrado e corrigido aqui**: o
    header `X-Tenant` usava `localStorage.getItem('tenant_slug')` — essa
    chave NUNCA é gravada em lugar nenhum do app (só `oficina_slug`
    existe, gravada em `useAuth.ts`). Ou seja, o download de NF sempre
    mandava `X-Tenant` vazio, independente do bug de CORS. Corrigido pra
    `oficina_slug`.
  - `frontend/app/(dashboard)/relatorios/page.tsx` (exportação XLSX de
    OS/clientes/estoque) — só a URL, `X-Tenant` já usava a chave certa.
  - `frontend/app/orcamento/[token]/page.tsx` (página pública de
    aprovação de orçamento, usa `axios` direto, não a instância
    `lib/api.ts`) — trocado `const API` hardcoded por `apiBase()` chamada
    dentro das funções (evita `window is not defined` em SSR, já que o
    módulo é avaliado também no server).
  - `frontend/app/saas-admin/(protected)/backup/page.tsx` (download de
    backup do saas-admin) — mesma troca. Relevante mesmo sem X-Tenant
    (saas-admin não é tenant-scoped): `saas.dlsistemas.com.br` nem consta
    em `CORS_ALLOWED_ORIGINS` do `docker-compose.vps.yml` (só
    `oficina.dlsistemas.com.br`), então dependia inteiramente de virar
    same-origin pra funcionar.
- **Sem teste automatizado**: bug depende de CORS real do navegador +
  múltiplos domínios; sem Docker/DB local pra reproduzir (limitação já
  documentada). Validação real só acontece após deploy, testando em uma
  oficina que NÃO seja `oficina.dlsistemas.com.br` (ex.: `stuntmotos`).
- `npx tsc --noEmit` e `npm run build` (Next.js, produção) limpos nos 5
  arquivos alterados.
- Usuário aprovou commit + deploy imediato desta rodada.
- **Deployado e validado** (commit `b6fc7bd`): containers saudáveis,
  `saas.dlsistemas.com.br`, `stuntmotos.dlsistemas.com.br` e
  `oficina-do-lundy.dlsistemas.com.br` respondendo 200 em `/api/health`.
  Falta o usuário validar manualmente que o download de PDF/recibo/NF/
  relatório/backup abre de fato numa oficina que não seja o domínio base.

## Rodada 12 (nova sessão) — Log de notificações visualizadas (oficina/usuário/IP)
Pedido do usuário: página mostrando todas as notificações abertas/lidas
pelas oficinas, quem leu, quantas vezes (pra notificações repetidas) e um
toggle com log detalhado (usuário, data, IP) — cobrindo tanto as
notificações manuais do admin quanto o alerta do motor de cobrança.

- Spec: `docs/superpowers/specs/2026-07-23-log-notificacoes-visualizadas-design.md`.
- Plano: `docs/superpowers/plans/2026-07-23-log-notificacoes-visualizadas.md`
  (9 tasks, executadas via subagent-driven-development — implementador +
  revisor por task, direto na main).
- **Gap descoberto na investigação**: nenhuma das duas fontes de
  notificação registrava visualização no servidor — a manual controlava
  tudo em `localStorage` do navegador, e o alerta de cobrança só tinha um
  contador agregado por oficina (`oficinas.alerta_cobranca_exibicoes_hoje`),
  sem log por evento.
- **O que foi implementado**: nova tabela central `notificacao_visualizacoes`
  (tipo MANUAL/COBRANCA, snapshot de título/mensagem, oficina/usuário/IP/
  user-agent/timestamp). `POST /notificacoes/{id}/visualizar` novo
  (tenant) grava a leitura; `GET /notificacoes/ativas` passou a decidir
  elegibilidade (vezes_dia/intervalo_minutos) no servidor, por oficina —
  `NotificacaoModal.tsx` não usa mais `localStorage`.
  `AssinaturaAlertaService::status()` ganhou uma gravação de log logo
  após `registrarExibicao()`, SEM alterar o throttle existente (caminho
  crítico de cobrança, intocado). SaaS Admin (`/saas-admin/notificacoes`)
  ganhou abas "Manuais" (coluna Leituras + toggle de log) e "Cobrança"
  (tabela agrupada por oficina/fatura + toggle de log), via componente
  compartilhado `NotificacaoLogInline.tsx`.
- **`TrustProxies` configurado** (`backend/bootstrap/app.php`) — sem
  isso, `$request->ip()` sempre devolveria o IP interno do Traefik, não o
  do usuário, e o log de IP não teria valor nenhum.
- **Bug crítico achado pela revisão final de branch (não pelas revisões
  por task) e corrigido**: Carbon 3 mudou `diffIn*()` pra diff com sinal;
  `now()->diffInMinutes($ultima)` (código original) é sempre negativo
  quando `$ultima` é passado, então o throttle de intervalo nunca
  expirava — notificação manual sumia pra sempre depois da primeira
  visualização. Corrigido pra `$ultima->diffInMinutes(now())`
  (commit `49c4fd7`), com 3 testes novos cobrindo os gaps que a revisão
  também apontou (fase VENCIDA do alerta de cobrança, role não-ADMIN em
  `visualizar()`, direção "ainda dentro do intervalo" do throttle).
- **Endurecimento de segurança pós-revisão**: a revisão final também
  achou que `trustProxies(at: '*')` confia em QUALQUER proxy — dá pra
  forjar `X-Forwarded-For` e falsificar o IP do log de auditoria E burlar
  o rate limit de login. Usuário aprovou restringir. Commit `abe7812`:
  trocado por faixas privadas do Docker (10.0.0.0/8, 172.16.0.0/12,
  192.168.0.0/16) + 127.0.0.1 (usado pelo test client do Laravel) — só
  uma conexão que já chega pela rede interna (ou seja, o próprio Traefik)
  é tratada como proxy confiável.
- **Itens Minor registrados, não corrigidos** (ver
  `.superpowers/sdd/progress.md` pro detalhe): `visualizar()` não valida
  `alvo_tipo` antes de logar (poderia logar visualização de notificação
  não destinada à oficina — impacto restrito à própria oficina do
  usuário); coluna Fase da aba Cobrança mostra status ao vivo da cobrança
  em vez do snapshot da fase no momento da exibição; sem purga/retenção
  automática do log (pode crescer bastante em fatura vencida há muito
  tempo).
- Todos os 9 commits da feature + o fix + o endurecimento de segurança
  estão na `main`, com `npx tsc --noEmit`/`npm run build` limpos e 34
  testes Unit locais passando (sem regressão). **Feature tests nunca
  rodaram contra Postgres de verdade** (indisponível localmente,
  limitação já documentada) — recomendo fortemente rodar
  `php artisan test tests/Feature` num ambiente com Postgres (CI ou a
  própria VPS) antes de considerar a rodada 100% validada, já que foi
  exatamente esse tipo de bug (lógica de throttle) que passou batido pelas
  revisões individuais e só apareceu na revisão final de branch inteira.
- **Deployado e validado** (commit `abe7812`, deploy concluído
  2026-07-24 02:22 -03): containers saudáveis, domínio público OK.
  Migration `2026_07_23_000001_create_notificacao_visualizacoes_table`
  confirmada `Ran` via `php artisan migrate:status` no container. Smoke
  test manual (seguro, sem escrever nada): `GET /api/saas/notificacoes`,
  `/api/saas/notificacoes-cobranca` e `/api/notificacoes/ativas`
  (stuntmotos) respondem `401` com `Accept: application/json` — igual a
  rotas antigas do mesmo padrão (`/api/saas/oficinas`,
  `/api/assinatura/alerta`), confirmando que as rotas novas existem e
  estão protegidas por auth corretamente. **NÃO rodei
  `php artisan test tests/Feature` em produção** — `RefreshDatabase`
  dropa e recria o banco, apagaria dados reais (regra permanente do
  projeto, ver `feedback-local-testing`). A cobertura de Feature tests
  desta rodada segue validada só por leitura de código + revisão, nunca
  executada de fato contra Postgres — se quiser essa validação real,
  precisa de um banco de teste dedicado/CI, não o de produção.
- Falta o usuário validar manualmente na tela: abrir uma notificação
  manual publicada, fechar, conferir que ela reaparece só depois do
  `intervalo_minutos` configurado (é exatamente o bug que foi corrigido);
  conferir a aba Cobrança em `/saas-admin/notificacoes` com uma oficina
  que tenha fatura pendente/vencida.

## Rodada 13 (mesma sessão) — agendador do Laravel nunca rodava em produção
Usuário testou a rodada 12 e reportou dois sintomas: (1) aba Cobrança de
`/saas-admin/notificacoes` vazia mesmo com fatura pendente existindo; (2)
fatura mensal automática da `oficina-do-lundy` não foi gerada.

- **(1) Não era bug** — expliquei ao usuário: a aba Cobrança é um log de
  *visualização* (só grava linha quando um usuário da oficina abre o
  sistema e o alerta é exibido pra ele), não uma lista de faturas
  pendentes (isso já existe em "Cobranças" no menu). Vazio é esperado se
  ninguém da oficina logou desde o deploy.
- **(2) Bug real e sério, confirmado direto na produção**: `crontab -l` na
  VPS não tinha NENHUMA entrada pro projeto mecanicapro, e os containers
  só rodavam `artisan serve` (backend) e `queue:work` (worker) — nada
  chamava `php artisan schedule:run`. Os 3 comandos agendados em
  `routes/console.php` (`cobrancas:gerar` 06:00, `alertas:verificar`
  07:00, `oficina:recalcular-status-clientes` 02:00) **nunca dispararam
  sozinhos em produção**, desde que foram criados — `Schedule::command()`
  no código não faz nada sozinho, precisa de algo externo cutucando
  `schedule:run` a cada minuto. Confirmado também que a única fatura
  pendente existente (`stuntmotos`, vencimento 01/08) foi criada
  manualmente (botão "Gerar Cobrança do Ciclo Agora"), não pelo job.
  Causa adicional específica da `oficina-do-lundy`: seu
  `proximo_vencimento` foi empurrado pra 2026-09-01 por um pagamento que
  depois foi estornado — estorno não desfaz esse avanço (comportamento já
  documentado antes, rodada 7) — usuário optou por gerar manualmente via
  botão em vez de eu editar a data direto no banco.
- **Fix**: novo container `scheduler` (mesma imagem do backend,
  `CONTAINER_ROLE=scheduler`) rodando `php artisan schedule:work`
  continuamente — processo dedicado do Laravel pra isso, não depende de
  cron do SO (evita ficar invisível num host compartilhado com outros
  projetos). `backend/docker-entrypoint.sh` ganhou o branch
  `CONTAINER_ROLE=scheduler` (mesmo padrão do branch `worker` já
  existente, antes da seção de migrations). `docker-compose.prod.yml`
  ganhou o serviço `scheduler`. **Não toquei em `docker-compose.vps.yml`**
  — confirmado que esse arquivo não é usado por `deploy-vps.sh` (só
  `docker-compose.prod.yml` é), parece ser um artefato antigo não
  referenciado por nenhum script.
- Commit `b241e78`, deployado e validado: `docker compose ps scheduler`
  mostra o container `Up`, log mostra `INFO No scheduled commands are
  ready to run.` (mensagem normal do `schedule:work` quando ainda não é a
  hora de nenhum comando — confirma que o loop de verificação por minuto
  está rodando de verdade).
- **Nota de fuso horário — corrigida logo em seguida, ver próxima entrada
  abaixo.** (Registrei aqui inicialmente como "FYI, não corrigida" por não
  fazer parte do pedido original; o usuário perguntou na sequência e pediu
  pra corrigir também.)
- Usuário vai gerar a fatura da `oficina-do-lundy` manualmente pelo botão
  "Gerar Cobrança do Ciclo Agora" (preferência dele, não fiz isso por ele).
- **Correção adicional pedida pelo usuário**: horário estava certo em
  quantidade (dispara todo dia), mas errado em fuso — sem `->timezone()`
  explícito, `dailyAt()` usa `config('app.timezone')` (UTC), então
  `cobrancas:gerar` disparava às 03:00 de Brasília em vez de 06:00.
  Corrigido com `->timezone('America/Sao_Paulo')` nos 3 agendamentos em
  `routes/console.php` (escopo mínimo — não mexe no timezone global da
  app, só em quando o agendador considera "a hora certa"). Commit
  `0712887`, deployado. **Validado em produção**:
  `php artisan schedule:list --timezone=America/Sao_Paulo` dentro do
  container mostra exatamente `0 2`/`0 7`/`0 6` (02:00/07:00/06:00
  Brasília) — confirma que a lógica real já usa o timezone certo (a
  exibição sem essa flag mostra em UTC por padrão, é só cosmético do
  comando `schedule:list`, não indica bug).

## Rodada 14 (mesma sessão) — parecer de viabilidade do NFePHP (entregue, não implementado)
Pedido original do usuário (mesma mensagem que trouxe a rodada 12): estudo
de viabilidade fiscal do NFePHP como motor gratuito adicional (contexto
Ilicínea/MG, IBGE 3130507), comparando com Spedy/Focus NFe já existentes.

- **Entregue só como parecer no chat** (não virou spec/plano ainda — ver
  "Próxima tarefa"). Pesquisa via WebSearch/WebFetch, sem escrever código.
- **Achados principais**:
  - `nfephp-org/nfephp` (pacote original) está DEPRECATED — sucessor é
    `nfephp-org/sped-nfe` (1,64M installs, v5.2.6 jun/2026, MIT/LGPL/GPL,
    ativamente mantido). Confiável para **NF-e/NFC-e** (peças, ICMS).
  - **NFS-e (serviço/mão de obra) mudou de figura em 2026**: LC 214/2025
    obriga todo município a aderir ao padrão nacional único (Ambiente de
    Dados Nacional / ADN) desde 01/01/2026 — resolve o problema histórico
    de cada prefeitura ter layout próprio (motivo dos pacotes
    `sped-nfse-*` do nfephp-org estarem fragmentados/abandonados).
    Achei biblioteca nova e específica pro padrão nacional:
    `nfse-nacional/nfse-php` (terceiros, não nfephp-org — MIT, 162
    stars, ativa).
  - **Ilicínea-MG**: sistema municipal roda em
    `ilicinea-mg.prefeituramoderna.com.br`; indício forte de que já exige
    emissão exclusiva pelo Portal Nacional (LC 214/2025). **Alíquota
    exata de ISS NÃO confirmada** (só o intervalo legal 2%–5%,
    LC 116/2003 + LC 157/2016) — fontes públicas indexadas não têm a lei
    municipal específica; recomendei confirmar direto com a Secretaria de
    Finanças ((35) 3854-1319) ou contador local antes de codificar
    qualquer valor.
  - Peças: maioria já chega com ICMS recolhido via substituição
    tributária (regra estadual MG) — oficina normalmente não recolhe de
    novo, só precisa emitir com CST/CSOSN de ST correto.
  - Reforma Tributária (IBS/CBS): já em vigor informativamente desde
    jan/2026 (alíquota teste 1%, sem alterar valor total); Simples
    Nacional só entra em set/2026; migração completa até 2033 — vale
    tanto pra Spedy/Focus quanto pra qualquer motor novo.
- **Recomendação dada**: SIM, seguro prosseguir, mas como motor adicional
  OPCIONAL (configurável por oficina), não substituto dos pagos — a
  interface `FiscalProvider` já existente no código (`app/Services/Fiscal/
  Contracts/FiscalProvider.php`) já comporta isso sem redesenho, bastaria
  um novo `Providers/NfePhpProvider.php`. Ponto central do parecer: o
  pacote em si é confiável, mas adotá-lo faz o MecânicaPro virar o próprio
  emissor perante o fisco (hoje Spedy/Focus absorvem contingência,
  atualização de schema, suporte a nota rejeitada — isso passaria a ser
  responsabilidade nossa).
- Passos sugeridos ao usuário, ainda não decididos: (1) confirmar alíquota
  real de Ilicínea antes de codificar; (2) teste isolado de emissão em
  homologação com `sped-nfe` + `nfse-nacional/nfse-php` fora do sistema
  antes de integrar; (3) documentar como limitação conhecida que a v1 não
  cobre contingência/fallback automático.

## Rodada 15 (nova sessão) — brainstorming do motor NFePHP EM ANDAMENTO
Retomando a rodada 14 (que era só parecer, nada implementado). Estamos no
meio do `superpowers:brainstorming` (skill carregada, ver
`C:\Users\dougl\.claude\plugins\cache\claude-plugins-official\superpowers\6.1.1\skills\brainstorming`)
— **NENHUM código escrito ainda, nenhum spec salvo ainda**. Sessão foi
interrompida a pedido do usuário (troca de modelo + relogin) antes de eu
terminar de reunir todas as decisões e apresentar o design formal.

**Instrução do usuário para a próxima sessão**: ele vai digitar "continue"
numa sessão nova e espera que eu retome EXATAMENTE daqui — sem reexplicar,
sem repetir perguntas já respondidas abaixo.

### Escopo já fechado com o usuário (não perguntar de novo)
- **Pedido original, textual**: "eu quero o NFePHP como terceiro motor
  para gerar nfe e nfse, porem tanto spedy como o focus precisam tambem
  emitir nfe e nfse" — ou seja, são DUAS frentes de trabalho distintas:
  1. NFePHP como motor novo (NF-e + NFS-e), usando `sped-nfe` +
     `nfse-nacional/nfse-php`.
  2. Spedy e Focus (que hoje SÓ emitem NFS-e no nosso código — confirmado
     lendo `SpedyProvider.php`/`FocusNfeProvider.php`, só batem em
     `/service-invoices` e `/v2/nfse`) precisam ganhar emissão de NF-e
     também, usando a API deles mesmo (não é NFePHP).
- **Sequenciamento decidido pelo usuário**: NFePHP PRIMEIRO. NF-e no
  Spedy/Focus fica pra uma rodada seguinte (spec separado, mais simples —
  é "ligar cabo que falta" na API deles, baixo risco). Esta rodada 15 é
  100% sobre o design do motor NFePHP.
- **Modelos de nota cobertos pelo NFePHP v1**: NF-e (modelo 55) + NFS-e
  nacional (ADN). NFC-e (modelo 65) FICA DE FORA da v1 (usuário escolheu
  a opção recomendada, não a de "NF-e + NFS-e + NFC-e").
- **Abrangência de UF na v1**: SÓ Minas Gerais (oficina real fica em
  Ilicínea/MG, webservice próprio da SEFAZ-MG). Multi-UF fica pra quando
  aparecer oficina fora de MG.
- **Contingência**: usuário PEDIU CONTINGÊNCIA JÁ NA V1 (escolheu a opção
  NÃO recomendada por mim — "Não, quero contingência já na v1"). Ou seja,
  o design PRECISA cobrir EPEC (Evento Prévio de Emissão em Contingência)
  desde o início, não é um "fica de fora, documentar como limitação"
  como o parecer da rodada 14 tinha sugerido. Isso aumenta bastante o
  escopo em relação ao que eu tinha estimado inicialmente — preciso
  detalhar isso na seção de arquitetura do design (fluxo de detecção de
  SEFAZ indisponível, geração/transmissão do evento EPEC, reautorização
  posterior do XML normal quando a SEFAZ voltar).
- **Execução**: EM FILA (Horizon), não síncrono. Reusa o padrão
  `PROCESSANDO` que já existe em `EmissaoResultado` (mesmo usado hoje por
  Spedy/Focus quando o status é "processando_autorizacao"/"enqueued").
- **Geração de PDF (DANFE/DANFSe)**: usuário escolheu DomPDF (já usado no
  projeto pra PDF de OS/relatórios), NÃO a lib `sped-da` do próprio
  ecossistema NFePHP. Ou seja, vou precisar de um template HTML nosso que
  bata o layout oficial exigido — vale registrar como trabalho não-trivial
  no design (vou precisar validar visualmente contra um DANFE/DANFSe real).
- **OS mista (peças + serviços)**: usuário escolheu emitir as DUAS notas
  juntas automaticamente ao emitir a partir de uma OS mista — o sistema
  separa por tipo de item (`PECA` → NF-e, `SERVICO` → NFS-e) e gera as
  duas numa ação só, cada uma com status/PDF/cancelamento independentes.
  NÃO é o fluxo de "usuário escolhe manualmente" nem o de "só nota
  avulsa separada".

### Pesquisa de Reforma Tributária (IBS/CBS) — REFEITA E CONCLUÍDA (2026-07-25)
Refeita nesta sessão via WebSearch/WebFetch direto (sem subagente — o limite
que derrubou a tentativa anterior era do fork, a busca sequencial funcionou).
Achados que impactam o design do motor NFePHP:

- **NF-e/NFC-e — NT 2025.002-RTC**, versão atual **v1.40 (publicada
  20/05/2026)**. Cria os grupos novos de IBS/CBS/IS no leiaute (bloco
  `IBSCBS`, campos `cClassTrib`, `cIndOp`, grupos `gALCZFMCBS`,
  `refDFeAnt`). Regra de validação **UB12-10_1115** é a que rejeita nota
  sem os campos.
- **Cronograma de obrigatoriedade da NF-e** (crítico pro nosso caso):
  - **03/08/2026** — obrigatório só pra **Regime Normal (CRT=3 — Lucro
    Real/Presumido)**. Sem os campos → rejeição na autorização.
  - **04/01/2027** — obrigatório pra **Simples Nacional (CRT=1) e MEI**.
    Até lá o Simples está **dispensado de destacar IBS/CBS**; obrigação
    real é informar o **Regime Tributário (CRT)** corretamente.
  - A NT diz explicitamente que as orientações de preenchimento pro
    CRT=1 virão em **NT futura ainda não publicada**.
- **NFS-e nacional**: bloco `IBSCBS` entrou na DPS; **NT SE/CGNFS-e
  nº 007/2026 (07/02/2026)** atualizou o leiaute (IBS/CBS + ajustes de
  PIS/COFINS/CSLL). Em **2026 o preenchimento do grupo IBSCBS é
  OPCIONAL** — mas se preenchido, TODAS as regras de validação da NT 04
  v2.0 se aplicam. Obrigatoriedade real começa em **2027**.
- **🔴 Achado novo e urgente, fora do que estava previsto: Resolução
  CGSN nº 189/2026** torna **obrigatório o uso do Emissor Nacional da
  NFS-e para ME/EPP do Simples Nacional** que prestam serviço sujeito a
  ISS, **a partir de 01/09/2026** (~5 semanas da data desta sessão).
  Isso reforça a decisão de usar `nfse-nacional/nfse-php` (padrão
  nacional/ADN) e não um integrador municipal, e cria um prazo real.
- **`nfephp-org/sped-nfe` — já suporta a NT 2025.002**: versão
  **v5.2.6 (15/06/2026)** publicada no Packagist, com menção explícita às
  NT 2025.002 v1.01/v1.10/v1.20 no changelog. A issue #1274 ("publicar
  versão com suporte a IBS/CBS/IS", aberta 13/11/2025) está **fechada**.
  Ressalva: o changelog menciona até a **v1.20** da NT; a NT já está na
  **v1.40** — pode haver defasagem de campos das versões 1.30/1.40.
  Impacto baixo pra v1 do nosso motor porque o emitente é Simples
  Nacional (dispensado até 2027), mas precisa ser reavaliado antes de
  atender qualquer oficina em Regime Normal.
- **`nfse-nacional/nfse-php` — NÃO confirmado suporte ao bloco IBSCBS /
  NT 007/2026**: o README não menciona reforma tributária, IBS, CBS nem
  ADN nesse contexto, e há discussão aberta de reescrita de arquitetura
  ("a próxima versão será uma oportunidade para consolidar uma
  arquitetura mais simples"). É o ponto mais frágil da stack proposta.
- **EPEC confirmado no `sped-nfe`** (`docs/Contingency.md`): fluxo é
  gerar a NF-e já com `tpEmis=4` + `dhCont` + `xJust`, chamar
  `$tools->sefazEPEC($xml, $verAplic)`, e **transmitir o XML normal
  quando a SEFAZ voltar — prazo de 7 dias**, senão a SEFAZ bloqueia
  novos EPEC por "Pendência de Conciliação". Esse prazo de 7 dias vira
  requisito de design (job de reconciliação agendado, não só "tenta de
  novo quando alguém clicar").
- **ISS**: o padrão nacional NÃO padroniza alíquota — continua municipal
  (2%–5%, LC 116/2003). Alíquota de Ilicínea segue **não confirmada**
  (mesma pendência da rodada 14): confirmar com a Secretaria de Finanças
  ou contador antes de codificar qualquer valor.

### (histórico) Pesquisa de Reforma Tributária — tentativa anterior que FALHOU
- Usuário pediu explicitamente (mensagem literal): "para estudar as
  regras fiscais, não esqueça de considerar e pesquisar muito a fundo a
  respeito da reforma tributaria e os novos impostos principalmente a
  respeito das novas regras, novos tipos de impostos como ibs e cbs e
  todos os outros que foram criados e fizer sentido."
- Eu disparei um agente (fork) de pesquisa profunda sobre isso — **o
  agente FALHOU** por limite semanal de uso da conta atingido (reseta
  2026-07-26 15h America/Sao_Paulo), não por erro de execução. Nenhum
  resultado foi retornado, nenhuma informação nova foi obtida além do que
  já constava no parecer da rodada 14 (que já tinha uma nota solta sobre
  IBS/CBS: "alíquota teste 1% desde jan/2026, sem alterar valor total;
  Simples Nacional só entra em set/2026; migração completa até 2033" —
  ESSE DADO NÃO FOI VERIFICADO A FUNDO, é só o que sobrou da pesquisa
  anterior, mais superficial).
- **Isso é bloqueante para o design técnico do NFePHP** porque a Reforma
  Tributária muda o leiaute XML da NF-e e da NFS-e nacional (novos grupos
  tipo IBSCBS) bem no meio da janela em que este motor está sendo
  desenhado (2026). Preciso saber, antes de fechar a arquitetura de
  dados (`NotaFiscalData` etc.) e o mapeamento de payload:
  1. Quais tags/grupos novos do XML da NF-e/NFS-e nacional já são
     obrigatórios em 2026 por causa da EC 132/2023 + LC 214/2025.
  2. Se `nfephp-org/sped-nfe` e `nfse-nacional/nfse-php` já suportam
     esses campos nas versões atuais.
  3. Se uma oficina no Simples Nacional em MG precisa preencher esses
     campos já em 2026 (fase "informativa"/teste) ou só a partir de
     set/2026.
  4. Qualquer obrigatoriedade de campo que cause rejeição SEFAZ mesmo
     com imposto zerado.

### Decisões da sessão 2026-07-25 (pós-pesquisa)
- **IBS/CBS na v1: estrutura preparada, não preenchida.** `NotaFiscalData`
  carrega os campos e o CRT do emitente, mas o `NfePhpProvider` só emite o
  bloco `IBSCBS` quando CRT=3 (Regime Normal). Simples Nacional emite sem o
  bloco — que é o que a lei permite até 04/01/2027, e a NT de orientação
  pro CRT=1 nem foi publicada (preencher hoje seria adivinhar).
- **Escopo da v1: NF-e + NFS-e juntas, com EPEC** (escopo original mantido).
  Eu cheguei a sugerir separar (NFS-e primeiro) por causa do prazo de
  01/09/2026 da Res. CGSN 189/2026, e o usuário chegou a aceitar — mas ao
  ser questionado ("pq vamos deixar produtos pra depois?") revisei e
  **voltei atrás**: esse prazo cobra emissão de NFS-e pelo padrão nacional,
  coisa que a oficina JÁ FAZ via Spedy/Focus. O motor NFePHP é adicional e
  gratuito, não o caminho de conformidade — logo o prazo não é bloqueante
  pra este projeto e separar só geraria retrabalho (OS mista quebrada +
  EPEC adiado por acidente). Usuário confirmou fazer junto.
- **Premissa a verificar fora do código (não bloqueia o design)**: Ilicínea
  usa `ilicinea-mg.prefeituramoderna.com.br`; se o município não aderiu ao
  ADN, a `nfse-nacional/nfse-php` não tem com quem falar. Res. CGSN
  189/2026 tende a resolver. Confirmar em `nfse.gov.br` ou com o contador.

### Design apresentado e aprovado (4 seções) — spec escrito
Apresentei o design em 4 seções, todas aprovadas pelo usuário:
1. Escopo revisado + premissas; 2. Arquitetura e componentes;
3. Numeração, contingência EPEC e PDFs; 4. Erro e estratégia de teste.

Spec commitado em
`docs/superpowers/specs/2026-07-25-motor-nfephp-design.md`.
Decisões de arquitetura registradas lá (não repetir aqui): interface
`FiscalProvider` NÃO muda — `NfePhpProvider` reinterpreta
`registrarEmissor()`/`enviarCertificado()` como validação local de
prontidão; `NotaFiscalData` vira envelope com `itens[]` (aditivo, Spedy/
Focus intocados); emissão passa a ser em fila pra TODOS os provedores;
número da NF-e alocado dentro do job (não no clique); retentativa sempre
consulta `sefazConsultaChave` antes (mesma lição das rodadas 7/8 de
pagamento); PDF renderizado sob demanda, não armazenado.

## Rodada 16 (mesma sessão) — REORDENAÇÃO: 3 etapas antes do NFePHP
Usuário disse: "ele será opcional, inclusive se precisar corrigir alguma
coisa no spedy e no focus corrija porque ele será os motores oficiais".
Isso mudou o sequenciamento — Spedy/Focus são os motores OFICIAIS e hoje
**não emitem NF-e**, só NFS-e. Fazer NFePHP primeiro faria a única
capacidade de emitir nota de peça chegar pelo motor opcional/experimental.
Usuário aprovou inverter.

**Roteiro acordado (nesta ordem):**
- **Etapa A (nova, primeiro)** — campos fiscais em `produtos` +
  corrigir a importação de NF-e por XML pra popular esses campos.
  Usuário pediu explicitamente etapa separada "pra fazer bem feito".
- **Etapa B** — refactor compartilhado (`NotaFiscalData` com `itens[]`,
  `EmissaoOrquestrador` da OS mista, emissão em fila) + **NF-e via API do
  Spedy/Focus** + os 5 defeitos abaixo.
- **Etapa C** — motor NFePHP (spec já escrito, commit `2411fd5`).

### 5 defeitos achados em Spedy/Focus (usuário mandou corrigir)
1. **XML nunca é armazenado (Focus)** — `FocusNfeProvider.php:195` grava
   `caminho_xml_nota_fiscal` (um PATH) em `notas_fiscais.xml_retorno`
   (coluna TEXT que deveria ter o XML). O documento fiscal legal nunca é
   arquivado de verdade. Corrigir: baixar o XML e persistir o conteúdo.
2. **Ambiente da Focus inferido de substring de URL** —
   `FocusNfeProvider.php:23` usa `str_contains($baseUrl,
   'api.focusnfe.com.br')` pra escolher entre `token_producao` e
   `token_homologacao`. NÃO está quebrado com os defaults, mas as URLs vêm
   de env e o `FiscalProviderManager::build()` JÁ recebe `$ambiente` e não
   repassa. Modo de falha: emitir nota real achando que está testando.
   Prova de que o risco é concreto: `sandbox-api.spedy.com.br` CONTÉM
   `api.spedy.com.br` — replicar o padrão no Spedy quebra de cara.
3. **Status desconhecido vira PROCESSANDO** nos dois
   (`SpedyProvider.php:160`, `FocusNfeProvider.php:160`) — `default` do
   `match` engole estado não mapeado; nota fica presa pra sempre. Piora
   com a mudança pra fila+polling. Corrigir: logar antes do default.
4. **`protocolo` recebe o mesmo valor de `numero`** nos dois
   (`SpedyProvider.php:192-193`, `FocusNfeProvider.php:193-194`).
5. **`naturezaOperacao` coletada e descartada** — existe em
   `NotaFiscalData` e nenhum dos dois payloads envia.

### Etapa A — investigação já feita (não repetir)
- **`produtos` NÃO tem NENHUM campo fiscal.** `grep -riE
  "ncm|cfop|csosn|cest|origem_mercadoria" app/ database/migrations/`
  retorna VAZIO no projeto inteiro. Sem NCM/CFOP/CSOSN/origem a SEFAZ
  rejeita a NF-e — é dado inexistente no banco, não detalhe de código.
- **A importação de NF por XML já recebe esses dados e os joga fora.**
  `NotaEntradaXmlParser.php:52-57` extrai só `cEAN`, `xProd`, `qCom`,
  `vUnCom` de `$det->prod`. Descarta `prod->NCM`, `prod->CFOP`,
  `prod->CEST`, `prod->uCom` e, dentro de `imposto->ICMS->*`, o `orig`
  (origem) e o `CST`/`CSOSN`. A tabela `notas_entrada_itens` também não
  tem colunas pra isso.
- **Regra de ouro da etapa A — nem todo campo se copia:**
  - NCM, CEST, origem → copiam direto (atributo da mercadoria).
  - **CFOP NÃO se copia** — o da entrada é de COMPRA (5405, 6404…);
    reusar na saída = venda com código de compra = rejeição. CFOP de
    saída é DERIVADO (dentro/fora do estado, com/sem ST).
  - CST/CSOSN do fornecedor não vira o nosso, mas é o melhor sinal
    disponível: CST 60 / CSOSN 500 = ICMS já recolhido por substituição
    tributária (caso dominante em MG). A importação passa a saber item a
    item quais peças são ST.
- Nó `ICMS` tem nome variável (`ICMS00`, `ICMS60`, `ICMSSN500`…) — o
  parser precisa iterar os filhos, não acessar por nome fixo.

### Base legal VERIFICADA (não repesquisar — usuário pediu confirmação)
**Contexto importante: o usuário NÃO tem contador.** Ele disse que confia
na pesquisa, mas isso aumenta a responsabilidade — separar sempre o que é
tabela verificável do que é juízo de classificação.

- **CSOSN — Ajuste SINIEF 03/2010, Anexo Único, Tabela B** (conferido no
  texto do próprio CONFAZ). Códigos com ST: **201, 202, 203, 500**. ✅
  (Ajuste SINIEF 14/2019 revogou o Anexo I do Ajuste SINIEF 07/2005 a
  partir de 01/01/2022 — a referência válida hoje é o 03/2010.)
- **CST — Tabela B do Anexo do Convênio SINIEF s/nº de 15/12/1970.**
  Com ST: **10** (tributada c/ ST), **30** (isenta/não tributada c/ ST),
  **60** (ICMS cobrado anteriormente por ST — caso dominante de peça de
  reposição), **70** (redução de base c/ ST). ✅
- **Origem da mercadoria — Tabela A do mesmo anexo: 0 a 8.** ✅
  (confirma `origem smallint` 0..8)
- **⚠️ A tabela CST é instável:** o **Ajuste SINIEF 39/2023** criaria os
  CST **12, 13, 52, 72, 74** (todos de ST) a partir de 01/04/2024, e o
  **Ajuste SINIEF 20/2024** (09/07/2024) REVOGOU essa criação. Há fonte
  estadual (SEFAZ-PB) publicando a tabela COM esses códigos — versões
  conflitantes circulando, e ajuste futuro pode ressuscitá-los.
- **[decisão] Por causa disso, NÃO usar lista fixa com `else` → NORMAL.**
  Regra: conjunto conhecido de ST → `ST`; conjunto conhecido de não-ST →
  `NORMAL`; **código desconhecido → NÃO adivinha, vira pendência.**
  É a mesma doença do defeito 3 de Spedy/Focus (`default` do `match`
  engolindo caso não previsto) — não repetir numa tabela que a
  legislação comprovadamente altera.

### Fonte extra do usuário (2026-07-26) — confirma a pesquisa + 1 fato novo
Usuário compartilhou `https://blog.spedy.com.br/cbs-e-ibs-na-nota-fiscal/`
(disse que vai compartilhar links assim quando forem pertinentes).
- **Confirma** tudo que já estava levantado: 03/08/2026 obrigatório p/ regime
  normal, Simples Nacional só em jan/2027, NT 2025.002-RTC v1.40, alíquotas
  de teste CBS 0,9% / IBS 0,1% compensadas com PIS/Cofins. Dá também o
  código de rejeição da SEFAZ: **1115**.
- **FATO NOVO, não estava em nenhuma pesquisa anterior**: **setembro/2026 é
  o prazo para o Simples Nacional decidir se adota REGIME HÍBRIDO em 2027**,
  para gerar crédito transferível a clientes PJ. Não muda código nenhum,
  mas é decisão de negócio com data: se a oficina atende PJ (frota,
  locadora, transportadora), sem regime híbrido a nota dela deixa de gerar
  crédito de CBS/IBS em 2027 e o comprador PJ ganha incentivo pra trocar de
  fornecedor. Se atende só PF, irrelevante. Usuário NÃO tem contador —
  recomendei consultar um só pra essa pergunta pontual.
- **Reforça a decisão de design já tomada** (estrutura de IBS/CBS preparada,
  não preenchida): se ele optar pelo híbrido, passa a precisar destacar os
  campos em 2027 e a estrutura já estará pronta pro mapeamento.

### Limites do que NÃO dá pra verificar por pesquisa (dizer ao usuário)
1. **Qual NCM classifica uma peça específica** — juízo sobre a
   mercadoria, não consulta de tabela. MAS: no fluxo dele isso quase se
   resolve sozinho, porque o NCM vem assinado na NF-e do fornecedor e a
   importação passa a lê-lo. O padrão por categoria é só rede de
   segurança pra produto cadastrado na mão.
2. **Alíquota de ISS de Ilicínea** — lei municipal, não indexada. A
   PREFEITURA informa por telefone; não precisa de contador.
3. **Se uma peça está na lista de ST de MG** — lista estadual (Anexo do
   RICMS/MG). CST do fornecedor é evidência forte, não prova.

### Etapa A — design aprovado e spec escrito
Design apresentado em 2 seções, ambas aprovadas. Spec em
`docs/superpowers/specs/2026-07-25-campos-fiscais-produtos-design.md`.
Decisões (detalhe no spec, não repetir aqui): colunas fiscais em
`produtos` + `fiscal_fonte`/`fiscal_revisado_em` (distinguem "conferido
por gente" de "herdado do padrão"); `notas_entrada_itens` guarda o valor
BRUTO do fornecedor como auditoria; tabelas novas
`produto_fiscal_divergencias` e `categoria_padrao_fiscal` (ambas
tenant-scoped — `Produto` usa `HasTenantScope`); padrões por categoria
nascem VAZIOS (não invento NCM); CFOP NÃO é coluna de produto;
divergência não sobrescreve; importação nunca falha inteira por dado
fiscal; valor malformado não é gravado (vazio é visível, lixo passa por
preenchido). Testes desta etapa RODAM localmente (lógica pura, sem DB).

Spec do NFePHP recebeu nota de sequenciamento no topo (é a etapa C).

### Etapa A — PLANO ESCRITO (commit `42260fb`)
`docs/superpowers/plans/2026-07-25-campos-fiscais-produtos.md` — 12 tasks.
Autorrevisão do plano pegou 4 problemas reais, todos já corrigidos no
arquivo (registrados aqui porque são decisões, não detalhe):
1. `fiscal_pendente` e a query de pendências incluíam
   `fiscal_revisado_em IS NULL` — isso manteria TODO produto preenchido
   por XML na lista de pendências pra sempre. Dado do fornecedor é
   confiável e não exige conferência humana. Removido dos dois lugares.
2. A aplicação dos dados fiscais estava DENTRO do `DB::transaction` da
   importação — violava a regra "importação nunca falha inteira por dado
   fiscal". E `try/catch` dentro de transação no Postgres não resolve: o
   primeiro erro aborta a transação inteira. Movido pra DEPOIS do commit,
   com try/catch + `Log::warning`.
3/4. Dois pontos vagos (`eStyle`, link de Configurações) viraram código
   concreto — o plano é executado por agente sem contexto do repo.

## Rodada 17 — ETAPA A IMPLEMENTADA (12 tasks + revisão final + 3 ondas de fix)
Executada via `superpowers:subagent-driven-development` direto na `main`
(consentimento explícito do usuário, mesmo fluxo da rodada 12). Range completo:
`cd111f9..3a59f13`. **80 testes unitários passando** (eram 65 no início da
execução), `npx tsc --noEmit` e `npm run build` limpos.

### O que foi entregue
Colunas fiscais em `produtos` (`ncm`, `cest`, `origem`, `tributacao_icms`,
`fiscal_fonte`, `fiscal_revisado_em`) + `*_xml` em `notas_entrada_itens` +
tabelas `produto_fiscal_divergencias` e `categoria_padrao_fiscal`. Parser de
NF-e passa a extrair NCM/CFOP/CEST/origem/CST-CSOSN e derivar a tributação em
3 estados. Política de conflito que nunca sobrescreve. Telas: bloco fiscal no
formulário de produto, pendências fiscais (com paginação, filtro e resolução de
divergência), padrões por categoria, e coluna fiscal na conferência de entrada.

### Revisão final de branch (opus): 0 Critical, 6 Important, 6 Minor
Todos os Important corrigidos. Os mais relevantes, porque são erros de DESIGN
meus e não de implementação:
1. Produto marcado "revise" não conseguia ser marcado como revisado ao ser
   confirmado — ficava pendente pra sempre. Corrigido com **botão explícito
   "Marcar como revisado"** (escolha do usuário), que recusa produto sem NCM.
2. `MANUAL` mascarava chute de categoria na criação — corrigido invertendo duas
   linhas em `store()`.
3. Tela de pendências carregava o catálogo inteiro no dia 1 (todo produto nasce
   sem NCM). Paginada + filtro por categoria.
4. `POST /produtos` dava 500 se o cliente omitisse os campos fiscais.
5. Nome de coluna dinâmico em atribuição em massa, sem guarda no ponto de escrita.

### ⚠️ Padrão que se repetiu — LER ANTES DA ETAPA B
- **Fix introduzindo defeito novo, 2x:** (a) o conserto do `0` falso em JS foi
  reimplementado com `empty()` em PHP, reintroduzindo o MESMO bug; (b) apertar
  a validação da importação fez uma nota inteira ser rejeitada por um campo ruim
  num item — violando a restrição global "a importação nunca falha inteira".
  Ambos por instrução minha pedindo mais do que era necessário.
- **`0` é valor fiscal VÁLIDO** (origem 0 = nacional). Defeito reincidiu 4x.
  Ver memória `project-zero-e-valor-fiscal-valido`.
- **Os 3 achados mais valiosos vieram de verificação DIRIGIDA**, não de "revise o
  diff": dar ao revisor uma tese concreta pra checar é o que produziu resultado.

### Pendências registradas, não corrigidas
- `ProdutoController::update()` — se o usuário submeter lixo num campo fiscal que
  já tinha valor real (com `ncm` válido intacto), `wasChanged()` dispara e carimba
  MANUAL mesmo o valor tendo virado null. Não esconde pendência (a fórmula olha
  `ncm`/`PADRAO`), lógica pré-existente, sem cobertura de teste.
- `max:N` na importação ainda derruba o lote se um valor for MAIOR que o limite.
  Baseline pré-existente; o parser só entrega 8-dígitos-ou-null e a tela de
  conferência não deixa editar campo fiscal, então não é alcançável na prática.
- `categoria` não restrita a `CATEGORIAS` no `PUT /categorias-fiscais` (linha órfã).
- Sem CHECK no banco pra `produto_fiscal_divergencias.campo` (só guarda de aplicação).
- **Feature tests nunca foram escritos** — recomendação explícita da revisão final:
  escrever ANTES da etapa B, porque lá é emissão e bug de numeração/tenancy não se
  conserta editando produto depois.

### Falta: DEPLOY + validação manual
Nada disso rodou contra banco de verdade. Após `git pull` + `bash deploy-vps.sh`:
1. `php artisan migrate:status` no container — as 4 migrations `2026_07_25_*` como `Ran`.
2. Importar XML real de fornecedor e conferir os 3 comportamentos: (a) produto novo
   nasce com NCM; (b) produto existente com NCM diferente NÃO é sobrescrito e gera
   divergência; (c) tela de pendências lista o que falta.
   A política de conflito tem teste da DECISÃO, zero cobertura da PERSISTÊNCIA —
   essa validação manual é a única prova real.

## Rodada 18 (nova sessão) — deploy da Etapa A + Feature tests
Usuário confirmou deploy ("faça o depósito depois continue o desenvolvimento" —
autocorretor pra "deploy"). Fluxo:

- **Deploy**: `git pull origin main` na VPS (fast-forward `0712887..7eca748`, 35
  arquivos) + `bash deploy-vps.sh` em background (build `--no-cache` demora
  ~10min, compila extensões PHP do zero). Antes de puxar, chequei
  `git status`/`git diff` na VPS por causa da lição registrada em
  `feedback-deploy` — havia 1 modificação local não commitada em
  `docker/nginx/tenant-slugs.map` (linha `oficina-do-lundy...` adicionada
  em runtime pelo provisionamento de tenant), não tocada por nenhum commit
  da Etapa A, preservada pelo merge (fast-forward simples, sem conflito).
- **Feature tests da Etapa A escritos**: `backend/tests/Feature/ProdutoFiscalTest.php`
  (novo, 13 testes) — cobre exatamente a lacuna que a revisão final da rodada 17
  apontou (política de conflito só tinha teste da DECISÃO em memória, zero da
  PERSISTÊNCIA real via HTTP+Postgres):
  - Produto novo nasce com NCM/CEST/origem/tributação do XML.
  - Produto novo sem dado no XML recebe o padrão da categoria (`fiscal_fonte=PADRAO`).
  - Produto existente com NCM vazio é preenchido pelo XML.
  - Produto existente com NCM já revisado (`MANUAL`) **não é sobrescrito** e gera
    `produto_fiscal_divergencias` — a garantia central da Etapa A, antes só
    testada em memória.
  - Regressão dedicada pro bug "0 é valor fiscal válido" que reincidiu 4x
    (rodada 17): `origem=0` do XML é persistido, não tratado como vazio.
  - `GET /produtos/pendencias-fiscais` lista produto sem NCM + produto com
    divergência aberta, exclui produto já revisado.
  - `marcar-revisado` recusa produto sem NCM / confirma produto com NCM.
  - `resolver-divergencia` nos dois ramos (ACEITOU_XML atualiza o produto;
    MANTEVE preserva o valor atual), ambos marcando a divergência resolvida.
  - `categorias-fiscais` (PUT+GET) round-trip e **isolamento entre oficinas**
    (padrão da oficina A não vaza pra oficina B — `categoria_padrao_fiscal`
    é tenant-scoped via `HasTenantScope`).
  - Todos os testes que envolvem `produto_fiscal_divergencias` ou
    `categoria_padrao_fiscal` precisam de `X-Tenant` real (essas tabelas têm
    `oficina_id` NOT NULL com FK — sem tenant no request, `TenancyContext::get()`
    seria null e o insert falharia; segue o padrão de
    `OrdemServicoNumeracaoTest::criarOficinaComAdmin()` já usado no projeto).
  - `php -l` limpo. **Não rodei os testes** — confirmado de novo que não há
    Postgres/Docker local (ver `feedback-local-testing`); precisam rodar em
    CI ou banco de teste dedicado antes de considerar a Etapa A 100%
    coberta. Nunca rodar `php artisan test` na VPS de produção
    (`RefreshDatabase` dropa o banco).

## Próxima tarefa (retomar exatamente aqui)
- [x] **DEPLOY DA ETAPA A** — feito na rodada 18 (2026-08-02). `git pull`
  fast-forward `0712887..7eca748` + `bash deploy-vps.sh`. Validado: HEAD na
  VPS = `7eca748`; as 4 migrations `2026_07_25_*` como `Ran`; domínios
  públicos `saas`/`stuntmotos`/`oficina-do-lundy`/`oficina` respondendo 200
  em `/api/health`; `produtos/pendencias-fiscais` e `categorias-fiscais`
  respondendo 401 sem token (rotas novas existem e estão protegidas).
- [x] **Feature tests da Etapa A** — `backend/tests/Feature/ProdutoFiscalTest.php`
  (rodada 18, 13 testes, detalhe acima). `php -l` limpo, **nunca executados**
  contra Postgres real (limitação de ambiente local, não do teste em si) —
  falta rodar em CI ou banco de teste dedicado pra fechar 100% a cobertura.
- [ ] **Validação manual com XML real de fornecedor em produção** — ainda
  pendente do usuário (os feature tests cobrem a persistência em teoria, mas
  só um XML real confirma que o parser lê corretamente notas do mundo real,
  não só o fixture do teste): (a) produto novo nasce com NCM; (b) produto
  existente com NCM diferente NÃO é sobrescrito e gera divergência; (c) tela
  de pendências fiscais lista o resíduo.
1. Próximo: desenhar a **etapa B** (spec ainda não existe) — refactor
   compartilhado (`NotaFiscalData` com `itens[]`, `EmissaoOrquestrador` da OS
   mista, emissão em fila) + NF-e no Spedy/Focus + os 5 defeitos registrados
   na rodada 16. **Merece uma sessão de brainstorming com o usuário antes do
   spec** (mesmo padrão usado na Etapa A e na rodada 15 do NFePHP) — não
   pular direto pro código.
2. Depois: a etapa C (NFePHP), cujo spec já está escrito em
   `docs/superpowers/specs/2026-07-25-motor-nfephp-design.md`. Aguardando
   aprovação ou pedidos de mudança.
3. Verificações de fato que dependem do usuário (não bloqueiam escrever
   os planos; bloqueiam a validação em homologação das etapas B/C):
   adesão de Ilicínea ao ADN (`nfse.gov.br`); **alíquota de ISS de
   Ilicínea — a PREFEITURA informa, o usuário NÃO tem contador**.
4. Pendências mais antigas, ainda não feitas: usuário validar
   manualmente rodada 12 (notificações) e rodada 13 (agendador — checar
   `docker compose logs scheduler` pros horários reais de disparo).

## Rodada 19 (2026-08-03) — Etapa B implementada via subagent-driven-development

Brainstorming da Etapa B (5 seções, todas aprovadas) → spec commitado em
`docs/superpowers/specs/2026-08-02-etapa-b-refactor-nfe-design.md` → plano de
12 tasks commitado em `docs/superpowers/plans/2026-08-02-etapa-b-refactor-nfe.md`
→ executado com `superpowers:subagent-driven-development` direto na `main`
(consentimento explícito do usuário, mesmo padrão da rodada 12/17). Ledger
completo em `.superpowers/sdd/2026-08-02-etapa-b-refactor-nfe/progress.md`.

### O que foi entregue
- `CfopSaidaResolver` e `TributacaoIcmsSaidaResolver` (CFOP/CST-CSOSN de
  saída, nunca default silencioso — combinação não coberta lança exceção).
- `NotaFiscalData` ganhou `modelo`/`itens[]` de forma aditiva (NFS-e intocada).
- Nova tabela `notas_fiscais_itens` + model `NotaFiscalItem` + relação
  `NotaFiscal::itens()`.
- **Focus NFe emite NF-e** (`POST /nfe`, confirmado contra a doc oficial
  nesta sessão) — com os defeitos #1 (XML real baixado, não só o path) e #4
  (protocolo nunca reusa número) corrigidos no fluxo novo.
- Defeitos #2 (ambiente explícito, só na Focus — Spedy nunca teve esse bug),
  #3 (status desconhecido loga warning) e #5 (naturezaOperacao no payload)
  corrigidos nos dois provedores.
- `NotaFiscalController::store()` deriva `modelo`, rejeita `Misto` (422,
  servidor E cliente), persiste itens com CFOP/CST-CSOSN calculados.
- `NfeService::montarNotaData()` monta os itens de NF-e a partir de
  `notas_fiscais_itens` — tradução cuidadosa entre a convenção do DTO
  (`'NFE'`/`'NFSE'`, sem hífen) e a coluna do banco (`'NF-e'`/`'NFS-e'`,
  com hífen, coluna que **já existia** e nunca tinha sido usada por nenhum
  controller).
- Frontend (`NotaFiscalForm.tsx`): "Misto" desabilitado, itens de venda
  viram `<select>` de produto (herdando NCM/origem/tributação da Etapa A),
  serviço continua texto livre.
- 102 testes Unit locais passando (up de ~80 no início da rodada).

### Spedy NÃO ganhou NF-e nesta rodada (Task 7 pulada por decisão do usuário)
`docs.spedy.com.br` bloqueou acesso automatizado (403) tanto no brainstorming
quanto na tentativa de implementação — sem sandbox/credenciais, o plano
proíbe explicitamente adivinhar schema de payload fiscal real. Usuário optou
por pular a task agora. **Guarda de segurança aplicada** (`SpedyProvider::
emitir()` rejeita `modelo=NFE` com mensagem clara) pra evitar que uma oficina
na Spedy gere uma NF-e malformada via o fluxo de NFS-e por engano — não é a
implementação real, só evita o pior enquanto isso não acontece.
**Retomar quando o usuário tiver acesso ao portal/sandbox da Spedy.**

### 3 rondas de correção — todas por bugs no meu próprio texto do plano/brief, não dos implementadores
1. **Task 6 (Focus NF-e)**: download do XML sem tratamento de falha —
   corrigido pra degradar a `xml: null` + log, sem derrubar uma emissão já
   autorizada pela SEFAZ por causa de um erro de rede no passo seguinte.
2. **Task 8 (`store()`)**: meu próprio brief tinha fallback silencioso
   (`'MG'`/`'Simples Nacional'`) que contradizia o Global Constraint que eu
   mesmo escrevi no topo do plano. Escalei pro usuário (regra do
   subagent-driven-development pra achado "plan-mandated") — confirmado:
   **erro claro, nunca chutar**. Também apliquei o mesmo princípio a
   `tributacao_icms` (defaultar pra NORMAL seria uma afirmação específica
   errada, diferente de NCM nulo que é só uma lacuna visível — essa
   distinção foi mantida). Achado de fora do escopo, não corrigido: o mesmo
   padrão de fallback existe em `RegistrarEmissorService.php:27`.
3. **Task 11 (frontend)**: minha prosa do brief dizia "valide todo item"
   mas meu código de exemplo só validava "pelo menos um" e descartava em
   silêncio os itens sem produto selecionado — um NF-e podia sair faltando
   linha sem aviso nenhum. Corrigido aplicando a mesma política já
   confirmada 2x nesta rodada, sem precisar perguntar de novo.

### Revisão final de branch inteira (opus) — achou 3 Critical reais, todos corrigidos
A revisão final (per-task já tinha passado tudo limpo) pegou o que as
revisões individuais, cada uma olhando um diff isolado, não conseguiriam ver
— uma propriedade cross-cutting quebrada. Todos os 3 Critical eram bugs no
meu próprio texto do plano/brief, mascarados por `Http::fake()` com
wildcards largos demais nos testes:
1. **Endpoint da Focus pra NF-e sem o prefixo `/v2`** (`/nfe` em vez de
   `/v2/nfe`, único método divergente dos outros 4 da mesma classe) — toda
   emissão real teria dado 404. Corrigido + testes com o path completo
   (não mais wildcard prefixo, que escondia o bug).
2. **Download do XML falha em silêncio com path relativo** — a Focus
   devolve path relativo de verdade (o próprio fixture de NFS-e já
   existente no repo já usava isso), o teste novo usou URL absoluta por
   engano e mascarou o bug. Corrigido: prefixa `baseUrl` quando o path não
   começa com `http`.
3. **`origem` nulo virava `0` ("mercadoria nacional") em silêncio** —
   diferente de NCM nulo (lacuna visível, decisão de design deliberada e
   mantida intocada), `origem` ausente sendo tratado como 0 é uma afirmação
   fiscal específica e possivelmente errada. Corrigido com o mesmo padrão
   de guarda 422 já usado pra `tributacao_icms`.

Mais 6 Important corrigidos na mesma rodada: campo real de protocolo da
Focus é `numero_protocolo` (não `protocolo`, que sempre resolvia null);
defeitos #1/#4 só tinham sido corrigidos no fluxo novo de NF-e, não no
fluxo antigo de NFS-e (corrigido nos dois provedores); frontend mostrava
desconto/ISS que o backend ignorava em venda de mercadoria (corrigido,
oculta os campos); número real da NF-e atribuído pelo provedor era
descartado, ficando só o contador interno (corrigido, persiste
`$resultado['numero']`); cobertura de teste sem CFOP/CST-CSOSN nem cenário
interestadual nem round-trip de emissão (adicionados). 104 testes Unit
locais passando (up de 102).

**Deliberadamente NÃO corrigido nesta rodada (registrado como limitação
conhecida, não bloqueante pro merge, mas bloqueante pra considerar "NF-e
end-to-end usável em produção")**: não existe polling/webhook/reconciliação
pra nota em status PROCESSANDO, e `consultar()`/`cancelar()` continuam
hardcoded pra `/v2/nfse` mesmo quando a nota é NF-e — é trabalho novo real
(job de reconciliação, ou webhook), não um fix rápido. Fica pra uma etapa
B2 ou ticket separado.

Minors registrados, não corrigidos (baixo risco): `unidade_comercial`
hardcoded 'UN' (produtos.unidade existe mas não é usado no payload);
`codigo_produto` envia UUID em vez de SKU (aparece no DANFE); sem índice em
`notas_fiscais_itens.nota_fiscal_id`/`oficina_id` (seq scan em toda
consulta/cascade); `RegistrarEmissorService.php:27` tem o mesmo padrão de
fallback silencioso já eliminado do resto do fluxo (fora do escopo desta
etapa).

### O que falta antes de considerar a emissão de NF-e pronta pra produção
- **Feature tests nunca executados contra Postgres real** (todos os novos em
  `NotaFiscalNfeTest`, `NfeServiceTest`) — precisam rodar em CI/banco
  dedicado antes de confiar na cobertura.
- **Task 7 (Spedy NF-e) real** — bloqueada por acesso à doc/sandbox da Spedy.
- **Reconciliação de status PROCESSANDO** (achado 8, acima) — sem isso, uma
  NF-e que a Focus processa assincronamente fica presa sem forma de ser
  consultada/cancelada corretamente pelo sistema.
- Deploy + validação manual em homologação (Focus) antes de considerar a
  emissão de NF-e pronta pra produção de verdade.

## Backlog geral de pendências (consolidado em 2026-08-03 — corrigir depois)

Lista única de tudo que ficou pendente até agora, pra não se perder entre
rodadas. Cada item cita a rodada de origem.

### Validações manuais que dependem do usuário
- [ ] **Rodada 11** — validar que o download de PDF/recibo/NF/relatório/
  backup abre de fato numa oficina que não seja o domínio base (ex.:
  `stuntmotos.dlsistemas.com.br`), depois do fix de CORS cross-subdomínio.
  Nunca teve confirmação registrada.
- [ ] **Rodada 12** — abrir uma notificação manual publicada, fechar, e
  confirmar que ela só reaparece depois do `intervalo_minutos` configurado
  (era exatamente o bug do fuso/Carbon 3, corrigido mas nunca validado na
  prática). Conferir também a aba "Cobrança" em `/saas-admin/notificacoes`
  com uma oficina que tenha fatura pendente/vencida.
- [ ] **Rodada 13** — checar `docker compose logs scheduler` na VPS e
  confirmar que os horários reais de disparo batem (`cobrancas:gerar` 06h,
  `alertas:verificar` 07h, `oficina:recalcular-status-clientes` 02h,
  horário de Brasília).
- [ ] **Rodadas 14-19 (fiscal)** — confirmar a alíquota real de ISS de
  Ilicínea (só a prefeitura informa por telefone, usuário não tem contador)
  e a adesão do município ao ADN (`nfse.gov.br`). Bloqueia validação em
  homologação das Etapas B e C.

### Etapa B — pendências técnicas
- [ ] Feature tests nunca executados contra Postgres real
  (`NotaFiscalNfeTest`, `NfeServiceTest`) — precisam de CI/banco dedicado.
- [ ] Task 7 real (NF-e via Spedy) — bloqueada por acesso à doc/sandbox.
- [ ] Reconciliação de status PROCESSANDO pra NF-e (sem polling/webhook,
  uma nota presa em processamento não tem como ser consultada/cancelada
  direito) — trabalho novo, não fix rápido.
- [ ] Deploy da Etapa B + validação manual em homologação com a Focus real.
- [ ] Minors de baixo risco: `unidade_comercial` hardcoded 'UN' (ignora
  `produtos.unidade`); `codigo_produto` manda UUID em vez de SKU (aparece
  no DANFE); falta índice em `notas_fiscais_itens.nota_fiscal_id`/
  `oficina_id`; `RegistrarEmissorService.php:27` tem o mesmo padrão de
  fallback silencioso (`?? 'Simples Nacional'`) já eliminado do resto do
  fluxo fiscal.

### Trabalho adiado por decisão (não é bug, é escopo pra depois)
- [ ] **EmissaoOrquestrador de OS mista** — gerar NF-e + NFS-e
  automaticamente a partir de uma OS com peça+serviço, com um clique. Vira
  uma "Etapa B2".
- [ ] **Emissão em fila (Horizon)** — adiada até o orquestrador acima ou o
  NFePHP (que precisa de EPEC assíncrono) exigirem de verdade.

Etapa B foi commitada e empurrada pro GitHub (`5c47bd2..693629c`) em
2026-08-03. Deploy ainda não feito — aguardando decisão do usuário.

## Rodada 20 (2026-08-03) — Etapa C1 (motor NFePHP, NFS-e) implementada em worktree isolado

Spec do NFePHP (rodada 15/16) revisado à luz do que a Etapa B realmente
entregou → plano de 8 tasks (`docs/superpowers/plans/2026-08-03-etapa-c1-nfephp-nfse.md`)
→ executado com `superpowers:subagent-driven-development` num **worktree
isolado** (`.claude/worktrees/etapa-c1-nfephp-nfse`, branch
`worktree-etapa-c1-nfephp-nfse`) — primeira vez nesta sessão usando
isolamento em vez de commitar direto na main, por envolver uma dependência
externa nova/experimental. Ledger completo no próprio worktree.

### Decisão de escopo confirmada ao retomar (2026-08-03)
NFePHP fica **no mesmo nível da Etapa B**: emissão manual e síncrona, sem
`EmissaoOrquestrador` de OS mista, sem fila. Motor NFePHP cobre só **NFS-e**
nesta etapa (via `nfse-nacional/nfse-php`) — NF-e via `sped-nfe` + EPEC
fica pra um plano C2 separado, ainda não escrito.

### O que foi entregue
- `nfse-nacional/nfse-php` instalado (pacote **beta-only**, `^1.21@beta`,
  com dependência transitiva `spatie/data-transfer-object` marcada
  "abandoned" — risco de supply-chain já catalogado no spec, reforçado
  aqui).
- `CrtResolver` (deriva CRT de `regime_tributario`) e `CertificadoStore`
  (decifra o `.pfx` sob demanda; certificado nunca fica em disco além de
  um arquivo temporário efêmero, 0600, apagado em `finally` mesmo se a
  chamada falhar).
- `EmissaoResultado::erro()` — status novo, distingue falha técnica
  (`ERRO`, HTTP 500) de rejeição do fisco (`REJEITADA`, HTTP 422).
- `NfePhpProvider` implementa `FiscalProvider` sem mudar a interface —
  `registrarEmissor()`/`enviarCertificado()` viram validação local (nunca
  chamam nada externo); NF-e retorna rejeição clara (motor NFePHP de NF-e
  é o plano C2, ainda não existe).
- `MotorNfse` emite/consulta/cancela NFS-e de verdade via
  `nfse-nacional/nfse-php`. **Cancelamento real implementado** (evento
  101101) — o plano original achava que isso não seria confirmável e
  previa um placeholder; o implementador encontrou o payload completo no
  próprio exemplo da biblioteca instalada.
- PDF da DANFSe usa `downloadDanfse()` da própria biblioteca em vez de um
  template DomPDF próprio (decisão tomada nesta sessão, mais simples que o
  plano original de 2026-07-25 previa).
- 126 testes Unit locais passando (up de 122 no início da rodada).

### Disciplina "verificar contra a biblioteca real, não adivinhar" — o achado mais importante desta rodada
O spec e o plano foram escritos a partir de pesquisa web, **antes** da
biblioteca estar instalada. Nas tasks de integração real (`MotorNfse`),
instruí os implementadores a conferir cada suposição contra
`vendor/nfse-nacional/nfse-php/src/` e os exemplos reais, não transcrever o
plano cegamente. Isso pagou:
- Corrigiu um bug real que eu mesmo deixei no plano: `issRetido` invertido.
- Achou que a tradução CRT→`opSimpNac` (que eu tinha marcado como
  "defensiva") é na verdade **exigida pelo próprio XSD** (`regTrib` sem
  `minOccurs`, ou seja, obrigatório) — sem ela nenhuma NFS-e seria
  validada.
- Achou cancelamento real (ver acima) onde o plano previa desistir.
- Descobriu que `consultar()` da biblioteca **engole a exceção e devolve
  null** em vez de lançar (o plano assumia o contrário).

### Revisão final de branch (opus) — achou 1 Critical real e 5 Important, nenhuma revisão por task isolada teria pego
Exatamente a classe de bug que a revisão final existe pra capturar —
propriedades cross-cutting que atravessam várias tasks:
1. **Critical**: `notas_fiscais.chave_acesso` era `varchar(50)`, curto
   demais pra chave real de NFS-e nacional (53 caracteres) — uma NFS-e
   **autorizada de verdade** falharia ao salvar e seria gravada como
   `REJEITADA`, com a chave perdida. Corrigido com migration
   (`chave_acesso` → 60, `numero` → `bigint`, mesmo problema por já ser
   `integer` mas `nNFSe` poder ter 13 dígitos).
2. **Important**: `NFEPHP` não era selecionável por nenhum caminho de
   API/UI (só `SPEDY`/`FOCUS` nas validações e nos selects do
   saas-admin) — a etapa inteira ficaria inacessível sem escrita direta no
   banco. Corrigido.
3. **Important**: `MotorNfse::consultar()` sempre devolvia `AUTORIZADA`,
   mesmo pra uma nota cancelada por essa mesma classe — a NFS-e nacional
   registra cancelamento como evento separado (101101), não como
   `cStat`. Corrigido: extrai a lógica de mapeamento pra um método puro e
   testável, checa evento de cancelamento antes de afirmar autorizada,
   nunca cai em `AUTORIZADA` por default. **Sem exposição real hoje**
   (nada chama `consultar()` ainda) — mas corrigido antes que algo passe a
   chamar.
4. **Important**: duas fontes de verdade pro ambiente dentro de
   `MotorNfse` (`montarDps()` lia `Configuracao.ambiente_fiscal` direto,
   o resto do arquivo usava o parâmetro `$ambiente`) — corrigido pra uma
   fonte só.
5. Pill de status `ERRO` faltando no frontend — corrigido.

**Pendência registrada, não bloqueante** (`consultar()` ainda sem nenhum
caller): quando a checagem de evento de cancelamento falha por
instabilidade de rede, o código atual trata como "não cancelado" em vez de
retornar `erro()` — deveria falhar visível em vez de arriscar permitir
`AUTORIZADA` por baixo de uma falha de rede. Resolver antes de qualquer job
de sincronização de status vir a chamar `consultar()`.

### O que falta antes de considerar a Etapa C1 pronta pra produção
- **Merge do worktree pra main** — ainda não feito, aguardando decisão do
  usuário (`superpowers:finishing-a-development-branch`).
- **Numeração de DPS hardcoded em `'1'`** — sinalizado desde o plano
  original como limitação conhecida, mas a revisão final destacou que isso
  é mais urgente do que parecia: como o `Id` da DPS é composto de
  CNPJ+município+série+**número**, toda emissão vai gerar o mesmo `Id` —
  **a segunda emissão, já no teste de homologação, seria rejeitada como
  duplicata**. Resolver a numeração antes do primeiro teste real, não só
  "antes de produção".
- **Endereço do prestador (`prest.end`) nunca preenchido** — confirmado
  via XSD que é opcional (`minOccurs=0`, não bloqueia emissão), mas
  `Configuracao` não tem campos de endereço decompostos pra preencher
  quando quisermos. Fica pra quando isso for adicionado.
- **`opSimpNac` assume ME/EPP, nunca MEI** — `Configuracao` não distingue.
  Risco real se a oficina de Ilicínea for MEI (não confirmado).
- **`cMotivo` do cancelamento hardcoded em `'9'`/Outros** — sem seletor de
  motivo estruturado ainda.
- **Feature tests da Etapa C1 nunca executados contra Postgres real** —
  mesma limitação de sempre.
- **Validação em homologação de verdade** — nada disso rodou contra a API
  real da NFS-e nacional ainda; confirmar em especial o comportamento de
  `listarEventos()` pra "nenhum evento" (array vazio vs. exceção) antes de
  confiar em `consultar()`.
- Confirmar adesão de Ilicínea ao ADN e a alíquota real de ISS (pendência
  antiga, ainda não resolvida).
