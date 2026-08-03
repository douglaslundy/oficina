# Etapa B — refactor compartilhado + NF-e no Spedy/Focus — design

## Contexto

Segunda de três etapas do roteiro fiscal:

- **Etapa A** (concluída, deployada, commit `9e47914`/`3a59f13`) — campos
  fiscais em `produtos` + importação de XML que os preenche. Spec em
  `2026-07-25-campos-fiscais-produtos-design.md`.
- **Etapa B (este spec)** — refactor compartilhado + NF-e via API do
  Spedy/Focus (hoje só emitem NFS-e) + correção de 5 defeitos.
- **Etapa C** — motor NFePHP, spec já escrito e aprovado em
  `2026-07-25-motor-nfephp-design.md`. Assume que `NotaFiscalData` já tem
  `itens[]` e que a interface `FiscalProvider` não muda — este spec entrega
  exatamente essa base.

A ordem foi definida assim porque Spedy e Focus são os motores **oficiais**
e hoje não emitem NF-e, só NFS-e (peça nunca teve como ser faturada por
nenhum dos dois motores pagos).

### Escopo desta etapa (decidido em sessão de brainstorming, 2026-08-02)

**Dentro:**
- `NotaFiscalData` ganha `modelo` e `itens[]` de forma **aditiva** — nenhum
  campo existente muda de nome/tipo, NFS-e continua funcionando sem tocar
  no código dela.
- Spedy e Focus ganham capacidade de emitir NF-e (produto/venda de
  mercadoria), além da NFS-e que já têm.
- Formulário de emissão manual: ao escolher "Venda de Mercadoria", os itens
  passam a ser selecionados do cadastro de produtos (herdando NCM/origem/
  tributação da Etapa A), em vez de texto livre.
- Opção "Misto" fica desabilitada no formulário (visível, não selecionável)
  e rejeitada também no backend.
- Correção dos 5 defeitos catalogados na rodada 16 do `PROGRESSO.md`.

**Fora (decidido explicitamente, não é esquecimento):**
- `EmissaoOrquestrador` / botão "gerar NF a partir da OS" com split
  automático peça+serviço. Motivo: sem isso, "Misto" não tem como ser uma
  nota só (NF-e e NFS-e são documentos legalmente distintos) — construir o
  orquestrador agora dobraria o escopo desta etapa. Fica pra uma etapa B2
  depois que a base do NF-e estiver validada em produção.
- Emissão em fila (Horizon). A emissão continua **síncrona**, como hoje.
  Sem o orquestrador, não há múltiplas notas disparando de uma vez nem
  pressão real por fila nesta etapa — vira necessidade real só na Etapa C
  (NFePHP precisa de fila por causa do EPEC/SEFAZ assíncrona).
- NFC-e e contingência (específicos do NFePHP).

## Arquitetura

### `NotaFiscalData` (aditivo)

```php
final class NotaFiscalData
{
    public function __construct(
        public readonly string $tipo,                  // mantido, não usado por NFE
        public readonly array $tomador,
        public readonly string $descricao,
        public readonly float $valorServicos,           // só relevante quando modelo=NFSE
        public readonly float $aliquotaIss,
        public readonly bool $issRetido,
        public readonly string $codigoServicoFederal,
        public readonly string $codigoServicoMunicipal,
        public readonly string $naturezaOperacao,
        public readonly string $referenciaExterna,
        public readonly string $modelo = 'NFSE',         // NOVO: 'NFE' | 'NFSE'
        public readonly array $itens = [],                // NOVO: só populado quando modelo=NFE
    ) {}
}
```

Cada item de `$itens` é um array (não vale criar uma classe só pra isso):
`produto_id`, `descricao`, `ncm`, `cfop`, `origem`, `tributacao_icms`
(`NORMAL`|`ST`), `cst_csosn`, `quantidade`, `valor_unitario`.

### `NotaFiscal` (model/tabela) e nova `notas_fiscais_itens`

- Nova coluna `notas_fiscais.modelo` (`NFE`|`NFSE`, default `NFSE` — não
  quebra histórico).
- Nova tabela `notas_fiscais_itens` (mesmo padrão de `os_itens` /
  `notas_entrada_itens`): `id`, `nota_fiscal_id`, `produto_id`,
  `descricao`, `ncm`, `cfop`, `origem`, `tributacao_icms`, `cst_csosn`,
  `quantidade`, `valor_unitario`. Só é escrita quando `modelo=NFE`. NFS-e
  continua usando as colunas flat existentes (`subtotal`, `valor_iss`,
  `valor_total`) sem nenhuma mudança.

