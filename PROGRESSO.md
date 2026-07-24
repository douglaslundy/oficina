# Progresso do Projeto

## Última atualização
2026-07-19

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
- **Ainda não commitado no PROGRESSO.md antes disso, não deployado.**
  Perguntar ao usuário sobre deploy antes de seguir pro próximo pedido
  (era a instrução original: "ao concluir essa tarefa me pergunte se
  desejo fazer o deploy antes de iniciar a próxima tarefa").

## Próxima tarefa
1. Perguntar ao usuário se quer deploy da Rodada 12 agora.
2. Se sim: `git push`, `git pull` na VPS, `bash deploy-vps.sh`, validar
   domínios (mesmo padrão das rodadas anteriores) — e idealmente rodar
   `php artisan test tests/Feature` no container de produção/homologação
   pra validar de verdade os testes que só puderam ser conferidos por
   `php -l` localmente.
3. Depois do deploy (ou se o usuário preferir pular): seguir para o
   pedido ainda pendente da mesma mensagem original — estudo de
   viabilidade fiscal do NFePHP como motor gratuito adicional (contexto
   Ilicínea/MG, IBGE 3130507), comparando com os motores pagos já
   existentes (Spedy/Focus NFe) e dizendo se é seguro prosseguir.
   usuário na mesma mensagem, ainda não iniciados.
