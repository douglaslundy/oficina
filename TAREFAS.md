# Backlog de Execução

> Lista viva. Cada item vira spec+plano próprio (ou fica "bounded" se for
> pequeno) antes de codar, seguindo `superpowers:brainstorming`. Ordem
> escolhida por risco/dependência crescente, não pela ordem em que foi pedida.

## Sobra da Rodada 39 continuação 2 (2026-09-11) — NFC-e via Spedy não testada até autorizar

A NF-e (peça, B2B/consumidor final) já AUTORIZA de verdade via Spedy
(5 bugs corrigidos, ver `PROGRESSO.md`). A NFC-e (venda de balcão) só
teve 1 tentativa, ANTES do fix de endereço, e nunca chegou a criar
registro na Spedy (`consumer-invoices` retornava 0 itens — provavelmente
o mesmo tipo de campo obrigatório ausente, não diagnosticado). Os 5 fixes
desta rodada foram aplicados em `montarPayloadNfe()`; `montarPayloadNfce()`
só recebeu address/CEST/tributáveis/numeração — **falta o grupo PIS/
COFINS**, que no schema da NFC-e é flat (`icmsOrigin`/`icmsTaxSituation`
direto no item, não aninhado em `taxes.icms`), então a estrutura exata
de PIS/COFINS pra esse endpoint não foi confirmada. Precisa de uma
rodada de teste dedicada (emitir NFC-e de teste, ler o erro real, repetir
o mesmo método usado pra NF-e: WebFetch na doc + reemissão via tinker).

## ✅ CONCLUÍDA 2026-09-14 — reconciliação de status Spedy (era a PRÓXIMA TAREFA OBRIGATÓRIA)

