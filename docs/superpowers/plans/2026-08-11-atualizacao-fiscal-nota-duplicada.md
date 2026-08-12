# Atualização fiscal via reenvio de nota de entrada já lançada — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que o reenvio do XML de uma nota de entrada já lançada
sirva só pra atualizar campos fiscais pendentes nos produtos batidos por
código de barras, sem nunca tocar estoque/custo, criar produto novo, ou
criar uma nova `NotaEntrada`. Se não houver nada fiscal pra atualizar, o
sistema continua recusando exatamente como hoje.

**Architecture:** Reaproveita inteiramente `ProdutoFiscalService`/
`PoliticaConflitoFiscal` (a mesma política de preencher-vazio/
abrir-divergência/não-fazer-nada já usada na importação normal). Só duas
peças novas: um método de leitura (`haveriaMudanca()`) que diz se aplicar
o XML mudaria algo num produto sem escrever nada, e um endpoint HTTP
separado (`POST /entradas-nf/atualizar-fiscal`) com contrato restrito
(fiscal-only). O frontend troca de fluxo (aviso + botão + endpoint
chamado) conforme o que `parse()` devolver.

**Tech Stack:** Laravel 11 (PHP 8.3+, `declare(strict_types=1)`), Eloquent,
Next.js 14 + TypeScript, mesmos padrões dos arquivos que este plano edita.

## Global Constraints

- `ProdutoFiscalService::CAMPOS` continua sendo a lista única de campos
  fiscais tratados: `['ncm', 'cest', 'origem', 'tributacao_icms']`. `cfop`
  e `cst_csosn` nunca entram nessa lógica (CFOP é da operação, não do
  produto; `cst_csosn` não tem coluna correspondente em `produtos`).
- Nenhuma migration nova, nenhuma tabela nova.
- O endpoint novo nunca chama `EstoqueService::registrarEntradaItem()`,
  nunca cria `NotaEntrada`/`NotaEntradaItem`, nunca cria `Produto` novo.
- `PoliticaConflitoFiscal::decidir()` (`PREENCHER`/`NADA`/`DIVERGENCIA`) é
  a única fonte de decisão — nenhuma task deste plano reimplementa essa
  lógica, só a consome.
- Rota nova entra no mesmo grupo de middleware das rotas de escrita de
  `entradas-nf` já existentes: `['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE']`
  (`backend/routes/api.php`, grupo que começa em `Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])->group(...)` por volta da linha 238).
- Frontend: sem framework de teste automatizado configurado neste projeto
  (`frontend/package.json` não tem script `test`) — a validação da Task 3
  é `tsc --noEmit` limpo + roteiro de verificação manual, não testes
  automatizados.
- Testes de backend que envolvem HTTP + Postgres (`Tests\Feature\*`) não
  rodam neste ambiente de desenvolvimento local (sem Postgres/Docker) —
  escrever os testes normalmente, mas não é esperado poder rodá-los aqui;
  só `php -l` e os testes puramente unitários (`Tests\Unit\*`) rodam local.

---

### Task 1: `ProdutoFiscalService::haveriaMudanca()` — checagem seca de mudança fiscal

**Files:**
- Modify: `backend/app/Services/Fiscal/ProdutoFiscalService.php`
- Test: `backend/tests/Feature/ProdutoFiscalTest.php`

**Interfaces:**
- Consumes: `PoliticaConflitoFiscal::decidir(mixed $atual, mixed $doXml): string`
  (retorna `PoliticaConflitoFiscal::PREENCHER`/`NADA`/`DIVERGENCIA`,
  `backend/app/Services/Fiscal/PoliticaConflitoFiscal.php`); `self::CAMPOS`
  e `self::ORIGEM_XML` (já existentes na própria classe); o método privado
  `sanitizar()` (já existente).
- Produces: `public function haveriaMudanca(Produto $produto, array $fiscalXml): bool`
  — usado pela Task 2 no controller novo. `private function divergenciaJaAberta(Produto $produto, string $campo, mixed $doXml): bool`
  — método novo, extraído do corpo de `registrarDivergencia()`, também
  chamado por `haveriaMudanca()`.

- [ ] **Step 1: Extrair `divergenciaJaAberta()` de `registrarDivergencia()`**

Em `backend/app/Services/Fiscal/ProdutoFiscalService.php`, o método
`registrarDivergencia()` atual (perto do fim do arquivo) tem:

