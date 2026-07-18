# Motor de cobrança recorrente — design

## Contexto

O SaaS cobra a mensalidade das oficinas hoje via *subscription* nativa do gateway
(Asaas `subscriptions` ou Mercado Pago `preapproval`), que debita automaticamente
no ciclo do próprio gateway. Isso não dá controle sobre:

- em que data a cobrança é gerada em relação ao vencimento;
- a oficina escolher PIX ou cartão a cada cobrança (MP `preapproval` debita o
  cartão salvo direto, sem escolha);
- suspensão automática por atraso e voto de confiança (specs futuras).

Esta spec substitui o motor de cobrança por um calendário próprio por oficina:
o sistema decide quando gerar a cobrança (cobrança avulsa, já suportada nos dois
gateways) e reage ao pagamento via webhook.

Bug lateral corrigido nesta sessão antes desta spec: `OficinaController` tinha
4 métodos hardcoded para Asaas, ignorando `oficina.gateway` — corrigido
(`gerarCobrancaAvulsa`, `cancelarAssinatura`, `CobrancaController::cancelar`) e
`MercadoPagoService::criarCobrancaAvulsa` foi criado (Checkout Pro / preference).

## Fora de escopo (specs futuras)

- Modelo de alerta "pagamento disponível" / "fatura vencida" / "será suspensa em
  X dias" e o modal de pagamento com PIX/cartão + upsell anual.
- Execução da suspensão automática (o campo `dias_suspensao_vencido` é criado e
  configurável aqui, mas nenhum job suspende oficina ainda).
- Página de bloqueio da oficina suspensa.
- Voto de confiança (admin e self-service).

## A) Modelo de dados

**`oficinas`** (migration nova):
- `ciclo_cobranca` VARCHAR(10) DEFAULT 'MENSAL' — `MENSAL` | `ANUAL`
- `proximo_vencimento` DATE NULL
- `dias_antecedencia_cobranca` INTEGER NULL — override; `null` = usa padrão global
- `dias_suspensao_vencido` INTEGER NULL — override; `null` = usa padrão global.
  Guardado e editável nesta spec; sem efeito prático até a spec de suspensão.

**`saas_config`** (migration nova):
- `cobranca_dias_antecedencia_padrao` INTEGER DEFAULT 5
- `cobranca_dias_suspensao_padrao` INTEGER DEFAULT 10
- `desconto_anual_pct` NUMERIC(5,2) DEFAULT 0