Ver `PROGRESSO.md` Rodada 40 pro relato completo (implementação + investigação do
pedido do usuário sobre "notas aprovadas mas constam rejeitadas" e "aprovada pra
um cliente, não pra outro"). Resumo: as duas perguntas eram o MESMO bug — não
havia diferença real ligada ao cliente. Corrigido `SpedyProvider` (integrationId
no payload + `consultar()` por filtro + falha de consulta nunca mais vira
REJEITADA). 2 commits, 49 testes Unit (era 48), suíte completa sem regressão
(mesmas 10 falhas pré-existentes de sempre).

**Pendente (não é código, é dado):** reconciliar manualmente as 10 NF-e reais
presas como REJEITADA na stuntmotos com a Spedy, agora que o fix está no ar —
aguardando confirmação do usuário antes de mutar registros fiscais de produção
(ver PROGRESSO.md Rodada 40).

<details>
<summary>Texto original da tarefa (referência)</summary>

**Corrigir a reconciliação de status de emissão via Spedy — notas ficam
PROCESSANDO pra sempre mesmo quando a Spedy já autorizou.**

Confirmado em homologação (stuntmotos, 2026-09-10): NFS-e emitida ficou
`PROCESSANDO` no nosso banco por dias, mas consultando a API da Spedy
diretamente (`GET /service-invoices/{id}`) o status real já era
`authorized` desde o primeiro segundo.

**Causa raiz confirmada:** ao emitir, a Spedy devolve o `id` dela própria
(ex. `a5522b3f-...`) no corpo da resposta — `SpedyProvider::resultadoDe()`/
`resultadoNfceDe()` **nunca leem esse `id`**. O sistema só guarda a nossa
`referencia_externa` interna (`nf-<uuid>`). Depois, `consultar()` faz
`GET /{recurso}/{referencia_externa}` — 404 sempre, porque a Spedy não
conhece essa referência. Resultado: nenhuma nota emitida via Spedy jamais
sai de PROCESSANDO por reconciliação automática (nem pelo polling do
frontend, nem pelo comando agendado `nfe:reconciliar-processando`).

**Correção proposta (testada empiricamente, não é suposição):**
1. Mandar a nossa `referencia_externa` como `integrationId` no payload de
   criação nos 4 caminhos de emissão da Spedy (`montarPayloadNfse`,
   `montarPayloadNfce`, `montarPayloadNfe`, `montarPayloadOrder`).
2. Trocar `consultar()` pra filtrar por ele:
   `GET /{recurso}?integrationId={referencia}` em vez de
   `GET /{recurso}/{referencia}`, pegando `items[0]`.
   **Confirmado no sandbox real (2026-09-10):** `?integrationId=X` filtra
   de verdade (retornou `totalCount=0` pra um id inexistente, contra uma
   lista não-vazia sem o filtro) — não é suposição, é comportamento
   observado da API.
3. Cobrir com teste (`SpedyProviderTest`) mockando `Http::fake()` pro novo
   formato de consulta.
4. Reconciliar manualmente (ou via comando) as notas já presas em
   PROCESSANDO na stuntmotos depois do deploy da correção.

**Por que não foi feita agora:** usuário pediu explicitamente pra adiar —
limite semanal de uso perto do fim. Ver [[project-roadmap-fiscal-3-etapas]]
na memória (seção Spedy) e `PROGRESSO.md` Rodada 39 pra todo o contexto de
investigação (incluindo o bug irmão, já corrigido: NF-e/NFC-e não mandavam
endereço do destinatário, commit `c1a5728`).

</details>

## Achados da análise Focus/NFePHP (2026-09-10, registrados — não corrigidos ainda)

Usuário pediu análise dos módulos Focus e NFePHP (config + dados enviados),
mas quer adiar qualquer correção pro próximo momento (limite semanal
perto do fim). Achados, por ordem de risco:

1. **BUG real — `NfePhpProvider::emitir()` roteia NFC-e pro motor de NFS-e
   silenciosamente.** `NfePhpProvider::emitir()` só distingue `NFE` (→
   `MotorNfe`) de "qualquer outra coisa" (→ `MotorNfse`) —
   `MotorNfse::emitir()` nunca checa `$nota->modelo`. NFePHP **não tem
   suporte a NFC-e implementado** (confirmado: zero menções a `NFCE`/`CSC`/
   `idToken` em `MotorNfe.php`), mas nada bloqueia isso — nem
   `IniciarEmissaoNotaService::iniciar()`, nem o motor. Se uma oficina
   configurada pra `provedor_fiscal = NFEPHP` tentar emitir uma NFC-e
   (venda de peça a consumidor, fluxo comum de oficina), o sistema geraria
   uma NFS-e (nota de serviço) em vez de recusar com erro claro — documento
   fiscal errado, não uma falha visível. **Hoje dormente**: nenhuma oficina
   está configurada pra NFEPHP (`provedor_fiscal_padrao = SPEDY`,
   `oficinas.provedor_fiscal` vazio nas duas oficinas existentes), mas
   precisa de guarda explícita (rejeitar com mensagem clara) antes de
   qualquer oficina real ligar o NFEPHP.

2. **Gap de dados cross-provider — `codigo_ibge` do destinatário é sempre o
   da PRÓPRIA oficina, nunca o do cliente.** `NfeService::montarNotaData()`
   recebe `codigoIbgeTomador` como parâmetro, mas todo caller
   (`NfeService::emitir()`) passa `$config->codigo_ibge` (a oficina) — a
   tabela `clientes` **não tem coluna `codigo_ibge`** (só `cidade`/`uf`
   texto). Afeta os 3 provedores igualmente (Spedy `enderecoDestinatario()`,
   Focus `montarPayloadNfse()`/`montarPayloadNfe()`, NFePHP `tagenderDest`/
   `MotorNfse`), porque nasce numa camada compartilhada. Não deu problema
   ainda porque o único teste real (ABRAÃO VINICIUS, Ilicínea/MG) mora na
   mesma cidade da STUNT MOTOS — mas qualquer cliente de outro município
   sai com `cMun`/`codigo_municipio` errado no documento fiscal (divergente
   de `UF`/`xMun`, que usam o dado real do cliente). Correção: `clientes`
   precisa de uma coluna `codigo_ibge` própria, preenchida pelo ViaCEP no
   cadastro (o ViaCEP já devolve o campo `ibge` na resposta — só não é
   capturado hoje).

3. **Focus não tem NENHUMA credencial cadastrada** (`saas_config`:
   `focus_master_token_producao`/`_homologacao` ambos vazios). Não é bug —
   o código de emissão (`FocusNfeProvider`) está bem documentado e com
   endereço do destinatário correto em NFS-e/NF-e (ao contrário do bug
   que existia na Spedy, já corrigido). Mas está 100% não testado contra
   sandbox real por falta de token — se alguém trocar o provedor pra FOCUS
   hoje, toda emissão falha com 401 até cadastrar o token em SaaS Admin.

4. **NFePHP nunca foi "ativado" pra nenhuma oficina** (`emissores_fiscais`
   só tem 1 registro, SPEDY/ERRO). A config da stuntmotos hoje JÁ atende
   aos requisitos de `NfePhpProvider::registrarEmissor()` (CNPJ, IE, IM,
   CNAE, código IBGE, regime tributário e certificado A1 — todos
   presentes), então dá pra testar de verdade quando alguém decidir usar
   NFePHP em vez de Spedy. Nunca emitida uma NF-e/NFS-e real via NFePHP
   contra a SEFAZ/ADN em produção nem homologação.

Ver `PROGRESSO.md` Rodada 39 pro detalhe completo da investigação
(inclusive as consultas diretas à API da Spedy que confirmaram o item 1 da
seção acima "PRÓXIMA TAREFA OBRIGATÓRIA").

## Achados da Rodada 40 (2026-09-14) — registrados, não corrigidos ainda

1. **`FocusNfeProvider::consultar()` tem a MESMA falha "consulta falhou ⇒
   REJEITADA"** já corrigida no `SpedyProvider` nesta rodada (ver
   `PROGRESSO.md` Rodada 40). Não corrigido agora porque a Focus não tem
   nenhuma credencial cadastrada (achado antigo, item 3 acima) — ninguém é
   afetado hoje. Corrigir junto quando alguém configurar Focus de verdade.