### CFOP de saída — calculado, não é campo do produto

Novo `App\Services\Fiscal\CfopSaidaResolver`. CFOP é da **operação**, não
da mercadoria (mesma regra que a Etapa A já aplicou pra entrada) — dado UF
da oficina, UF do cliente/destinatário e se o item é ST:

| Situação | CFOP |
|---|---|
| Dentro do estado, tributação normal | **5102** |
| Dentro do estado, com ST (ICMS já recolhido) | **5405** |
| Fora do estado (interestadual), normal | **6102** |
| Fora do estado (interestadual), com ST | **6404** |

Fonte: Convênio s/nº de 15/12/1970 (Tabela CFOP), corroborado por múltiplas
fontes especializadas em emissão fiscal (blog NFE+, ContaAzul, ClickNotas,
Simplifique). São os CFOPs padrão de "venda de mercadoria adquirida ou
recebida de terceiros" — os mesmos que qualquer ERP usa pra este cenário.
**Combinação não coberta pelas 4 linhas acima lança exceção, nunca cai num
`default` silencioso** — mesma lição da Etapa A (CST/CSOSN instáveis) e dos
5 defeitos desta etapa (status desconhecido não pode virar PROCESSANDO
silencioso).

## Provedores (Spedy/Focus)

Interface `FiscalProvider` **não muda** (trava do spec já aprovado da
Etapa C). `emitir()` passa a ramificar internamente por `$nota->modelo`:

```php
public function emitir(NotaFiscalData $nota): EmissaoResultado
{
    return $nota->modelo === 'NFE' ? $this->emitirNfe($nota) : $this->emitirNfse($nota);
}
```

### Focus NFe — confirmado via `doc.focusnfe.com.br/reference/emitir_nfe.md`

- Endpoint: `POST {baseUrl}/nfe?ref={referencia}`.
- Itens exigem `codigo_ncm`, `cfop`, `icms_origem`, `icms_situacao_tributaria`
  (CST/CSOSN), `quantidade_comercial`, `valor_unitario_comercial` — bate
  1:1 com o que a Etapa A + o `CfopSaidaResolver` fornecem.
- Campos obrigatórios adicionais no corpo: `natureza_operacao`,
  `data_emissao`, `tipo_documento` (0=entrada,1=saída — sempre 1 aqui),
  `finalidade_emissao` (1=normal).
- **Assíncrona por padrão**: resposta `202`/`processando_autorizacao` é o
  caso normal, não exceção — já coberto por `EmissaoResultado::processando()`,
  que já existe hoje. Autorização síncrona (`201`) pode acontecer conforme
  UF/config, mas o código não deve assumir isso.
- Status de erro incluem `erro_validacao_schema` com detalhe por campo —
  vale propagar a mensagem de campo específico pro usuário em vez de um erro
  genérico, quando disponível.

### Spedy — endpoint exato pendente de confirmação

A doc técnica (`docs.spedy.com.br`) bloqueou acesso automatizado (403) —
central de ajuda e materiais comerciais confirmam suporte a NF-e, mas o
schema exato do payload não foi verificado nesta sessão. Pelo padrão já
usado pra NFS-e (`POST {baseUrl}/service-invoices`, JSON em camelCase
próprio da Spedy, não nomes de tag SEFAZ), a hipótese de trabalho é um
endpoint irmão tipo `POST {baseUrl}/product-invoices` — **precisa ser
confirmado contra a doc autenticada ou sandbox da Spedy antes de codificar
o payload builder**, não é um "provavelmente certo" aceitável pra emissão
fiscal real.

## Os 5 defeitos (corrigidos junto, não depois)

Corrigidos nos dois provedores, em ambos os fluxos (NFS-e existente e NF-e
novo), já que a mesma classe de bug pode se repetir na cópia:

1. **XML nunca é armazenado (Focus)** — `xml_retorno` guardava um path
   (`caminho_xml_nota_fiscal`), não o conteúdo. Corrigido: baixar o XML via
   GET e persistir o conteúdo real.
2. **Ambiente inferido por substring de URL** — troca `str_contains($baseUrl,
   'api.focusnfe.com.br')` por `$ambiente` explícito injetado no construtor
   (`FiscalProviderManager::build()` já recebe isso, só não repassava).
