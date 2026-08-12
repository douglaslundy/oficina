# Atualização fiscal via reenvio de nota de entrada já lançada — Design

## Contexto

O sistema já importa XML de NF-e de fornecedor pra dar entrada em estoque
(`EntradaNfController`/`NotaEntradaXmlParser`/`ProdutoFiscalService`),
extraindo e aplicando campos fiscais (NCM, CEST, origem, tributação de
ICMS) nos produtos batidos por código de barras. Hoje, se o usuário reenvia
o XML de uma nota já lançada (`NotaEntrada.chave_acesso` já existe), o
sistema recusa por completo: `store()` retorna 422 "Esta nota fiscal já foi
lançada anteriormente" e a tela desabilita o botão de confirmar.

Isso é correto pra evitar duplicar estoque — mas produtos importados antes
de existirem os campos fiscais (ou de qualquer forma sem NCM preenchido)
ficam sem um jeito natural de reaproveitar dados que já existem no XML de
uma nota que o usuário já tem em mãos. Hoje só dá pra corrigir isso um
produto de cada vez, manualmente, na tela de pendências fiscais.

## Objetivo

Permitir que o reenvio do XML de uma nota já lançada sirva **só** pra
atualizar campos fiscais pendentes nos produtos que baterem por código de
barras — nunca estoque, nunca custo, nunca produto novo, nunca uma nova
`NotaEntrada`. Se não houver nada fiscal pra atualizar, o sistema continua
recusando exatamente como hoje.

## Arquitetura

Reaproveita inteiramente a lógica de decisão já existente em
`ProdutoFiscalService`/`PoliticaConflitoFiscal` (preencher campo vazio,
abrir divergência se o XML diverge do valor já cadastrado, não fazer nada
se igual). As únicas peças novas são: (1) um método de "checagem seca" que
diz se aplicar o XML mudaria algo num produto, sem escrever nada; (2) um
endpoint HTTP separado do `store()` normal, com contrato restrito
(fiscal-only); (3) o branching de UI que troca de fluxo conforme a resposta
do `parse()`.

Endpoint novo em vez de um "modo" no `store()` existente: os dois fluxos
têm efeitos colaterais fundamentalmente diferentes (um mexe em estoque e
cria registros, o outro só atualiza campos de produtos existentes) — manter
contratos separados evita que um bug de parâmetro acabe misturando os dois
efeitos.

## Componentes

### `ProdutoFiscalService::haveriaMudanca(Produto $produto, array $fiscalXml): bool`

Novo método público, sem efeito colateral. Percorre os mesmos 4 campos de
`self::CAMPOS` que `aplicarDoXml()` já percorre, chamando
`PoliticaConflitoFiscal::decidir()` pra cada um:

- `PREENCHER` → retorna `true` imediatamente (há algo a preencher).
- `DIVERGENCIA` → só conta como mudança se ainda não existir uma
  divergência aberta idêntica (mesmo produto + campo + valor do XML) — a
  checagem de "já aberta" que `registrarDivergencia()` já faz precisa virar
  um método privado compartilhado (`divergenciaJaAberta()`) pra não
  duplicar a query.
- `NADA` → segue pro próximo campo.

Se nenhum campo resultar em mudança, retorna `false`.

### `EntradaNfController::parse()` — resposta estendida

Sem mudança de contrato pro caminho normal (nota nova). Quando
`ja_lancada === true`:

- Cada item do array `itens` é avaliado com `haveriaMudanca()` contra o
  produto batido (só itens com `matched === true` entram nessa avaliação —
  os sem match nunca aparecem no array de resposta quando a nota já foi
  lançada, já que não há produto pra atualizar).
- A nota como um todo ganha `atualizacao_fiscal_disponivel: bool` — `true`
  se pelo menos um item teria mudança.
- Cada item mantém o `ncm`/`cest`/`origem`/`tributacao_icms` extraídos do
  XML (já existentes hoje), acrescido de `sera_atualizado: bool` (o
  resultado individual de `haveriaMudanca()` pra aquele item).

### Novo endpoint: `POST /entradas-nf/atualizar-fiscal`

`EntradaNfController::atualizarFiscal(Request $request, ProdutoFiscalService $fiscalService): JsonResponse`

Validação de entrada: mesma forma de `itens` que `store()` já valida hoje
(reaproveita as mesmas regras de `produto_id`/campos fiscais), mas
`chave_acesso` passa a ser **obrigatório** (não `nullable` como em
`store()`), porque este endpoint só faz sentido pra uma nota que já existe.

Corpo do método:

