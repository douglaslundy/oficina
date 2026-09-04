# Entrada de NF via consulta ao provedor (QR/código de barras + listagem por CNPJ) — Design

## Contexto

Hoje o único jeito de dar entrada em estoque a partir de uma nota fiscal de
fornecedor é fazer upload manual do arquivo XML (`EntradaNfController::parse()`
→ `NotaEntradaXmlParser` → tela de revisão → `EntradaNfController::store()`).
Quando a oficina só tem a nota em papel (o fornecedor não mandou o XML por
e-mail, ou o e-mail se perdeu), hoje não existe caminho nenhum — o usuário
teria que digitar tudo manualmente, item por item, sem nenhum dado fiscal.

Os motores de emissão que o sistema já integra pra emitir as próprias notas
(Spedy e Focus NFe) também oferecem, como produto separado, consulta a
**notas recebidas** — documentos emitidos *contra* o CNPJ da oficina, que
essas plataformas sincronizam periodicamente com a SEFAZ:

- **Spedy**: `GET /v1/inbound-product-invoices` (lista, filtra por
  `accessKey`), `POST /v1/inbound-product-invoices/{id}/manifest` (manifesta
  como `acknowledged`/`confirmed`/`unknown`/`notPerformed`), `GET
  /v1/inbound-product-invoices/{id}/xml` (baixa o XML completo — só depois de
  manifestada, indicado pelo campo `isComplete`). Sincroniza com a SEFAZ de
  hora em hora (doc oficial, `docs.spedy.com.br/api-reference/nf-e-recebidas`).
- **Focus NFe**: `GET /v2/nfes_recebidas/{chave}.json?completa=1` devolve o
  documento com itens detalhados (NCM, CFOP, CST/CSOSN, quantidade, valor
  unitário) — é JSON estruturado, não XML bruto (`doc.focusnfe.com.br/
  reference/consultar_nfe_recebida_individual_json`).

Confirmado nesta sessão via WebFetch/WebSearch contra a documentação real —
nenhum comportamento foi assumido sem checar a fonte, seguindo a disciplina já
estabelecida no projeto pra tudo que é fiscal.

**NFePHP fica de fora deste design** (decisão do usuário): a Etapa C1 segue
isolada num worktree não mergeado; consultar a SEFAZ direto via certificado
próprio da oficina é um caminho mais frágil que fica pra quando esse motor
entrar na `main`.

## Objetivo

1. Ler o QR Code ou código de barras impresso no papel da nota (que carrega
   só a chave de acesso de 44 dígitos, nunca os itens), consultar o motor
   fiscal já configurado pra oficina (Spedy ou Focus), manifestar
   automaticamente como "ciência da operação" quando necessário, e cair na
   **mesma** tela de revisão de itens que o upload de XML já usa hoje.
2. Adicionalmente, listar as notas emitidas contra o CNPJ da oficina que o
   provedor já tem sincronizadas e que ainda não foram lançadas no sistema —
   pra pegar nota cujo papel sumiu ou nunca foi escaneado, sem precisar do
   código físico.

As duas vias convergem no mesmo pipeline de sempre: preview → revisão editável
→ `store()`. Nada muda no `store()` existente, no `NotaEntradaXmlParser`, nem
no schema do banco — é tudo aditivo.

## Arquitetura

Nova interface restrita a "consulta de nota de terceiro", separada da
`FiscalProvider` (que é sobre emissão — semânticas de leitura/escrita
diferentes o suficiente pra não valer a pena inflar a interface existente):

```php
namespace App\Services\Fiscal\Contracts;

interface ConsultaNotaTerceiroProvider
{
    /** Consulta uma NF-e emitida contra o CNPJ da oficina pela chave de
     *  acesso (44 dígitos). Manifesta automaticamente como "ciência da
     *  operação" quando a nota existe mas ainda não está completa. */
    public function consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado;

    /** Lista notas recebidas já sincronizadas pelo provedor, mais recentes
     *  primeiro. $desde filtra por data de emissão quando informado. */
    public function listarNotasRecebidas(?\DateTimeInterface $desde = null): array; // list<ConsultaNotaTerceiroResumo>
}
```

Implementada por `SpedyProvider` e `FocusNfeProvider`. `NfePhpProvider`
**não** implementa — quem chama verifica `instanceof` e trata a ausência como
"este motor ainda não suporta essa consulta", nunca como erro genérico.

### DTOs novos (`App\Services\Fiscal\Data`)