3. **Status desconhecido vira PROCESSANDO silenciosamente** — `Log::warning()`
   no ramo `default` dos dois `mapStatus()`, sem mudar o comportamento de
   fallback (continua PROCESSANDO, só passa a ser visível em log).
4. **`protocolo` recebe o mesmo valor de `numero`** — pra NF-e, `protocolo`
   passa a ser o protocolo de autorização real da SEFAZ (campo distinto no
   retorno). Pra NFS-e existente, verificar caso a caso se os provedores têm
   um "protocolo" separado de "número"; se genuinamente não existir,
   documentar como limitação do provedor em vez de inventar um valor.
5. **`naturezaOperacao` coletada e descartada** — já existe no DTO, não era
   usada em nenhum payload. Passa a ser plugada nos dois builders (NFS-e e
   NF-e) — a Focus inclusive exige isso como campo obrigatório pra NF-e.

## Frontend (`NotaFiscalForm.tsx`)

- Select de natureza da operação: `Prestação de Serviços` e `Venda de
  Mercadoria` passam a mapear pra `modelo` (`NFSE`/`NFE`) enviado ao
  backend. `Misto` fica com `disabled` (visível, não selecionável).
- Lista de itens dinâmica bifurca por natureza:
  - **Serviço**: continua texto livre, como hoje.
  - **Venda**: cada linha vira um `select` de produto (mesmo padrão do
    seletor de peças em OS — busca por nome/SKU). NCM/origem/tributação
    vêm automáticos e somente-leitura ao escolher o produto. Produto com
    `fiscal_pendente=true` mostra aviso inline ("dados fiscais pendentes,
    ver Produtos › Pendências Fiscais") mas **não bloqueia emissão** — mesma
    filosofia da Etapa A de nunca travar o fluxo principal por dado fiscal
    incompleto, só sinalizar.
- Pré-preenchimento a partir de uma OS selecionada: **fora do escopo**
  (é o `EmissaoOrquestrador` adiado). Formulário continua standalone.
- CFOP não aparece na UI — calculado no backend ao montar o payload,
  invisível pro usuário (mesmo padrão de CST/origem hoje em Produtos).

## Testes

**Locais (lógica pura, sem Postgres — rodam nesta sessão/CI leve):**
- `CfopSaidaResolverTest` — as 4 combinações da tabela acima + combinação
  não coberta lança exceção (não cai num default silencioso).
- `montarPayloadNfe()` novo em `FocusNfeProviderTest`/`SpedyProviderTest` —
  só montagem de array, sem HTTP real.
- `mapStatus()` dos dois provedores — status desconhecido loga warning e
  ainda retorna PROCESSANDO.

**Feature tests (Postgres — CI ou banco dedicado; NUNCA em produção,
`RefreshDatabase` dropa o banco):**
- Emitir NF-e com produto com dados fiscais completos → `notas_fiscais_itens`
  grava NCM/origem/tributação copiados do produto + CFOP calculado
  corretamente pro caso (dentro/fora do estado).
- `Http::fake()` simulando resposta de Focus/Spedy → `xml_retorno` grava
  conteúdo (não path); `protocolo` ≠ `numero` pra NF-e; ambiente correto é
  usado (sem inferência por substring).
- Backend rejeita `modelo`/natureza = Misto com 422 — validação
  server-side, não só o `disabled` do frontend.
- Suite de NFS-e existente (`NotaFiscalTest`, `NfeServiceTest`) roda sem
  alteração — prova de que o refactor foi aditivo de verdade.

**Validação manual obrigatória antes de considerar pronto** (mesmo espírito
da Etapa A com XML real): emitir uma NF-e de teste em **homologação**
contra Focus e, separadamente, contra Spedy — única forma de confirmar o
schema exato da Spedy (bloqueado nesta sessão) e ver a SEFAZ aceitando os
campos de ICMS/CFOP de verdade, não só o código compilando.

## Limites do que não foi verificado nesta sessão

1. **Schema exato do endpoint de NF-e da Spedy** — doc técnica bloqueou
   acesso automatizado. Confirmar contra doc autenticada/sandbox antes de
   codificar `SpedyProvider::montarPayloadNfe()`.
2. **Se Focus/Spedy têm um "protocolo" de NFS-e distinto do "número"** — não
   confirmado; se não existir, é limitação do provedor, não bug nosso.
3. Alíquota de ISS de Ilicínea (pendência antiga, não é desta etapa, mas
   segue não confirmada — a prefeitura informa, usuário não tem contador).