```php
public function atualizarFiscal(Request $request, ProdutoFiscalService $fiscalService): JsonResponse
{
    $validated = $request->validate([
        'chave_acesso'            => ['required', 'string', 'max:44'],
        'itens'                   => ['required', 'array', 'min:1'],
        'itens.*.produto_id'      => ['required', 'uuid', 'exists:produtos,id'],
        'itens.*.ncm'             => ['nullable', 'string', 'max:8'],
        'itens.*.cest'            => ['nullable', 'string', 'max:7'],
        'itens.*.origem'          => ['nullable', 'integer', 'min:0', 'max:8'],
        'itens.*.tributacao_icms' => ['nullable', 'string', 'in:NORMAL,ST'],
    ]);

    $notaExistente = NotaEntrada::where('chave_acesso', $validated['chave_acesso'])->first();
    if (!$notaExistente) {
        return response()->json([
            'message' => 'Esta nota ainda não foi lançada — use a importação normal.',
        ], 422);
    }

    $produtosAtualizados = 0;
    foreach ($validated['itens'] as $item) {
        $produto = Produto::find($item['produto_id']);
        if (!$produto) {
            continue;
        }

        if ($fiscalService->haveriaMudanca($produto, $item)) {
            $fiscalService->aplicarDoXml($produto, $item, $notaExistente->id);
            $produtosAtualizados++;
        }
    }

    if ($produtosAtualizados === 0) {
        return response()->json([
            'message' => 'Esta nota fiscal já foi lançada anteriormente.',
        ], 422);
    }

    return response()->json([
        'message'              => 'Dados fiscais atualizados.',
        'produtos_atualizados' => $produtosAtualizados,
    ]);
}
```

Nota: `haveriaMudanca()` é chamado de novo aqui (não só confia no
`sera_atualizado` que o frontend recebeu do `parse()`) porque o estado pode
ter mudado entre o preview e a confirmação (outra aba, outro usuário) — a
mesma disciplina de "nunca confiar cegamente em estado que passou pelo
cliente" já usada no resto do projeto pros campos fiscais.

`aplicarDoXml()` já é idempotente e nunca lança (`Nunca lança` está
documentado no próprio método) — nenhuma mudança nele é necessária.

Rota registrada em `routes/api.php`, mesmo grupo/middleware de
`entradas-nf.store` (autenticado, mesmo tenant).

### Frontend — `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`

`ItemPreview` e `NotaPreview` ganham os campos novos
(`sera_atualizado` no item, `atualizacao_fiscal_disponivel` na nota).

Quando `preview.ja_lancada === true`:

- **`atualizacao_fiscal_disponivel === false`**: comportamento idêntico ao
  atual — aviso vermelho "Esta nota fiscal já foi lançada anteriormente.
  Não é possível confirmar de novo.", botão desabilitado.
- **`atualizacao_fiscal_disponivel === true`**: aviso âmbar substituindo o
  vermelho — "Esta nota já foi lançada. N produto(s) têm dados fiscais
  pendentes que serão atualizados a partir deste XML. Nenhum estoque ou
  produto novo será alterado." (N = contagem de itens com
  `sera_atualizado === true`). A tabela esconde as colunas Categoria, Qtd,
  Custo, Venda (não fazem sentido nesse modo) e mantém Descrição + Fiscal.
  O botão de ação vira "Atualizar dados fiscais" e chama
  `POST /entradas-nf/atualizar-fiscal` em vez de `POST /entradas-nf`,
  mandando só `chave_acesso` + os itens com `produto_id` presente.
  Sucesso: toast "`N` produtos atualizados." e volta pra `/produtos`
  (mesmo padrão do fluxo normal).

Sem novo componente de tela — é o mesmo `page.tsx`, com um branch a mais
na renderização condicional que já existe hoje pra `ja_lancada`.

## Fora de escopo (explícito)

- Nenhum registro de auditoria novo (nem campo, nem log) marcando que uma
  nota foi reprocessada — decisão do usuário na fase de brainstorming. O
  rastro que já existe (`fiscal_fonte`/`fiscal_revisado_em` no produto, e
  `ProdutoFiscalDivergencia` pra divergências) é considerado suficiente.
- Itens sem produto correspondente numa nota já lançada não geram produto
  novo nem aparecem na tela — são descartados silenciosamente no `parse()`.
- Nenhuma mudança em `store()`, `NotaEntradaXmlParser`,
  `PoliticaConflitoFiscal` ou nas migrations existentes.

## Testes

- `ProdutoFiscalServiceTest`: `haveriaMudanca()` retorna `true` quando
  campo vazio recebe valor do XML; `false` quando valores já batem; `true`
  quando valores divergem e não há divergência aberta; `false` quando a
  mesma divergência já está aberta (idempotência do reenvio).
- `EntradaNfControllerTest` (feature, precisa de Postgres — mesma
  limitação de ambiente já documentada no projeto, não roda localmente):
  `atualizarFiscal()` retorna 422 com a mensagem padrão quando não há nada
  pra atualizar; retorna 200 com a contagem certa quando há; retorna 422
  quando `chave_acesso` não corresponde a nenhuma nota lançada; nunca
  altera `qty_atual` do produto nem cria `NotaEntrada`/`NotaEntradaItem`.