```php
final class ConsultaNotaTerceiroResultado
{
    private function __construct(
        public readonly string $status, // COMPLETA | AGUARDANDO_MANIFESTACAO | NAO_ENCONTRADA | ERRO
        public readonly ?array $dados = null,        // mesmo shape de NotaEntradaXmlParser::parse()
        public readonly ?string $mensagemErro = null,
    ) {}

    public static function completa(array $dados): self
    {
        return new self('COMPLETA', $dados);
    }

    public static function aguardandoManifestacao(): self
    {
        return new self('AGUARDANDO_MANIFESTACAO');
    }

    public static function naoEncontrada(): self
    {
        return new self('NAO_ENCONTRADA');
    }

    public static function erro(string $mensagemErro): self
    {
        return new self('ERRO', null, $mensagemErro);
    }
}
```

```php
final class ConsultaNotaTerceiroResumo
{
    public function __construct(
        public readonly string $chaveAcesso,
        public readonly ?string $fornecedorNome,
        public readonly ?string $fornecedorCnpj,
        public readonly ?string $dataEmissao,   // Y-m-d
        public readonly float $valorTotal,
        public readonly bool $completa,         // true = já dá pra baixar o XML sem manifestar de novo
    ) {}
}
```

`ConsultaNotaTerceiroResultado::$dados` usa **exatamente** o array shape que
`NotaEntradaXmlParser::parse()` já retorna (`chave_acesso`, `numero_nf`,
`serie`, `data_emissao`, `fornecedor_nome`, `fornecedor_cnpj`, `valor_total`,
`itens[]` com `codigo_barras`/`descricao`/`quantidade`/`valor_unitario`/
`ncm`/`cfop`/`cest`/`unidade`/`origem`/`cst_csosn`/`tributacao_icms`) — é o
contrato que já existe e que o resto do controller já sabe consumir. Isso
significa que, seja qual for a origem (XML de upload, XML baixado da Spedy,
JSON traduzido da Focus), tudo vira o mesmo array antes de chegar no
`EntradaNfController`.

### `SpedyProvider::consultarNotaRecebida()`

1. `GET {baseUrl}/v1/inbound-product-invoices?accessKey={chave}` com
   `X-Api-Key: emissorToken ?? masterKey` (mesmo padrão de auth já usado em
   todo o resto do arquivo).
2. Lista vazia → `naoEncontrada()`.
3. Achou e `isComplete === true` → `GET
   {baseUrl}/v1/inbound-product-invoices/{id}/xml`, roda o corpo (XML) pelo
   **mesmo** `NotaEntradaXmlParser::parse()` já existente (injeção via
   construtor ou `app(NotaEntradaXmlParser::class)`), devolve
   `completa($dados)`.
4. Achou e `isComplete === false` → `POST
   {baseUrl}/v1/inbound-product-invoices/{id}/manifest` com `{"status":
   "acknowledged"}`, devolve `aguardandoManifestacao()` **sem** tentar baixar
   XML na mesma chamada (a doc não garante que a SEFAZ libera na hora — nunca
   inventar um tempo de espera; quem decide tentar de novo é o usuário,
   clicando de novo em "Consultar").
5. Erro HTTP em qualquer etapa → `erro($resp->json('message') ?? 'Erro ao
   consultar nota na Spedy.')`.

### `FocusNfeProvider::consultarNotaRecebida()`

1. `GET {baseUrl}/v2/nfes_recebidas/{chave}.json?completa=1` com
   `Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')`
   (mesmo padrão do resto do arquivo).
2. 404 → `naoEncontrada()`.
3. 200 → mapeia o JSON pro mesmo shape via um mapper novo e pequeno,
   `FocusNfeRecebidaMapper::paraArray(array $json): array` (arquivo separado,
   não um método privado gigante dentro do provider — mesma razão de sempre:
   testável isoladamente sem precisar de `Http::fake()`), devolve
   `completa($dados)`.
4. Outro erro HTTP → `erro($resp->json('mensagem') ?? 'Erro ao consultar nota
   na Focus.')`.
5. **Manifestação**: a doc consultada não deixou claro se `completa=1`
   já exige manifestação prévia pra essa rota específica (`nfes_recebidas`,
   diferente da rota genérica de "distribuição DFe" descrita em blogs de
   terceiros). Trato como **não exige** por ora (chamada única, sem
   manifestar) — se em produção a Focus devolver itens vazios/parciais
   nesse caso, o sintoma vai aparecer como `dados.itens` vazio, e nesse
   ponto adiciono a chamada de manifestação
   (`POST /v2/nfes_recebidas/{chave}/manifestacao`, mesmo endpoint já usado
   por `manifestar_nfe_recebida` na doc) antes de repetir a consulta. Registro
   isso como item de validação em produção, não bloqueia o desenvolvimento.

### `SpedyProvider::listarNotasRecebidas()` / `FocusNfeProvider::listarNotasRecebidas()`

Spedy: `GET /v1/inbound-product-invoices` (+ `initialDate` se `$desde`
informado), mapeia cada item da lista pra `ConsultaNotaTerceiroResumo`
(`completa` = campo `isComplete` da resposta).