**`cobrancas`**: sem colunas novas. Reusa `tipo` (`ASSINATURA`, já existente) e
`descricao` (preenchida com "Mensalidade Mês/Ano" ou "Assinatura anual
AAAA–AAAA").

## B) Provisionamento (`TenantProvisionService`)

- Continua criando o `customer` no gateway configurado (necessário pra cobrança
  avulsa).
- Para de chamar `criarSubscription`/`preapproval`.
- Define `ciclo_cobranca = 'MENSAL'` e `proximo_vencimento = hoje->addMonth()`.

**Migração das oficinas existentes**: endpoint manual no SaaS admin —
`cancelarAssinatura` (já corrigido) cancela a subscription antiga no gateway;
admin edita a oficina e preenche `proximo_vencimento` manualmente. Não há
migração automática de dados retroativa.

## C) Job diário de geração de cobranças

`Schedule::command('cobrancas:gerar')->dailyAt('06:00')` em
`routes/console.php`, comando novo `App\Console\Commands\GerarCobrancasRecorrentes`
chamando `App\Services\CobrancaRecorrenteService::gerarPendentes()`:

```
para cada oficina em (ATIVA, INADIMPLENTE) com proximo_vencimento não nulo e plano.preco_mensal > 0:
    dias = oficina.dias_antecedencia_cobranca ?? saas_config.cobranca_dias_antecedencia_padrao
    se hoje >= proximo_vencimento.subDays(dias)
       e não existe Cobranca(oficina, tipo=ASSINATURA, vencimento=proximo_vencimento, status != CANCELADA):
        valor = ciclo == ANUAL
            ? plano.preco_mensal * 12 * (1 - saas_config.desconto_anual_pct / 100)
            : plano.preco_mensal
        id = Str::uuid()
        gateway = oficina.gateway ?? saas_config.gateway_preferido
        customerId = gateway == MERCADOPAGO ? oficina.mp_customer_id : oficina.asaas_customer_id
        payment = chama {gateway}Service->criarCobrancaAvulsa(customerId, valor, proximo_vencimento)
                  passando `id` como external_reference/reference
        cria Cobranca(id: id, oficina_id, tipo: ASSINATURA, valor, status: PENDENTE,
                       vencimento: proximo_vencimento, gateway, {gateway}_payment_id: payment.id,
                       descricao: "Mensalidade {mes}/{ano}" ou "Assinatura anual {ano}–{ano+1}")

separadamente, todo dia (mesmo comando):
    Cobranca::where(tipo=ASSINATURA, status=PENDENTE)->where(vencimento < hoje)
        ->update(status: VENCIDA)
    e oficina correspondente vira status=INADIMPLENTE (se ainda ATIVA)
```

Idempotência garantida pela checagem "não existe Cobranca com esse vencimento
ainda não cancelada" antes de criar. O uso de `>=` (em vez de `==`) na condição
de data é proposital: se o job falhar num dia (API do gateway fora do ar,
worker parado) ou pular uma execução, ele se autocorrige no próximo run em vez
de perder a cobrança daquela oficina para sempre. Falha ao chamar o gateway é
logada (`Log::warning`) e a oficina é pulada nessa execução — sem quebrar o
loop das demais.

`{gateway}Service::criarCobrancaAvulsa` (Asaas e MP) passa a aceitar um
`referenceId` opcional (o UUID pré-gerado da `Cobranca`) para permitir
reconciliação exata no webhook — ajuste de assinatura nos dois serviços e no
controller `gerarCobrancaAvulsa` (cobrança avulsa manual do admin também passa
a gerar o id antes e usar o mesmo caminho).

## D) Reconciliação de pagamento (webhooks)

**Bug corrigido nesta spec**: `WebhookController::handlePaymentConfirmed` (Asaas)
só localiza a oficina via `payment.subscription` — cobrança avulsa não tem
`subscription`, então hoje pagamento de cobrança avulsa via Asaas *não é*
reconciliado. Isso vira crítico assim que o motor rodar 100% em avulsas.

- **Asaas**: `handlePaymentConfirmed` passa a localizar `Cobranca::where('asaas_payment_id', $payment['id'])`.
  Ao confirmar: `status=PAGA`, `pago_em=now()`, chama
  `oficina->avancarVencimento()` (soma 1 ciclo ao `proximo_vencimento` atual —
  não à data de hoje, pra não acumular atraso quando paga fora do dia) e
  `status=ATIVA` se estava `INADIMPLENTE`.
- **Mercado Pago**: novo branch para `type=payment` em `WebhookController::mercadopago`
  — busca `GET /v1/payments/{id}`, pega `external_reference` (= id da
  `Cobranca`), localiza por PK e aplica a mesma reconciliação.
- Código de `subscription_preapproval`/`mpHandleAuthorized` e a busca por
  `asaas_subscription_id`/`findOficinaBySubscription` são removidos (ficam
  inalcançáveis pós-migração — nenhuma oficina nova gera subscription).

`Oficina::avancarVencimento()` (novo método no model):
```php
$meses = $this->ciclo_cobranca === 'ANUAL' ? 12 : 1;
$this->update(['proximo_vencimento' => $this->proximo_vencimento->addMonths($meses)]);
```

## E) Ciclo anual + desconto

- `desconto_anual_pct` configurável em SaaS Admin → Configurações.
- Novo endpoint `POST /saas/oficinas/{id}/mudar-ciclo` (`{ciclo: MENSAL|ANUAL}`):
  recalcula `proximo_vencimento = hoje->addMonths(ciclo == ANUAL ? 12 : 1)` (não
  soma em cima do ciclo anterior). A view do modal de upsell (spec futura) só
  precisa chamar esse endpoint.

## F) UI — SaaS Admin

- **Configurações**: 3 campos novos (dias de antecedência, dias de suspensão,
  desconto anual %) na seção de cobrança já existente.
- **Detalhe da oficina**: no card hoje rotulado "Asaas", adiciona:
  - Ciclo de cobrança (Mensal/Anual) + ação de trocar
  - Próximo vencimento (editável — é o campo usado na migração manual)
  - Overrides opcionais de dias (em branco = herda padrão global)
  - "Subscription ID" passa a ser rotulado como legado/histórico (fica vazio em
    oficinas novas).

## G) Histórico de pagamentos

Sem trabalho novo — reaproveita as telas existentes (SaaS Admin → Cobranças e o
painel "Cobranças Locais" na tela da oficina). Cobranças geradas pelo motor
aparecem ali com `tipo=ASSINATURA` e `descricao` preenchida, do mesmo jeito que
as manuais aparecem hoje.

## Arquivos afetados (visão geral, detalhado no plano)

- Migrations: `oficinas` (4 colunas), `saas_config` (3 colunas)
- `app/Models/Oficina.php` — `avancarVencimento()`, novos fillable/casts
- `app/Models/SaasConfig.php` — novos fillable
- `app/Services/CobrancaRecorrenteService.php` (novo)
- `app/Console/Commands/GerarCobrancasRecorrentes.php` (novo)
- `routes/console.php` — novo `Schedule::command`
- `app/Services/AsaasService.php`, `app/Services/MercadoPagoService.php` —
  `criarCobrancaAvulsa` aceita `referenceId` opcional
- `app/Http/Controllers/SaaS/OficinaController.php` — `gerarCobrancaAvulsa`
  usa id pré-gerado; novo `mudarCiclo`
- `app/Http/Controllers/SaaS/WebhookController.php` — reconciliação por
  `payment_id`, novo branch MP `type=payment`, remoção do código de subscription
- `app/Http/Controllers/SaaS/SaasConfigController.php` — novos campos
- `routes/api.php` — rota `oficinas/{id}/mudar-ciclo`
- `frontend/app/saas-admin/(protected)/configuracoes/page.tsx` — 3 campos novos
- `frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx` — ciclo,
  próximo vencimento, overrides