```php
private function registrarDivergencia(
    Produto $produto,
    string $campo,
    mixed $atual,
    mixed $doXml,
    ?string $notaEntradaId,
): void {
    $jaAberta = ProdutoFiscalDivergencia::where('produto_id', $produto->id)
        ->where('campo', $campo)
        ->whereNull('resolvido_em')
        ->where('valor_xml', (string) $doXml)
        ->exists();

    if ($jaAberta) {
        return;
    }

    ProdutoFiscalDivergencia::create([
        'oficina_id'      => TenancyContext::get(),
        'produto_id'      => $produto->id,
        'nota_entrada_id' => $notaEntradaId,
        'campo'           => $campo,
        'valor_atual'     => $atual === null ? null : (string) $atual,
        'valor_xml'       => (string) $doXml,
    ]);
}
```

Substituir pelo par de métodos abaixo (extrai a query de "já aberta" pra
um método próprio, sem mudar nenhum comportamento):

```php
private function divergenciaJaAberta(Produto $produto, string $campo, mixed $doXml): bool
{
    return ProdutoFiscalDivergencia::where('produto_id', $produto->id)
        ->where('campo', $campo)
        ->whereNull('resolvido_em')
        ->where('valor_xml', (string) $doXml)
        ->exists();
}

private function registrarDivergencia(
    Produto $produto,
    string $campo,
    mixed $atual,
    mixed $doXml,
    ?string $notaEntradaId,
): void {
    if ($this->divergenciaJaAberta($produto, $campo, $doXml)) {
        return;
    }

    ProdutoFiscalDivergencia::create([
        'oficina_id'      => TenancyContext::get(),
        'produto_id'      => $produto->id,
        'nota_entrada_id' => $notaEntradaId,
        'campo'           => $campo,
        'valor_atual'     => $atual === null ? null : (string) $atual,
        'valor_xml'       => (string) $doXml,
    ]);
}
```

- [ ] **Step 2: Rodar `php -l` pra confirmar que o refactor não quebrou sintaxe**