Focus: não tem um "listar" nativo tão explícito nos resultados de busca desta
sessão — usa o mesmo endpoint de consulta individual não se aplica a lista;
**verificar no início da implementação** se existe uma rota de listagem
equivalente na doc completa da Focus (`doc.focusnfe.com.br`) antes de
escrever o método. Se não existir uma rota de listagem de verdade, o botão
"Notas Recebidas" no frontend funciona só pra oficinas com provedor Spedy
nesta primeira versão, e a Focus continua atendendo plenamente a via A
(consulta por chave individual) — não é motivo pra bloquear o resto do
design, mas precisa ficar registrado no `PROGRESSO.md` como limitação
conhecida se confirmado.

### `EntradaNfController` — refactor + 2 endpoints novos

O bloco de matching/preview hoje inline dentro de `parse()` (linhas ~41–121
do arquivo atual, o `array_map`/`array_filter` que decide `matched`,
`fiscal_pendente`, `sera_atualizado` por item) vira um método privado:

```php
private function montarPreview(array $dados): array
{
    // Mesmo corpo que hoje monta a resposta inteira de parse() a partir de
    // $dados (saída de NotaEntradaXmlParser::parse()): calcula $jaLancada,
    // monta o array de $itens com matched/fiscal_pendente/sera_atualizado, e
    // devolve o payload completo (chave_acesso, numero_nf, fornecedor_*,
    // itens[], ja_lancada, atualizacao_fiscal_disponivel, xml_original).
    // Sem mudança de lógica em relação ao que já existe hoje dentro de
    // parse() — só extraído pra método reaproveitável.
}
```

Reaproveitado pelos três pontos de entrada (`parse()`, e o endpoint
`consultar()` abaixo), eliminando a duplicação que existiria se cada consulta
remontasse esse array na mão.

```php
public function recebidas(FiscalProviderManager $providerManager): JsonResponse
{
    $provider = $providerManager->forTenant();
    if (!$provider instanceof ConsultaNotaTerceiroProvider) {
        return response()->json(['message' => 'O motor fiscal desta oficina ainda não suporta consultar notas recebidas.'], 422);
    }

    $chavesJaLancadas = NotaEntrada::whereNotNull('chave_acesso')->pluck('chave_acesso')->all();

    $resumos = array_map(fn (ConsultaNotaTerceiroResumo $r) => [
        'chave_acesso'    => $r->chaveAcesso,
        'fornecedor_nome' => $r->fornecedorNome,
        'fornecedor_cnpj' => $r->fornecedorCnpj,
        'data_emissao'    => $r->dataEmissao,
        'valor_total'     => $r->valorTotal,
        'completa'        => $r->completa,
        'ja_lancada'      => in_array($r->chaveAcesso, $chavesJaLancadas, true),
    ], $provider->listarNotasRecebidas());

    return response()->json(['notas' => $resumos]);
}

public function consultar(Request $request, FiscalProviderManager $providerManager): JsonResponse
{
    $validated = $request->validate(['chave_acesso' => ['required', 'string', 'size:44']]);

    if (NotaEntrada::where('chave_acesso', $validated['chave_acesso'])->exists()) {
        return response()->json(['message' => 'Esta nota fiscal já foi lançada anteriormente.'], 422);
    }

    $provider = $providerManager->forTenant();
    if (!$provider instanceof ConsultaNotaTerceiroProvider) {
        return response()->json(['message' => 'O motor fiscal desta oficina ainda não suporta essa consulta.'], 422);
    }

    $resultado = $provider->consultarNotaRecebida($validated['chave_acesso']);

    return match ($resultado->status) {
        // Mesmo shape que parse() já devolve hoje (chave_acesso, numero_nf,
        // fornecedor_*, itens[] com matched/fiscal_pendente/etc.) — o
        // frontend usa a resposta de forma idêntica nos dois endpoints.
        'COMPLETA' => response()->json($this->montarPreview($resultado->dados)),
        'AGUARDANDO_MANIFESTACAO' => response()->json([
            'message' => 'Ciência da operação enviada à SEFAZ. Isso pode levar alguns minutos — tente consultar novamente em instantes.',
        ], 202),
        'NAO_ENCONTRADA' => response()->json([
            'message' => 'Nota não encontrada no provedor ainda. A sincronização com a SEFAZ pode levar até 1 hora — tente novamente mais tarde.',
        ], 404),
        default => response()->json(['message' => $resultado->mensagemErro ?? 'Erro ao consultar a nota.'], 422),
    };
}
```

`store()` não muda em nenhuma linha — o preview gerado por `consultar()` cai
na validação e no fluxo transacional de sempre.

