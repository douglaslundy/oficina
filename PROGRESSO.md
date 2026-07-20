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

## Próxima tarefa
1. Deploy da rodada 5.
2. **Testar de verdade** (ambiente é `producao`, dinheiro real): cartão
   (conferir se detecta bandeira/parcelas) e PIX (QR code + confirmação
   automática via polling/webhook). Usar valor baixo numa cobrança avulsa.
3. Confirmar visualmente que os campos de cartão agora parecem parte do
   sistema (fundo escuro, sem "janela" da MP aparecendo).
