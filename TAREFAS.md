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

- [ ] **2. Conciliação fiscal de notas de entrada já importadas**
      Arquitetural. Design apresentado e discutido no chat em 2026-09-05 (reconsulta via
      `ConsultaNotaTerceiroProvider`, aplica só campos fiscais via `ProdutoFiscalService::aplicarDoXml()`,
      nunca mexe em estoque, marca `fiscal_conferida_em` em `notas_entrada`, estende a tela
      "Histórico de Entrada de NF"). Falta: spec formal, plano, execução via SDD.

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

- [ ] **4. Dark mode**
      Arquitetural, frontend inteiro. O design system já é 100% CSS variables
      (`--bg`, `--surface`, `--card`, `--border`, `--text`, `--muted`, etc.) — a base pra um
      toggle claro/escuro já existe, falta a paleta clara + o mecanismo de troca. Precisa de
      brainstorming próprio (onde persiste a preferência, troca automática por `prefers-color-scheme`
      ou só manual, etc.).

## Bloqueado — não entra na fila até o usuário resolver

- **Toggle `MANUAL` vs `AUTOMATICO_PROVEDOR`** (Spedy/Focus calculando CFOP/CST/ICMS/ISSO
  automaticamente em vez do sistema calcular tudo). **Não implementar às cegas**: o próprio
  desenho de 2026-08-05 já registrava isso como "aguardando confirmação real via sandbox" —
  ninguém nunca testou `POST /v1/orders` da Spedy sem mandar CFOP/CST/ICMS pra confirmar que
  ela realmente calcula certo. Implementar um toggle pra um comportamento nunca confirmado
  quebraria a regra mais repetida deste projeto (nunca assumir comportamento fiscal sem
  confirmar na doc/sandbox real) — e um erro de cálculo fiscal enviado à SEFAZ é caro e
  difícil de desfazer. Fica fora da fila até você criar a conta sandbox da Spedy
  (`app.spedy.com.br/signup`) e eu poder testar de verdade, ou até você decidir que quer que
  eu implemente mesmo assim, ciente do risco.

## Concluído
(nada ainda nesta lista — itens anteriores já concluídos estão em `PROGRESSO.md`)