### Rotas (`routes/api.php`)

```php
// Grupo de leitura (qualquer role autenticada), junto de GET entradas-nf:
Route::get('entradas-nf/recebidas', [EntradaNfController::class, 'recebidas']);

// Grupo de escrita (ADMIN, ATENDENTE), junto de parse/store/atualizar-fiscal:
Route::post('entradas-nf/consultar', [EntradaNfController::class, 'consultar']);
```

### Frontend — `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`

A página ganha um seletor de modo com 3 opções (a de upload já existe):

1. **Upload de XML** — inalterado.
2. **Ler QR Code / código de barras** — abre a câmera (`getUserMedia`) usando
   `@zxing/browser` (decodifica QR e Code128 com a mesma API) num `<video>`;
   ao decodificar, extrai a chave de 44 dígitos do conteúdo (regex `/\d{44}/`
   — cobre tanto um valor cru de 44 dígitos quanto uma URL de QR da SEFAZ que
   embute a chave). Sempre visível ao lado: um campo de texto pra digitar a
   chave manualmente + botão "Consultar", pro caso de câmera indisponível ou
   papel ilegível. Os dois caminhos chamam a mesma função `consultarChave(chave: string)`.
3. **Notas Recebidas** — nova aba com uma tabela vinda de `GET
   /entradas-nf/recebidas` (fornecedor, data, valor, status: "pronta pra
   importar" / "aguardando manifestação" / "já lançada" — esta última com o
   botão desabilitado). Botão "Importar" por linha chama a mesma
   `consultarChave(chave)`.

`consultarChave()` chama `POST /entradas-nf/consultar`:

- `200` → popula `setPreview`/`setItens` com a resposta, exatamente como o
  `handleUpload` do fluxo de XML já faz hoje — cai na mesma tabela de revisão
  e no mesmo botão de confirmar (`POST /entradas-nf`), sem nenhum componente
  novo de revisão.
- `202` → toast informativo com a mensagem do backend, sem preview.
- `404`/`422` → toast de erro com a mensagem do backend.

Sem teste automatizado de UI pra câmera (não dá pra simular hardware em
CI/local) nem introdução de test runner JS novo — o projeto não tem nenhum
configurado hoje em lugar nenhum do frontend, e criar um só pra isso é fora
de proporção. A função de extração da chave (regex) é pura e simples o
bastante pra confiar em revisão manual + QA na tela.

## Fora de escopo (explícito)

- NFePHP — fica pra quando a Etapa C1 mergear.
- Qualquer manifestação além de `acknowledged`/"ciência da operação" (não
  confirma nem rejeita a operação — só declara ciência, que é o mínimo
  necessário pra liberar o XML completo).
- Download de DANFE em PDF da nota de terceiro (os dois provedores expõem
  isso, mas não faz parte do fluxo de dar entrada em estoque).
- Alterar `NotaEntradaXmlParser`, `store()`, `ProdutoFiscalService`, ou
  qualquer migration — tudo aditivo.
- Sincronização automática/agendada de "notas recebidas" (a listagem é
  sempre sob demanda, quando o usuário abre a aba) — nada de scheduler novo
  nesta rodada.

## Testes

- `SpedyProviderTest` (`Http::fake()`, mesmo padrão dos testes de emissão já
  existentes): `consultarNotaRecebida()` completa retorna os itens
  corretos a partir do XML fake; incompleta dispara `POST .../manifest` com
  `status: acknowledged` e retorna `AGUARDANDO_MANIFESTACAO`; lista vazia
  retorna `NAO_ENCONTRADA`; erro HTTP retorna `ERRO` com a mensagem do
  provedor. `listarNotasRecebidas()` mapeia a lista corretamente.
- `FocusNfeProviderTest`: mesmos 4 casos pra `consultarNotaRecebida()`
  (adaptados ao JSON da Focus em vez do XML da Spedy).
- `FocusNfeRecebidaMapperTest` (unit, sem HTTP): JSON completo de exemplo →
  array no shape esperado, cobrindo pelo menos um item com CST e um com
  CSOSN (mesma distinção que `ClassificacaoIcms::derivar()` já trata em
  `NotaEntradaXmlParser`).
- `EntradaNfConsultaTest` (feature, contra Postgres — mesma limitação de
  ambiente já documentada no projeto, roda em CI): `POST
  entradas-nf/consultar` com provider fake retornando completa → 200 com o
  preview; retornando aguardando manifestação → 202; não encontrada → 404;
  motor da oficina é NFEPHP → 422 sem chamar nenhum provider; chave já
  lançada → 422 sem sequer consultar o provedor (short-circuit antes da
  chamada HTTP). `GET entradas-nf/recebidas` retorna a lista com `ja_lancada`
  calculado certo por chave.