2. **`SpedyProvider::cancelar()` ainda usa `DELETE /{recurso}/{referencia}`
   por path** (não por filtro `integrationId`) — mesma classe de problema do
   `consultar()` antigo: `referencia_externa` salvo é sempre a nossa
   referência interna, nunca o `id` real da Spedy, então cancelar uma nota
   já AUTORIZADA provavelmente também dá 404. **Não corrigido nesta rodada**
   porque exigiria confirmar empiricamente (sandbox real) se a Spedy aceita
   filtrar por `integrationId` também no DELETE ou se exige o `id` real via
   um GET prévio — nenhuma nota chegou a ser cancelada de verdade ainda
   neste projeto, então não há evidência de produção como havia pra
   `consultar()`. Precisa de uma rodada dedicada com teste ao vivo.

## Fila (nesta ordem)

- [x] **1. Correção pontual — `MotorNfse::consultar()` trata falha de rede como "não cancelado"**
      ✅ Concluída 2026-09-05. `resultadoAposVerificarCancelamento()` extraído (mesmo padrão de
      `mapearResultadoConsulta()`): falha ao listar eventos 101101 agora vira `ERRO` explícito,
      nunca mais "tratado como sem cancelamento" silenciosamente. 3 testes novos, 6/6 passando,
      zero regressão na suíte Unit (247 testes, só as 3 falhas pré-existentes de OpenSSL local).

- [x] **2. Conciliação fiscal de notas de entrada já importadas**
      ✅ Concluída 2026-09-05, ver `PROGRESSO.md`. Plano de 5 tasks
      (`docs/superpowers/plans/2026-09-05-conciliacao-fiscal-notas-entrada.md`) executado direto
      na `main`, TDD. `ConciliarFiscalNotaEntradaJob` reconsulta cada `NotaEntrada` via
      `ConsultaNotaTerceiroProvider::consultarNotaRecebida()` e aplica só campos fiscais via
      `ProdutoFiscalService::aplicarDoXml()` (nunca `EstoqueService`, verificado por teste e por
      autorrevisão do diff). Endpoints `POST entradas-nf/{id}/conciliar` e
      `POST entradas-nf/conciliar-pendentes`; tela "Histórico de Entrada de NF" ganhou coluna de
      status fiscal + botões de conciliação. Ver relatório completo em
      `docs/superpowers/plans/2026-09-05-conciliacao-fiscal-notas-entrada-report.md` pra detalhes
      de quais testes rodaram localmente (Unit) vs. quais precisam de CI/túnel Postgres
      (Feature, `RefreshDatabase`).

- [x] **3. Estender `ConsultaNotaTerceiroProvider` pro motor NFePHP**
      ✅ Concluída 2026-09-05 (Rodada 33, ver `PROGRESSO.md`). `NfePhpProvider` agora implementa
      a interface consultando a SEFAZ direto via Distribuição DFe (`Tools::sefazDistDFe()`), com
      o certificado A1 da própria oficina. A pesquisa exigida foi feita contra o código real do
      vendor e os XSDs oficiais do pacote instalado (`Tools.php:384/677`,
      `schemes/PL_010_V1.30/retDistDFeInt_v1.01.xsd`, `resNFe_v1.01.xsd`) — nada assumido de doc
      externa. 3 commits, 15 testes `Unit` novos, zero regressão.
      **Sobra conhecida (não bloqueia):** `listarNotasRecebidas()` é best-effort — varre do NSU 0
      e fica no primeiro lote (máx. 50 docs pelo XSD), sem checkpoint de `ultNSU`/`maxNSU`.
      Revisitar antes de a primeira oficina real usar o motor NFePHP com volume.
      **Não confirmado contra a SEFAZ real:** se o próprio destinatário recebe o `procNFe`
      completo direto ou precisa manifestar antes — os dois caminhos são tratados, o desconhecido
      degrada pra `AGUARDANDO_MANIFESTACAO`.

