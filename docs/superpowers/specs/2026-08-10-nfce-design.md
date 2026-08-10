# NFC-e (modelo 65) — design

## Contexto

Pedido do usuário: suporte a NFC-e (Nota Fiscal de Consumidor Eletrônica,
modelo 65) como alternativa à NF-e (modelo 55) quando o destinatário é
consumidor final pessoa física — reaproveitando o fluxo de Cliente/OS/
emissão já existente (Etapa B), **não** uma tela nova de "venda de balcão"
sem cliente.

Ordem decidida pelo usuário em 2026-08-05: **NFC-e primeiro**, Etapa C2
(NF-e via NFePHP `sped-nfe` + contingência EPEC) depois — spec e
implementação separados, este documento cobre só NFC-e.

### Escopo desta etapa

**Dentro:**
- Emissão de NFC-e a partir da mesma tela "Emitir Nota Fiscal"
  (`fiscal/emitir`), quando natureza da operação = "Venda de Mercadoria" e o
  cliente é pessoa física (CPF).
- Seleção automática do modelo (NFC-e vs NF-e) por tipo de documento do
  cliente, com escape hatch manual para forçar NF-e.
- Numeração/série própria (`serie_nfce`/`proximo_numero_nfce`), isolada da
  numeração de NF-e.
- CFOP de venda a consumidor final (`CfopConsumidorResolver` novo).
- Emissão via Spedy e Focus, cobrindo a divergência real entre os dois
  (Focus síncrona, Spedy assíncrona) com um único fluxo de UI.
- PDF do cupom em formato térmico 80mm (DANFCE), com QR code.
- Aviso de prazo de cancelamento na UI.

**Fora (decidido explicitamente):**
- Contingência offline (modo "Comunicador Offline" da Focus / equivalente
  Spedy). Se a emissão falhar por indisponibilidade do provedor/SEFAZ, o
  sistema bloqueia e avisa — sem gerar cupom sem autorização. Mesma decisão
  já tomada para NF-e/EPEC (Etapa C2, ainda não implementada).
- Payload real de produção da Spedy para NFC-e — a doc pública confirma
  `POST /v1/consumer-invoices` e que só `isFinalCustomer` é documentado como
  obrigatório; o resto do payload (endereço do destinatário, itens,
  pagamento) é inferido pelo padrão já usado no `SpedyProvider` para NFS-e/
  empresa e **precisa ser confirmado em homologação antes de considerar a
  Spedy pronta pra produção** — mesma ressalva já registrada para NF-e-Spedy
  na Etapa B (que segue bloqueada por doc/sandbox).
- Reemissão automática de numeração cancelada/inutilizada.
- Qualquer mudança em NF-e ou NFS-e existentes — este spec é aditivo.

## Arquitetura

Estende o padrão já usado na Etapa B para acrescentar NF-e ao lado da NFS-e
(mesmo `NotaFiscalController`/`NfeService`/`FiscalProvider`), em vez de um
módulo separado ou uma refatoração genérica por strategy — decisão tomada
em brainstorming: menor risco, reusa código já validado em produção.

### Fluxo end-to-end

1. Usuário abre "Emitir Nota Fiscal", escolhe natureza "Venda de
   Mercadoria" e seleciona o cliente.
2. Frontend deriva o modelo a partir do `cpf_cnpj` do cliente (11 dígitos =
   CPF → NFC-e; 14 = CNPJ → NF-e). Quando é NFC-e, mostra badge "NFC-e" no
   lugar do rótulo "NF-e" de hoje, com um link discreto "emitir como NF-e
   mesmo assim" (escape hatch — cliente PF que excepcionalmente precisa de
   NF-e). Quando é CNPJ, o campo permanece fixo em "NF-e" (NFC-e não é
   opção — por definição não existe NFC-e pra pessoa jurídica).
3. Ao emitir: backend grava a `NotaFiscal` (`RASCUNHO`, `modelo='NFC-e'`)
   com CFOP/CST-CSOSN calculados por item, igual ao fluxo de NF-e hoje, e
   chama `provider.emitir()` **na mesma request HTTP** (sem fila/Horizon —
   mesma decisão de manter síncrono adotada na Etapa B, ainda mais
   apropriada aqui já que a Focus responde sempre síncrono).
