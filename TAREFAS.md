# Backlog de Execução

> Lista viva. Cada item vira spec+plano próprio (ou fica "bounded" se for
> pequeno) antes de codar, seguindo `superpowers:brainstorming`. Ordem
> escolhida por risco/dependência crescente, não pela ordem em que foi pedida.

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