- [x] **4. Dark mode**
      ✅ **JÁ ESTAVA PRONTO** — descoberto ao investigar, não construído agora. Commit `047b992
      feat: modo claro/escuro com next-themes` já implementou tudo: `ThemeProvider` (`next-themes`)
      em `app/layout.tsx` (`attribute="data-theme"`, default escuro, sem seguir SO), toggle ☀️/🌙
      funcional em `components/layout/Topbar.tsx`, paleta clara completa em `app/globals.css`
      (`[data-theme='light']`). Isso era outro item de backlog desatualizado — corrigido aqui e
      na memória persistente, mesma causa do engano com as Etapas C1/C2.

## Bloqueado — nada mais na fila

(vazio — o toggle `MANUAL`/`AUTOMATICO_PROVEDOR` saiu daqui em 2026-09-05: o
usuário pediu pra atacar, o spike real do `POST /v1/orders` foi feito no
sandbox Spedy e confirmou que a Spedy calcula a tributação sozinha, então
foi implementado. Ver `PROGRESSO.md` Rodada 35.)

## Verificação final (controlador, 2026-09-05)

Depois dos 2 agentes paralelos (itens 2 e 3) terminarem, rodei eu mesmo os
testes de Feature/DB-dependentes contra um Postgres real (túnel SSH) —
achei e corrigi 1 bug real que nenhum dos dois agentes pôde ver (os testes
que o exercitavam exigem `RefreshDatabase`, indisponível na máquina de
ambos): 2 dos 4 testes do `ConciliarFiscalNotaEntradaJobTest` mockavam só
`ConsultaNotaTerceiroProvider`, mas `FiscalProviderManager::forTenant()`
tem retorno tipado estrito como `FiscalProvider` — `TypeError` em runtime.
Corrigido mockando as duas interfaces juntas (commit `a91021e`). CI
confirmado verde nesse commit (Backend + Frontend, `success`).

**Todos os 4 itens que o usuário pediu pra fila + a conciliação fiscal
estão concluídos, com CI verde.** Nenhuma tarefa restante na fila além do
item explicitamente bloqueado abaixo.

## Concluído
- Correção do `MotorNfse::consultar()` (commit `7bd7eb9`)
- Conciliação fiscal de notas de entrada (commits `19cdd7d`..`8553e91`, fix `a91021e`)
- Extensão do NFePHP pra `ConsultaNotaTerceiroProvider` (commits `c10f61c`..`389c489`)
- Dark mode (já existia — `047b992`, achado nesta rodada)
- Toggle `calculo_tributario_modo` MANUAL/AUTOMATICO_PROVEDOR (Rodada 35, commits `a6a9937`..`ee46d7c`)

- Emissão fiscal em fila (Rodada 36, commits `91fd465` + `b0268f4`)
- Paginação parcial do NFePHP DistDFe (Rodada 36, commit `8b15a34`)
- `EmissaoOrquestrador` — OS mista → NF-e + NFS-e com 1 clique (Rodada 36, commit `6145620`)

## Verificação a fundo do backlog GERAL (não-fiscal) — 2026-09-05

"Verifique a fundo e faça todos itens abertos": auditei item por item a
seção "❌ FALTA IMPLEMENTAR" de `project-mecanicapro.md` contra o código.
**Todo P0/P1/P2 já está implementado** (editar itens de OS, recálculo de
status ao listar clientes, filtros+busca na lista de OS, exportação Excel,
módulo de relatórios, recibo PDF, dark mode, filtro de período em Contas a
Receber). Detalhe da verificação em `PROGRESSO.md` Rodada 37.

Responsividade mobile/tablet: RESOLVIDO 2026-09-05 (Rodada 38, commit
`5d33afa`). Classes de grid responsivo em `globals.css`, ~17 telas do
dashboard, calendário de agendamentos (semana empilha, mês rola), modais e
telas `(auth)`. Ver `PROGRESSO.md` Rodada 38. **Backlog geral 100% fechado.**

## Backlog vazio — tudo o que estava listado foi feito.

Sobras conhecidas (documentadas, não bloqueiam ninguém, ninguém pediu):
- Paginação COMPLETA do NFePHP DistDFe (checkpoint de NSU + sync agendado) — só se alguma oficina usar o NFePHP com volume alto.
- Validação real do toggle AUTOMATICO_PROVEDOR com catálogo variado — depende do certificado A1.
- Bloco IBS/CBS na NF-e (obrigatório só em 2027), CSC pra QR Code da NFC-e (precisa credencial SEFAZ-MG).