4. Se o resultado já vier `AUTORIZADA` (caso normal com Focus): atualiza
   status, frontend recebe a resposta e abre o PDF do cupom em nova aba
   automaticamente.
5. Se vier `PROCESSANDO` (caso normal com Spedy, que enfileira): frontend
   entra em polling curto (a cada 3s, até 30s) chamando o mesmo endpoint de
   consulta já usado pra NF-e/NFS-e. Se autorizar dentro da janela, mesmo
   comportamento do passo 4. Se estourar 30s, libera a tela com aviso
   "ainda processando, acompanhe pelo Histórico de NF" — não trava o
   usuário esperando indefinidamente.
6. Se rejeitar (SEFAZ recusou) ou falhar (provedor indisponível): erro
   claro na tela, nota fica `REJEITADA`/`RASCUNHO`, usuário tenta de novo.
   Sem contingência offline nesta v1.

## Modelo de dados

- `configuracoes` ganha duas colunas novas: `serie_nfce` (varchar(5),
  default `'001'`) e `proximo_numero_nfce` (integer, default `1`) — mesmo
  padrão de `serie_nf`/`proximo_numero_nf` já existente.
- `NfeService::proximoNumeroNf()` é generalizado para aceitar qual par de
  colunas usar (parâmetro `$modelo` ou dois métodos curtos
  `proximoNumeroNfe()`/`proximoNumeroNfce()`), mantendo a mesma transação
  com `lockForUpdate()` — evita duplicidade de numeração sob concorrência,
  igual ao mecanismo já validado para NF-e.
- `notas_fiscais.modelo` (já existe, string) passa a aceitar também
  `'NFC-e'` além de `'NF-e'`/`'NFS-e'` — sem migration na própria tabela.
- `notas_fiscais_itens` (CFOP/NCM/origem/tributação por item, criada na
  Etapa B) é reusada sem alteração — mesma estrutura que já serve a NF-e.

## Regras fiscais

### Seleção automática NFC-e vs NF-e

No `NotaFiscalController::store()`, quando `natureza_operacao === 'Venda de
Mercadoria'`: se `strlen(preg_replace('/\D/', '', $cliente->cpf_cnpj)) ===
11`, modelo é `NFC-e` (a menos que o frontend envie o escape hatch
`forcar_nfe=true`, aceito também no backend — validação não pode confiar só
no frontend). Se 14 dígitos (CNPJ), modelo é sempre `NF-e`, `forcar_nfe` é
ignorado nesse caso.

### CFOP de venda a consumidor final — `CfopConsumidorResolver` (novo)

Diferente do `CfopSaidaResolver` da Etapa B (venda B2B, que precisa saber
se o destinatário é contribuinte e se o item é ST), consumidor final de
NFC-e por definição nunca é contribuinte de ICMS — a regra fica mais
simples, só compara UF da oficina com UF do cliente:

| Situação | CFOP |
|---|---|
| Dentro do estado | **5102** |
| Fora do estado (interestadual) | **6108** |

Combinação não coberta (nenhuma UF informada) lança exceção — mesma
política de nunca cair num default silencioso já usada no
`CfopSaidaResolver` e no `TributacaoIcmsSaidaResolver`. CST/CSOSN por item
continua vindo do `TributacaoIcmsSaidaResolver` já existente, sem mudança.

## Provedores (Spedy/Focus)

Interface `FiscalProvider` não muda. `emitir()` passa a ramificar por
`$nota->modelo` em 3 branches (`NFSE`/`NFE`/`NFCE`) em vez de 2.

### Focus NFe — confirmado via `doc.focusnfe.com.br/reference/emitir_nfce.md` (2026-08-10)

- **Síncrona**: doc confirma explicitamente "Todos os processos envolvendo
  NFC-e são síncronos".