Run: `cd backend && php -l app/Services/Fiscal/ProdutoFiscalService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Escrever os testes de `haveriaMudanca()` (vão falhar — método ainda não existe)**

Em `backend/tests/Feature/ProdutoFiscalTest.php`, adicionar uma nova seção
de testes (depois da seção "── Regressão: origem=0 ... ────" e antes da
seção "── Endpoint de pendências fiscais ───"). Estes testes chamam o
serviço diretamente (`new ProdutoFiscalService()`), sem passar por HTTP —
mesmo padrão de instanciação direta que `ProdutoFiscalServiceSanitizacaoTest`
usa, mas aqui como Feature test porque `haveriaMudanca()` consulta o banco
(via `divergenciaJaAberta()`):

```php
    // ── haveriaMudanca(): checagem seca, sem escrever nada ──────────────

    public function test_haveria_mudanca_true_quando_campo_vazio_seria_preenchido(): void
    {
        [$oficina] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'Correia', 'sku' => 'COR-02', 'categoria' => 'Motor', 'oficina_id' => $oficina->id,
        ]);

        $service = new \App\Services\Fiscal\ProdutoFiscalService();
        $resultado = $service->haveriaMudanca($produto, ['ncm' => '84212300']);

        $this->assertTrue($resultado);
        $this->assertNull($produto->fresh()->ncm); // confirma que NÃO escreveu nada
    }

    public function test_haveria_mudanca_false_quando_valores_ja_batem(): void
    {
        [$oficina] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'Correia', 'sku' => 'COR-03', 'categoria' => 'Motor', 'oficina_id' => $oficina->id,
            'ncm' => '84212300', 'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);

        $service = new \App\Services\Fiscal\ProdutoFiscalService();
        $resultado = $service->haveriaMudanca($produto, ['ncm' => '84212300']);

        $this->assertFalse($resultado);
    }

    public function test_haveria_mudanca_true_quando_diverge_e_nao_ha_divergencia_aberta(): void
    {
        [$oficina] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'Correia', 'sku' => 'COR-04', 'categoria' => 'Motor', 'oficina_id' => $oficina->id,
            'ncm' => '11111111', 'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);

        $service = new \App\Services\Fiscal\ProdutoFiscalService();
        $resultado = $service->haveriaMudanca($produto, ['ncm' => '22222222']);

        $this->assertTrue($resultado);
    }

    public function test_haveria_mudanca_false_quando_mesma_divergencia_ja_esta_aberta(): void
    {
        [$oficina] = $this->criarOficinaComAdmin();
        $produto = Produto::create([
            'nome' => 'Correia', 'sku' => 'COR-05', 'categoria' => 'Motor', 'oficina_id' => $oficina->id,
            'ncm' => '11111111', 'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);
        \App\Models\ProdutoFiscalDivergencia::create([
            'oficina_id' => $oficina->id, 'produto_id' => $produto->id,
            'campo' => 'ncm', 'valor_atual' => '11111111', 'valor_xml' => '22222222',
        ]);

        $service = new \App\Services\Fiscal\ProdutoFiscalService();
        $resultado = $service->haveriaMudanca($produto, ['ncm' => '22222222']);

        $this->assertFalse($resultado);
    }
```

Run: `cd backend && php artisan test --filter=ProdutoFiscalTest` (não roda
neste ambiente local — Postgres ausente; só confirmar que `php -l` do
arquivo de teste está limpo)
Expected: falha por método `haveriaMudanca()` inexistente (ou, se não for
possível rodar localmente, seguir direto pro Step 4)

- [ ] **Step 4: Implementar `haveriaMudanca()`**

Em `backend/app/Services/Fiscal/ProdutoFiscalService.php`, adicionar logo
depois de `aplicarDoXml()`:

```php
    /**
     * Diz se aplicarDoXml() mudaria algo neste produto — sem escrever
     * nada. Usado pra decidir se uma nota já lançada tem algo de fato pra
     * reportar (ver EntradaNfController::atualizarFiscal()).
     */
    public function haveriaMudanca(Produto $produto, array $fiscalXml): bool
    {
        foreach (self::CAMPOS as $campo) {
            $doXml = $this->sanitizar($campo, $fiscalXml[self::ORIGEM_XML[$campo]] ?? null);
            $atual = $produto->{$campo};

            switch (PoliticaConflitoFiscal::decidir($atual, $doXml)) {
                case PoliticaConflitoFiscal::PREENCHER:
                    return true;

                case PoliticaConflitoFiscal::DIVERGENCIA:
                    if (!$this->divergenciaJaAberta($produto, $campo, $doXml)) {
                        return true;
                    }
                    break;
            }
        }

        return false;
    }
```

- [ ] **Step 5: Rodar `php -l` e conferir os 4 testes novos**

Run: `cd backend && php -l app/Services/Fiscal/ProdutoFiscalService.php`
Expected: `No syntax errors detected`
Run (se Postgres disponível): `php artisan test --filter=ProdutoFiscalTest`
Expected: todos os testes de `ProdutoFiscalTest` passam, incluindo os 4 novos

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Fiscal/ProdutoFiscalService.php backend/tests/Feature/ProdutoFiscalTest.php
git commit -m "feat(fiscal): ProdutoFiscalService::haveriaMudanca() - checagem seca de atualizacao fiscal"
```

---

### Task 2: `EntradaNfController` — resposta estendida do `parse()` + endpoint `atualizarFiscal()`

**Files:**
- Modify: `backend/app/Http/Controllers/EntradaNfController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/EntradaNfTest.php`

**Interfaces:**
- Consumes: `ProdutoFiscalService::haveriaMudanca(Produto $produto, array $fiscalXml): bool`
  (Task 1); `ProdutoFiscalService::aplicarDoXml(Produto $produto, array $fiscalXml, ?string $notaEntradaId): void`
  (já existente); `NotaEntrada` model (`chave_acesso`, `id`).
- Produces: resposta de `POST /entradas-nf/parse` ganha, por item,
  `sera_atualizado: bool` (só quando a nota é duplicada), e no nível da
  nota `atualizacao_fiscal_disponivel: bool`. Rota nova
  `POST /entradas-nf/atualizar-fiscal` → `EntradaNfController::atualizarFiscal()`,
  respondendo `422 {"message": "Esta nota fiscal já foi lançada anteriormente."}`
  quando não há nada a atualizar, ou `200 {"message": "Dados fiscais atualizados.", "produtos_atualizados": N}`.

- [ ] **Step 1: Escrever os testes de `parse()` estendido (vão falhar)**

Em `backend/tests/Feature/EntradaNfTest.php`, adicionar depois de
`test_parse_avisa_nota_ja_lancada()`:

```php
    public function test_parse_nota_ja_lancada_sinaliza_atualizacao_fiscal_disponivel(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => '35260712345678000199550010000012340000000001', 'numero_nf' => '1234']);
        Produto::create([
            'nome' => 'Filtro de Óleo Existente', 'sku' => 'FLT-EXIST', 'categoria' => 'Filtros',
            'codigo_barras' => '7891234567890', 'qty_atual' => 3, 'qty_minima' => 5, 'preco_venda' => 40,
            // sem ncm — o XML de teste traz NCM? Não traz (xmlValido() não tem NCM/ICMS).
            // Este teste precisa de um XML com dado fiscal — usar xmlComDadosFiscais() (Step 2 abaixo).
        ]);

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlComDadosFiscais());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('ja_lancada'));
        $this->assertTrue($response->json('atualizacao_fiscal_disponivel'));
        $this->assertTrue($response->json('itens.0.sera_atualizado'));
    }

    public function test_parse_nota_ja_lancada_sem_nada_fiscal_pra_atualizar(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => '35260712345678000199550010000012340000000001', 'numero_nf' => '1234']);
        Produto::create([
            'nome' => 'Filtro de Óleo Existente', 'sku' => 'FLT-EXIST', 'categoria' => 'Filtros',
            'codigo_barras' => '7891234567890', 'qty_atual' => 3, 'qty_minima' => 5, 'preco_venda' => 40,
            'ncm' => '84212300', 'origem' => 0, 'tributacao_icms' => 'ST',
            'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlComDadosFiscais());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('ja_lancada'));
        $this->assertFalse($response->json('atualizacao_fiscal_disponivel'));
    }

    public function test_parse_nota_ja_lancada_descarta_itens_sem_produto_correspondente(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => '35260712345678000199550010000012340000000001', 'numero_nf' => '1234']);
        // Nenhum produto cadastrado com os códigos de barras do XML.

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlValido());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('ja_lancada'));
        // xmlValido() tem 2 itens (1 matched se existisse produto, 1 sempre sem match) —
        // sem nenhum produto cadastrado, os dois ficam sem match e a lista fica vazia.
        $this->assertCount(0, $response->json('itens'));
        $this->assertFalse($response->json('atualizacao_fiscal_disponivel'));
    }
```

Adicionar também o fixture `xmlComDadosFiscais()` como um segundo método
privado na classe, ao lado de `xmlValido()` — mesma estrutura, mas com um
grupo `<imposto><ICMS><ICMS00>` trazendo NCM/origem/CST, pro parser extrair
dado fiscal de verdade:

```php
    private function xmlComDadosFiscais(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
      <ide>
        <nNF>1234</nNF>
        <serie>1</serie>
        <dhEmi>2026-07-01T09:15:32-03:00</dhEmi>
      </ide>
      <emit>
        <CNPJ>12345678000199</CNPJ>
        <xNome>Auto Pecas Distribuidora LTDA</xNome>
      </emit>
      <det nItem="1">
        <prod>
          <cProd>FORN-001</cProd>
          <cEAN>7891234567890</cEAN>
          <xProd>FILTRO DE OLEO XPTO</xProd>
          <qCom>10.0000</qCom>
          <vUnCom>15.5000</vUnCom>
          <NCM>84212300</NCM>
        </prod>
        <imposto>
          <ICMS>
            <ICMS00>
              <orig>0</orig>
              <CST>00</CST>
            </ICMS00>
          </ICMS>
        </imposto>
      </det>
      <total>
        <ICMSTot>
          <vNF>155.00</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }
```

Run: `php -l backend/tests/Feature/EntradaNfTest.php`
Expected: `No syntax errors detected` (o teste em si não roda localmente —
sem Postgres)

- [ ] **Step 2: Implementar a extensão de `parse()`**

Em `backend/app/Http/Controllers/EntradaNfController.php`, o método
`parse()` monta `$itens` via `array_map` e depois calcula `$jaLancada`.
Reestruturar assim (a lógica de montagem de cada item continua igual —
só acrescenta os campos novos condicionados a `$jaLancada`, e isso exige
mover o cálculo de `$jaLancada` pra ANTES do `array_map`, e injetar
`ProdutoFiscalService` no método):

```php
    public function parse(Request $request, NotaEntradaXmlParser $parser, ProdutoFiscalService $fiscalService): JsonResponse
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'max:2048'],
        ]);

        $conteudo = (string) file_get_contents($request->file('arquivo')->getRealPath());

        try {
            $dados = $parser->parse($conteudo);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $config          = Configuracao::first();
        $markup          = (float) ($config?->markup_padrao_entrada_nf ?? 40);
        $qtyMinimaPadrao = (int) ($config?->estoque_limite_padrao ?? 5);

        $jaLancada = $dados['chave_acesso']
            ? NotaEntrada::where('chave_acesso', $dados['chave_acesso'])->exists()
            : false;

        $itens = array_values(array_filter(array_map(function (array $item) use ($markup, $qtyMinimaPadrao, $jaLancada, $fiscalService) {
            $produto = $item['codigo_barras']
                ? Produto::where('codigo_barras', $item['codigo_barras'])->first()
                : null;

            if ($produto) {
                return [
                    'codigo_barras'   => $item['codigo_barras'],
                    'descricao_xml'   => $item['descricao'],
                    'quantidade'      => $item['quantidade'],
                    'valor_unitario'  => $item['valor_unitario'],
                    'matched'         => true,
                    'produto_id'      => $produto->id,
                    'nome'            => $produto->nome,
                    'categoria'       => $produto->categoria,
                    'unidade'         => $produto->unidade,
                    'unidade_xml'     => $item['unidade'],
                    'qty_atual'       => $produto->qty_atual,
                    'preco_venda'     => $produto->preco_venda,
                    'qty_minima'      => $produto->qty_minima,
                    'ncm'             => $item['ncm'],
                    'cfop'            => $item['cfop'],
                    'cest'            => $item['cest'],
                    'origem'          => $item['origem'],
                    'cst_csosn'       => $item['cst_csosn'],
                    'tributacao_icms' => $item['tributacao_icms'],
                    'fiscal_pendente' => $item['ncm'] === null || $item['tributacao_icms'] === null,
                    'sera_atualizado' => $jaLancada ? $fiscalService->haveriaMudanca($produto, $item) : false,
                ];
            }

            // Nota já lançada: item sem produto correspondente não tem o
            // que atualizar (este fluxo nunca cria produto novo) — descarta.
            if ($jaLancada) {
                return null;
            }

            $custo = $item['valor_unitario'];

            return [
                'codigo_barras'   => $item['codigo_barras'],
                'descricao_xml'   => $item['descricao'],
                'quantidade'      => $item['quantidade'],
                'valor_unitario'  => $custo,
                'matched'         => false,
                'produto_id'      => null,
                'nome'            => $item['descricao'],
                'categoria'       => 'Outros',
                'unidade'         => $item['unidade'] ?? 'Un',
                'unidade_xml'     => $item['unidade'],
                'qty_atual'       => 0,
                'preco_venda'     => round($custo * (1 + $markup / 100), 2),
                'qty_minima'      => $qtyMinimaPadrao,
                'ncm'             => $item['ncm'],
                'cfop'            => $item['cfop'],
                'cest'            => $item['cest'],
                'origem'          => $item['origem'],
                'cst_csosn'       => $item['cst_csosn'],
                'tributacao_icms' => $item['tributacao_icms'],
                'fiscal_pendente' => $item['ncm'] === null || $item['tributacao_icms'] === null,
                'sera_atualizado' => false,
            ];
        }, $dados['itens']), fn ($item) => $item !== null));

        return response()->json([
            'numero_nf'       => $dados['numero_nf'],
            'serie'           => $dados['serie'],
            'chave_acesso'    => $dados['chave_acesso'],
            'data_emissao'    => $dados['data_emissao'],
            'fornecedor_nome' => $dados['fornecedor_nome'],
            'fornecedor_cnpj' => $dados['fornecedor_cnpj'],
            'valor_total'     => $dados['valor_total'],
            'ja_lancada'      => $jaLancada,
            'atualizacao_fiscal_disponivel' => $jaLancada && collect($itens)->contains('sera_atualizado', true),
            'itens'           => $itens,
            'xml_original'    => $conteudo,
        ]);
    }
```

Nota: `array_filter`/`array_values` com callback `fn ($item) => $item !== null`
substitui os itens descartados (retorno `null` no branch "sem match e nota
já lançada") — `array_filter` sem segunda flag descartaria também um item
legítimo cujo primeiro valor fosse falsy, por isso o callback explícito.

- [ ] **Step 3: Escrever os testes de `atualizarFiscal()` (vão falhar — endpoint ainda não existe)**

Em `backend/tests/Feature/EntradaNfTest.php`, adicionar depois de
`test_confirmar_entrada_rejeita_chave_ja_lancada()`:

```php
    public function test_atualizar_fiscal_aplica_campos_pendentes_e_retorna_contagem(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => 'CHAVE-DUPLICADA', 'numero_nf' => '1']);
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-02', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
        ]);

        $payload = [
            'chave_acesso' => 'CHAVE-DUPLICADA',
            'itens'        => [[
                'produto_id' => $produto->id,
                'ncm' => '85122000', 'origem' => 2, 'tributacao_icms' => 'NORMAL',
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf/atualizar-fiscal', $payload);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('produtos_atualizados'));
        $produto->refresh();
        $this->assertSame('85122000', $produto->ncm);
        $this->assertSame(2, $produto->origem);
        $this->assertSame('NORMAL', $produto->tributacao_icms);
        // Nunca mexeu em estoque.
        $this->assertSame(5, $produto->qty_atual);
    }

    public function test_atualizar_fiscal_recusa_quando_nao_ha_nada_pra_atualizar(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => 'CHAVE-DUPLICADA', 'numero_nf' => '1']);
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-03', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
            'ncm' => '85122000', 'origem' => 2, 'tributacao_icms' => 'NORMAL',
            'fiscal_fonte' => 'MANUAL', 'fiscal_revisado_em' => now(),
        ]);

        $payload = [
            'chave_acesso' => 'CHAVE-DUPLICADA',
            'itens'        => [[
                'produto_id' => $produto->id,
                'ncm' => '85122000', 'origem' => 2, 'tributacao_icms' => 'NORMAL',
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf/atualizar-fiscal', $payload);

        $response->assertStatus(422);
        $this->assertSame('Esta nota fiscal já foi lançada anteriormente.', $response->json('message'));
    }

    public function test_atualizar_fiscal_recusa_chave_de_nota_nunca_lancada(): void
    {
        $token = $this->loginAdmin();
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-04', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
        ]);

        $payload = [
            'chave_acesso' => 'CHAVE-NUNCA-LANCADA',
            'itens'        => [[
                'produto_id' => $produto->id,
                'ncm' => '85122000',
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf/atualizar-fiscal', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('não foi lançada', $response->json('message'));
    }

    public function test_atualizar_fiscal_nunca_cria_nota_entrada_nem_mexe_estoque(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => 'CHAVE-DUPLICADA', 'numero_nf' => '1']);
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-05', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2, 'preco_venda' => 20,
        ]);

        $this->withToken($token)->postJson('/api/entradas-nf/atualizar-fiscal', [
            'chave_acesso' => 'CHAVE-DUPLICADA',
            'itens'        => [['produto_id' => $produto->id, 'ncm' => '85122000']],
        ]);

        $this->assertSame(1, NotaEntrada::count()); // só a original, nenhuma nova
        $this->assertSame(5, $produto->fresh()->qty_atual);
        $this->assertDatabaseCount('notas_entrada_itens', 0);
    }
```

Run: `php -l backend/tests/Feature/EntradaNfTest.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Implementar `atualizarFiscal()`**

Em `backend/app/Http/Controllers/EntradaNfController.php`, adicionar
depois de `store()`:

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

- [ ] **Step 5: Registrar a rota**

Em `backend/routes/api.php`, no grupo
`Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])->group(...)`
(o mesmo grupo de `entradas-nf/parse` e `entradas-nf`), adicionar logo
depois da linha `Route::post('entradas-nf', [EntradaNfController::class, 'store']);`:

```php
    Route::post('entradas-nf/atualizar-fiscal', [EntradaNfController::class, 'atualizarFiscal']);
```

- [ ] **Step 6: Rodar `php -l` em tudo que mudou**

Run:
```bash
cd backend
php -l app/Http/Controllers/EntradaNfController.php
php -l routes/api.php
php -l tests/Feature/EntradaNfTest.php
```
Expected: `No syntax errors detected` nos três

- [ ] **Step 7: Rodar os testes de feature se Postgres estiver disponível**

Run: `cd backend && php artisan test --filter=EntradaNfTest`
Expected: todos passam, incluindo os 7 testes novos (3 de `parse()`
estendido + 4 de `atualizarFiscal()`)

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Controllers/EntradaNfController.php backend/routes/api.php backend/tests/Feature/EntradaNfTest.php
git commit -m "feat(fiscal): endpoint de atualizacao fiscal para nota de entrada ja lancada"
```

---

### Task 3: Frontend — tela de entrada de NF com fluxo de atualização fiscal

**Files:**
- Modify: `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`

**Interfaces:**
- Consumes: `POST /api/entradas-nf/parse` (Task 2) — resposta agora inclui
  `atualizacao_fiscal_disponivel: boolean` na nota e `sera_atualizado: boolean`
  em cada item; itens sem match somem do array quando `ja_lancada === true`.
  `POST /api/entradas-nf/atualizar-fiscal` (Task 2) — body
  `{ chave_acesso: string, itens: Array<{ produto_id, ncm, cest, origem, tributacao_icms }> }`,
  resposta `{ message: string, produtos_atualizados: number }` (200) ou
  `{ message: string }` (422).
- Produces: nenhuma interface nova pra outros componentes — mudança
  isolada nesta página.

- [ ] **Step 1: Estender as interfaces TypeScript**

Em `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`, adicionar
campos nas interfaces já existentes:

```tsx
interface ItemPreview {
  codigo_barras: string | null
  descricao_xml: string
  quantidade: number
  valor_unitario: number
  matched: boolean
  produto_id: string | null
  nome: string
  categoria: string
  unidade: string
  qty_atual: number
  preco_venda: number
  qty_minima: number
  ncm: string | null
  cfop: string | null
  cest: string | null
  origem: number | null
  cst_csosn: string | null
  tributacao_icms: string | null
  unidade_xml: string | null
  fiscal_pendente: boolean
  sera_atualizado: boolean
}

interface NotaPreview {
  numero_nf: string | null
  serie: string | null
  chave_acesso: string | null
  data_emissao: string | null
  fornecedor_nome: string | null
  fornecedor_cnpj: string | null
  valor_total: number
  ja_lancada: boolean
  atualizacao_fiscal_disponivel: boolean
  itens: ItemPreview[]
  xml_original: string
}
```

- [ ] **Step 2: Adicionar `handleAtualizarFiscal()` ao lado de `handleConfirmar()`**

Logo depois da função `handleConfirmar()` existente:

```tsx
  async function handleAtualizarFiscal() {
    if (!preview) return
    setConfirming(true)
    try {
      const res = await api.post<{ produtos_atualizados: number }>('/entradas-nf/atualizar-fiscal', {
        chave_acesso: preview.chave_acesso,
        itens: itens
          .filter(i => i.matched && i.produto_id)
          .map(i => ({
            produto_id: i.produto_id,
            ncm: i.ncm,
            cest: i.cest,
            origem: i.origem,
            tributacao_icms: i.tributacao_icms,
          })),
      })
      toast(`${res.data.produtos_atualizados} produto(s) atualizado(s).`, 'success')
      router.push('/produtos')
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao atualizar dados fiscais.', 'danger')
    } finally {
      setConfirming(false)
    }
  }
```

- [ ] **Step 3: Ajustar `podeConfirmar` e criar `modoAtualizacaoFiscal`**

Substituir a linha existente:

```tsx
  const podeConfirmar = !!preview && !preview.ja_lancada && itens.length > 0 && !confirming
```

Por:

```tsx
  const modoAtualizacaoFiscal = !!preview && preview.ja_lancada && preview.atualizacao_fiscal_disponivel
  const podeConfirmar = !!preview && !confirming && itens.length > 0
    && (modoAtualizacaoFiscal || !preview.ja_lancada)
```

- [ ] **Step 4: Trocar o aviso e o botão condicionalmente**

Substituir o bloco do aviso vermelho:

```tsx
            {preview.ja_lancada && (
              <p style={{ color: 'var(--danger)', fontSize: 13, marginTop: 16, marginBottom: 0 }}>
                Esta nota fiscal já foi lançada anteriormente. Não é possível confirmar de novo.
              </p>
            )}
```

Por:

```tsx
            {preview.ja_lancada && !modoAtualizacaoFiscal && (
              <p style={{ color: 'var(--danger)', fontSize: 13, marginTop: 16, marginBottom: 0 }}>
                Esta nota fiscal já foi lançada anteriormente. Não é possível confirmar de novo.
              </p>
            )}
            {modoAtualizacaoFiscal && (
              <p style={{ color: 'var(--accent)', fontSize: 13, marginTop: 16, marginBottom: 0 }}>
                Esta nota já foi lançada. {itens.filter(i => i.sera_atualizado).length} produto(s) têm dados
                fiscais pendentes que serão atualizados a partir deste XML. Nenhum estoque ou produto novo
                será alterado.
              </p>
            )}
```

Substituir o botão de confirmar:

```tsx
            <button type="button" onClick={handleConfirmar} disabled={!podeConfirmar} className="font-display"
              style={{
                padding: '10px 28px', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16,
                background: podeConfirmar ? 'var(--accent)' : 'var(--muted)', color: '#000',
                cursor: podeConfirmar ? 'pointer' : 'not-allowed',
              }}>
              {confirming ? 'Confirmando...' : 'Confirmar Entrada'}
            </button>
```

Por:

```tsx
            <button type="button" onClick={modoAtualizacaoFiscal ? handleAtualizarFiscal : handleConfirmar}
              disabled={!podeConfirmar} className="font-display"
              style={{
                padding: '10px 28px', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16,
                background: podeConfirmar ? 'var(--accent)' : 'var(--muted)', color: '#000',
                cursor: podeConfirmar ? 'pointer' : 'not-allowed',
              }}>
              {confirming ? 'Confirmando...' : modoAtualizacaoFiscal ? 'Atualizar dados fiscais' : 'Confirmar Entrada'}
            </button>
```

- [ ] **Step 5: Esconder colunas irrelevantes no modo atualização fiscal**

No cabeçalho da tabela, trocar:

```tsx
                    {['Cód. barras', 'Descrição', 'Status', 'Categoria', 'Qtd', 'Custo', 'Venda', 'Fiscal', ''].map(h => (
```

Por (mantém a estrutura, só filtra as colunas quando em modo fiscal):

```tsx
                    {(modoAtualizacaoFiscal
                      ? ['Cód. barras', 'Descrição', 'Fiscal (XML)']
                      : ['Cód. barras', 'Descrição', 'Status', 'Categoria', 'Qtd', 'Custo', 'Venda', 'Fiscal', '']
                    ).map(h => (
```

No corpo de cada linha, trocar o bloco inteiro do `<tr>` (de
`<tr key={idx}>` até `</tr>`, dentro do `.map((item, idx) => (...))`) por:

```tsx
                    <tr key={idx}>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }} className="font-mono">
                        {item.codigo_barras ?? '-'}
                      </td>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                        <input style={inputStyle} value={item.nome} disabled={item.matched}
                          onChange={e => updateItem(idx, 'nome', e.target.value)} />
                      </td>
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                          <span style={{
                            padding: '3px 8px', borderRadius: 6, fontSize: 11, fontWeight: 700,
                            background: item.matched ? 'rgba(67,160,71,0.15)' : 'rgba(245,166,35,0.15)',
                            color: item.matched ? 'var(--success)' : 'var(--accent)',
                          }}>
                            {item.matched ? 'Existente' : 'Novo'}
                          </span>
                        </td>
                      )}
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                          {item.matched ? (
                            <span style={{ color: 'var(--muted)', fontSize: 13 }}>{item.categoria}</span>
                          ) : (
                            <select style={inputStyle} value={item.categoria} onChange={e => updateItem(idx, 'categoria', e.target.value)}>
                              {CATEGORIAS.map(c => <option key={c} value={c}>{c}</option>)}
                            </select>
                          )}
                        </td>
                      )}
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', width: 80 }}>
                          <input type="number" min={0.01} step="0.01" style={inputStyle} value={item.quantidade}
                            onChange={e => updateItem(idx, 'quantidade', Math.max(0.01, parseFloat(e.target.value) || 0))} />
                        </td>
                      )}
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', width: 100 }}>
                          <input type="number" min={0} step="0.01" style={inputStyle} value={item.valor_unitario}
                            onChange={e => updateItem(idx, 'valor_unitario', Math.max(0, parseFloat(e.target.value) || 0))} />
                        </td>
                      )}
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', width: 100 }}>
                          <input type="number" min={0} step="0.01" style={inputStyle} value={item.preco_venda} disabled={item.matched}
                            onChange={e => updateItem(idx, 'preco_venda', Math.max(0, parseFloat(e.target.value) || 0))} />
                        </td>
                      )}
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', fontSize: 12 }}>
                        {item.fiscal_pendente ? (
                          <span style={{ color: 'var(--accent)' }} title="Faltou NCM ou situação tributária no XML — o produto entrará com pendência fiscal">
                            Pendente
                          </span>
                        ) : (
                          <span style={{ fontFamily: 'JetBrains Mono', color: 'var(--muted)' }}>
                            {item.ncm}
                            {item.tributacao_icms === 'ST' && (
                              <span style={{ color: 'var(--accent)', marginLeft: 6 }}>ST</span>
                            )}
                          </span>
                        )}
                      </td>
                      {!modoAtualizacaoFiscal && (
                        <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                          <button type="button" onClick={() => removeItem(idx)}
                            style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 16 }}
                            title="Remover item">✕</button>
                        </td>
                      )}
                    </tr>
```

E o `colSpan` da linha de "Nenhum item na nota." (`itens.length === 0`)
precisa acompanhar o número de colunas visível — trocar:

```tsx
                    <tr><td colSpan={9} style={{ padding: 24, textAlign: 'center', color: 'var(--muted)' }}>Nenhum item na nota.</td></tr>
```

Por:

```tsx
                    <tr><td colSpan={modoAtualizacaoFiscal ? 3 : 9} style={{ padding: 24, textAlign: 'center', color: 'var(--muted)' }}>Nenhum item na nota.</td></tr>
```

- [ ] **Step 6: `tsc --noEmit` limpo**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erro, exit 0

- [ ] **Step 7: Verificação manual (sem framework de teste automatizado neste projeto)**

Roteiro manual com o servidor de desenvolvimento rodando:
1. Importar um XML novo (nota nunca lançada) → confirma que o fluxo normal
   continua idêntico ao de hoje (nada quebrou).
2. Reenviar o mesmo XML → confirma o aviso âmbar aparecendo com a
   contagem certa de produtos, tabela só com as colunas relevantes, botão
   "Atualizar dados fiscais".
3. Clicar em "Atualizar dados fiscais" → toast de sucesso, produto(s)
   atualizados na tela de Produtos.
4. Reenviar o XML uma terceira vez → agora sem nada pendente, volta pro
   aviso vermelho de hoje ("já foi lançada... não é possível confirmar").
5. Reenviar um XML de uma nota que nunca foi lançada, cujo(s) item(ns) não
   batem com nenhum produto cadastrado → tabela vazia, sem quebra visual.

- [ ] **Step 8: Commit**

```bash
git add "frontend/app/(dashboard)/produtos/entrada-nf/page.tsx"
git commit -m "feat(fiscal): fluxo de atualizacao fiscal na tela de entrada de NF para nota ja lancada"
```
