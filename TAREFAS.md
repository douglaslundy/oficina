# Backlog de Execução

> Lista viva. Cada item vira spec+plano próprio (ou fica "bounded" se for
> pequeno) antes de codar, seguindo `superpowers:brainstorming`. Ordem
> escolhida por risco/dependência crescente, não pela ordem em que foi pedida.

## Fila (nesta ordem)

- [ ] **1. Correção pontual — `MotorNfse::consultar()` trata falha de rede como "não cancelado"**
      Bounded. Sem caller hoje (`consultar()` não é chamado por nada ainda) — risco parked
      registrado desde a Rodada 20/22, resolver antes que um job de sincronização use isso.

- [ ] **2. Conciliação fiscal de notas de entrada já importadas**
      Arquitetural. Design apresentado e discutido no chat em 2026-09-05 (reconsulta via
      `ConsultaNotaTerceiroProvider`, aplica só campos fiscais via `ProdutoFiscalService::aplicarDoXml()`,
      nunca mexe em estoque, marca `fiscal_conferida_em` em `notas_entrada`, estende a tela
      "Histórico de Entrada de NF"). Falta: spec formal, plano, execução via SDD.

- [ ] **3. Estender `ConsultaNotaTerceiroProvider` pro motor NFePHP**
      Arquitetural. Hoje só `SpedyProvider`/`FocusNfeProvider` implementam a interface (decisão
      de escopo da Rodada 32, não bloqueio técnico). Pra NFePHP, a consulta seria direto na SEFAZ
      via Distribuição DFe usando o certificado A1 da própria oficina (`sped-nfe`, que o motor
      NFe/NFSe já usa) — caminho mais frágil que a API REST da Spedy/Focus. Precisa de pesquisa
      real na doc do `sped-nfe`/`nfephp-org` antes de desenhar (mesma disciplina de nunca
      assumir comportamento fiscal).

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