- Emissão: `POST {baseUrl}/v2/nfce?ref={referencia}` — mesmo padrão de
  prefixo `/v2/` já usado por `/v2/nfe` e `/v2/nfse` (evita repetir o
  defeito #1 da Etapa B, endpoint sem o prefixo correto).
- Consulta: `GET {baseUrl}/v2/nfce/{referencia}`.
- Cancelamento: `DELETE {baseUrl}/v2/nfce/{referencia}`, prazo documentado
  de **30 minutos após a emissão**.
- Payload de itens: `numero_item`, `codigo_ncm`, `codigo_produto`,
  `descricao`, `quantidade_comercial`, `quantidade_tributavel`, `cfop`,
  `valor_unitario_comercial`, `valor_bruto`, `unidade_comercial`,
  `unidade_tributavel`, `icms_origem`, `icms_situacao_tributaria` — bate
  1:1 com o que a Etapa A + `CfopConsumidorResolver` fornecem. Também exige
  `presenca_comprador` (fixo `1` = presencial), `modalidade_frete` (fixo
  `9` = sem frete), `local_destino` (`1`/`2` conforme UF), array
  `pagamento` (`forma_pagamento`, `valor_pagamento` — reusa
  `forma_pagamento` já coletada no formulário).
- Resposta: `status` (`autorizado`/`erro_autorizacao`), `chave_nfe`,
  `numero`, `serie`, `caminho_danfe`, `qrcode_url`.

### Spedy — endpoint confirmado, payload parcialmente inferido

- **Assíncrona** (achado desta sessão, contradiz suposição anterior de que
  NFC-e seria sempre síncrona): doc confirma "a emissão é assíncrona: a
  resposta 2xx confirma que a solicitação foi aceita, não que a nota foi
  autorizada" — mesmo padrão `enqueued`/`processing`/`authorized` já usado
  pela NFS-e da Spedy, tratado pelo `mapStatus()` já existente no
  `SpedyProvider`.
- Emissão: `POST {baseUrl}/v1/consumer-invoices`. Único campo confirmado
  como obrigatório na doc: `isFinalCustomer` (boolean). Resto do payload
  (destinatário, itens, pagamento) segue o padrão camelCase já usado em
  `montarPayloadNfse()`/`montarPayloadEmpresa()` deste provider — **é
  hipótese de trabalho, não confirmada em sandbox**, mesma ressalva já
  registrada para NF-e-Spedy na Etapa B.
- Consulta: endpoint de consulta de nota (`obter-nfc-e`/`consultar-nota-
  fiscal`) segue o mesmo padrão de referência usado hoje para NFS-e.
- Cancelamento: doc confirma "dentro do prazo legal", sem detalhar minutos
  — "prazos e regras variam por UF". Não dá pra confiar num número exato
  vindo da Spedy; a UI usa o mesmo aviso de 30 minutos (regra geral de
  NFC-e, confirmada pela Focus) como orientação, não como bloqueio rígido
  local — quem decide de fato é a SEFAZ na hora do cancelamento.

## PDF do cupom (DANFCE)

- Novo template Blade `pdf.nota_fiscal_nfce`, separado do `pdf.nota_fiscal`
  atual (que fica intocado para NF-e/NFS-e).
- Renderizado via DomPDF com página customizada ~80mm de largura
  (`setPaper([0, 0, 226.77, $alturaDinamica], 'portrait')`), altura calculada
  pela quantidade de itens.
- Conteúdo: cabeçalho da oficina (razão social, CNPJ, endereço resumido),
  itens (descrição/quantidade/valor), total, forma de pagamento, chave de
  acesso formatada (grupos de 4 dígitos), QR code.
- QR code: quando o provedor retorna `qrcode_url` pronta (Focus confirma
  esse campo), embute direto. Quando não vier (caso da Spedy, a confirmar),
  gera localmente a partir da chave de acesso + URL padrão de consulta da
  SEFAZ-MG, usando uma lib PHP de QR code (`endroid/qr-code`, nova
  dependência).
- Reusa o endpoint existente `GET /notas-fiscais/{id}/pdf` — só troca o
  template quando `modelo === 'NFC-e'`, mesma resposta HTTP de hoje.

## Frontend (`NotaFiscalForm.tsx` + histórico)

- Tela de emissão: badge "NFC-e" substitui o rótulo "NF-e" quando o cliente
  selecionado tem CPF e natureza é "Venda de Mercadoria", com o link
  "emitir como NF-e mesmo assim" (envia `forcar_nfe: true`). Itens de venda
  continuam usando o seletor de produto já implementado na Etapa B (herda
  NCM/origem/tributação).
- Botão "Emitir": loading state já existente. Se a resposta já vier
  autorizada, toast de sucesso + `window.open()` do PDF em nova aba. Se
  vier `PROCESSANDO`, o botão muda para "Aguardando confirmação…" e o
  frontend faz polling a cada 3s por até 30s no endpoint de consulta
  (reusa o padrão de polling já usado em `PagamentoTransparenteModal.tsx`
  para status de pagamento). Estourado o tempo, libera a tela com aviso
  "ainda processando, acompanhe pelo Histórico de NF" — não bloqueia o
  atendimento no balcão.
- `fiscal/historico`: filtro por modelo passa a incluir `NFC-e`. Botão
  "Cancelar" existente ganha texto de aviso "cancelável em até 30 minutos
  após a emissão" ao lado da ação, sem bloquear o clique depois desse
  prazo (deixa a SEFAZ decidir, evita a app inventar uma regra rígida que
  pode não bater com a UF).

## Testes

**Locais (lógica pura, sem Postgres):**
- `CfopConsumidorResolverTest` — as 2 combinações da tabela acima + UF
  ausente lança exceção.
- `montarPayloadNfce()` novo em `FocusNfeProviderTest`/`SpedyProviderTest`
  — só montagem de array, sem HTTP real.
- `mapStatus()` dos dois provedores reusado sem mudança (já teve cobertura
  na Etapa B) — nenhuma cobertura nova necessária aqui.
- `NfeService::proximoNumeroNfce()`/`proximoNumeroNfe()` — contadores
  avançam independentemente um do outro (não compartilham sequência).

**Feature tests (Postgres — CI ou banco dedicado; nunca em produção,
`RefreshDatabase` dropa o banco):**
- Cliente com CPF + "Venda de Mercadoria" → modelo `NFC-e` automático;
  cliente com CNPJ → sempre `NF-e`; `forcar_nfe=true` com cliente CPF →
  `NF-e` mesmo assim.
- Numeração de NFC-e usa `serie_nfce`/`proximo_numero_nfce`, nunca
  incrementa/consome o contador de NF-e e vice-versa.
- CFOP calculado corretamente pros dois casos (dentro/fora do estado).
- `Http::fake()` simulando Focus (autorizado direto) e Spedy (`enqueued` →
  consulta subsequente retorna `authorized`) — os dois caminhos gravam o
  mesmo conjunto de campos no fim (`chave_acesso`, `numero`, `status`).
- Suite de NF-e/NFS-e existente roda sem alteração — prova de que a
  mudança foi aditiva.

**Validação manual obrigatória antes de produção** (mesmo espírito da
Etapa A/B): emitir uma NFC-e de teste em homologação contra Focus (single
request, deve autorizar na hora) e, separadamente, contra Spedy (confirmar
o payload real e o comportamento assíncrono na prática) — não dá pra
confiar só no código compilando pra um documento fiscal real.

## Limites do que não foi verificado nesta sessão

1. **Payload real de produção da Spedy para NFC-e** — só `isFinalCustomer`
   está documentado como obrigatório; resto inferido por padrão. Confirmar
   em homologação/sandbox antes de considerar a Spedy pronta.
2. **Se a Spedy retorna QR code pronto ou só a chave de acesso** — decide
   se o `endroid/qr-code` local é realmente necessário ou só um fallback
   raramente usado.
3. Alíquota de ISS de Ilicínea e adesão ao ADN — pendência antiga, não é
   desta etapa (NFC-e não envolve ISS), mas segue não confirmada para as
   etapas de NFS-e.
