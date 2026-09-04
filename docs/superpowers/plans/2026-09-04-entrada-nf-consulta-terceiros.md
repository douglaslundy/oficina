# Entrada de NF via consulta ao provedor (QR/código de barras + notas recebidas) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir dar entrada em estoque a partir de uma nota fiscal de fornecedor que só existe em papel — lendo a chave de acesso pelo QR Code/código de barras (ou digitando na mão), consultando Spedy/Focus (que já sincronizam com a SEFAZ), e reaproveitando a mesma tela de revisão que o upload de XML já usa. Adicionalmente, lista as notas emitidas contra o CNPJ da oficina que o provedor já tem sincronizadas.

**Architecture:** Nova interface `ConsultaNotaTerceiroProvider` implementada por `SpedyProvider`/`FocusNfeProvider` (não por `NfePhpProvider`), devolvendo os dados sempre no MESMO array shape que `NotaEntradaXmlParser::parse()` já produz — pra Spedy vem de rodar o XML baixado por esse parser já existente; pra Focus vem de um mapper novo (`FocusNfeRecebidaMapper`) traduzindo o JSON. `EntradaNfController` ganha um método privado `montarPreview()` (extraído de `parse()`, sem mudança de comportamento) reaproveitado por 2 endpoints novos. Frontend: mesma página `entrada-nf`, 3 modos convergindo pro mesmo componente de revisão.

**Tech Stack:** Laravel 12 / PHP 8.2+, PHPUnit (`Http::fake()`), Next.js/TypeScript, `@zxing/browser` + `@zxing/library` (scanner de câmera).

**Spec:** `docs/superpowers/specs/2026-09-04-entrada-nf-consulta-terceiros-design.md`

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo, seguindo o padrão já usado em 100% do projeto.
- NFePHP fica fora de escopo nesta rodada — `NfePhpProvider` não implementa `ConsultaNotaTerceiroProvider`.
- Manifestação automática só como "ciência da operação" (Spedy: `status: "acknowledged"`; Focus: `tipo: "ciencia"`) — nunca confirmação, desconhecimento ou operação não realizada.
- Feature tests (tudo que usa `RefreshDatabase`/Postgres) não rodam na máquina local direto — só via CI, ou via o túnel SSH pra Postgres efêmero documentado na memória `feedback-local-testing` (`ssh -f -N -L 15432:127.0.0.1:15432 root@144.91.92.70` + `DB_HOST=127.0.0.1 DB_PORT=15432 php artisan test ...`). Unit tests (sem DB) rodam localmente sempre.
- Nenhum deploy na VPS nesta rodada — só commit/push pro GitHub (`git push origin main` direto, sem branch, seguindo o padrão desta sessão), a menos que o usuário peça deploy explicitamente.
- Frontend não tem test runner (nem vitest, nem jest) em lugar nenhum do projeto — não introduzir um só pra esta feature.
- Todo texto de UI em português, seguindo o tom já usado no resto do `entrada-nf/page.tsx` (mensagens de erro, labels, toasts).

---

### Task 1: DTOs e interface de consulta (`ConsultaNotaTerceiroResultado`, `ConsultaNotaTerceiroResumo`, `ConsultaNotaTerceiroProvider`)

**Files:**
- Create: `backend/app/Services/Fiscal/Data/ConsultaNotaTerceiroResultado.php`
- Create: `backend/app/Services/Fiscal/Data/ConsultaNotaTerceiroResumo.php`
- Create: `backend/app/Services/Fiscal/Contracts/ConsultaNotaTerceiroProvider.php`
- Test: `backend/tests/Unit/Fiscal/ConsultaNotaTerceiroResultadoTest.php`

**Interfaces:**
- Produces: `ConsultaNotaTerceiroResultado::completa(array $dados)`, `::aguardandoManifestacao()`, `::naoEncontrada()`, `::erro(string $mensagemErro)` — cada um com propriedades públicas `status`, `dados` (`?array`), `mensagemErro` (`?string`). `ConsultaNotaTerceiroResumo` com construtor `(string $chaveAcesso, ?string $fornecedorNome, ?string $fornecedorCnpj, ?string $dataEmissao, float $valorTotal, bool $completa)`. Interface `ConsultaNotaTerceiroProvider` com `consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado` e `listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use PHPUnit\Framework\TestCase;

class ConsultaNotaTerceiroResultadoTest extends TestCase
{
    public function test_completa_carrega_os_dados(): void
    {
        $r = ConsultaNotaTerceiroResultado::completa(['chave_acesso' => 'X', 'itens' => []]);
        $this->assertSame('COMPLETA', $r->status);
        $this->assertSame(['chave_acesso' => 'X', 'itens' => []], $r->dados);
        $this->assertNull($r->mensagemErro);
    }

    public function test_aguardando_manifestacao_nao_carrega_dados(): void
    {
        $r = ConsultaNotaTerceiroResultado::aguardandoManifestacao();
        $this->assertSame('AGUARDANDO_MANIFESTACAO', $r->status);
        $this->assertNull($r->dados);
    }

    public function test_nao_encontrada(): void
    {
        $r = ConsultaNotaTerceiroResultado::naoEncontrada();
        $this->assertSame('NAO_ENCONTRADA', $r->status);
    }

    public function test_erro_carrega_mensagem(): void
    {
        $r = ConsultaNotaTerceiroResultado::erro('Falha ao consultar.');
        $this->assertSame('ERRO', $r->status);
        $this->assertSame('Falha ao consultar.', $r->mensagemErro);
    }

    public function test_resumo_expoe_propriedades(): void
    {
        $r = new ConsultaNotaTerceiroResumo('CHAVE1', 'Fornecedor X', '12345678000199', '2026-09-01', 150.5, true);
        $this->assertSame('CHAVE1', $r->chaveAcesso);
        $this->assertSame('Fornecedor X', $r->fornecedorNome);
        $this->assertSame('12345678000199', $r->fornecedorCnpj);
        $this->assertSame('2026-09-01', $r->dataEmissao);
        $this->assertSame(150.5, $r->valorTotal);
        $this->assertTrue($r->completa);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/ConsultaNotaTerceiroResultadoTest.php`
Expected: FAIL — `Class "App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado" not found`.

- [ ] **Step 3: Write the DTOs and the interface**

`backend/app/Services/Fiscal/Data/ConsultaNotaTerceiroResultado.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class ConsultaNotaTerceiroResultado
{
    private function __construct(
        public readonly string $status, // COMPLETA | AGUARDANDO_MANIFESTACAO | NAO_ENCONTRADA | ERRO
        public readonly ?array $dados = null,
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

`backend/app/Services/Fiscal/Data/ConsultaNotaTerceiroResumo.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class ConsultaNotaTerceiroResumo
{
    public function __construct(
        public readonly string $chaveAcesso,
        public readonly ?string $fornecedorNome,
        public readonly ?string $fornecedorCnpj,
        public readonly ?string $dataEmissao,
        public readonly float $valorTotal,
        public readonly bool $completa,
    ) {}
}
```

`backend/app/Services/Fiscal/Contracts/ConsultaNotaTerceiroProvider.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Contracts;

use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;

interface ConsultaNotaTerceiroProvider
{
    /** Consulta uma NF-e emitida contra o CNPJ da oficina pela chave de
     *  acesso (44 dígitos). Manifesta automaticamente como "ciência da
     *  operação" quando a nota existe mas ainda não está completa. */
    public function consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado;

    /** Lista notas recebidas já sincronizadas pelo provedor, mais recentes
     *  primeiro. $desde filtra por data de emissão quando informado.
     *  @return list<\App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo> */
    public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/ConsultaNotaTerceiroResultadoTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Data/ConsultaNotaTerceiroResultado.php backend/app/Services/Fiscal/Data/ConsultaNotaTerceiroResumo.php backend/app/Services/Fiscal/Contracts/ConsultaNotaTerceiroProvider.php backend/tests/Unit/Fiscal/ConsultaNotaTerceiroResultadoTest.php
git commit -m "feat(fiscal): DTOs e interface de consulta de nota de terceiro"
```

---

### Task 2: `SpedyProvider::consultarNotaRecebida()`

**Files:**
- Modify: `backend/app/Services/Fiscal/Providers/SpedyProvider.php`
- Test: `backend/tests/Unit/Fiscal/SpedyProviderTest.php`

**Interfaces:**
- Consumes: `ConsultaNotaTerceiroResultado` (Task 1), `NotaEntradaXmlParser::parse(string $xml): array` (já existe em `backend/app/Services/NotaEntradaXmlParser.php`).
- Produces: `SpedyProvider implements ConsultaNotaTerceiroProvider` — `consultarNotaRecebida()` pronto pro Task 7/8 usar via `FiscalProviderManager::forTenant()`.

- [ ] **Step 1: Write the failing tests**

Adicionar ao final da classe `SpedyProviderTest` (em `backend/tests/Unit/Fiscal/SpedyProviderTest.php`), dentro do `use` já existente de `Http`:

```php
    public function test_consultar_nota_recebida_completa_baixa_e_faz_parse_do_xml(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
      <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-07-01T09:15:32-03:00</dhEmi></ide>
      <emit><CNPJ>12345678000199</CNPJ><xNome>Fornecedor Teste</xNome></emit>
      <det nItem="1">
        <prod><cEAN>7891234567890</cEAN><xProd>FILTRO DE OLEO</xProd><qCom>10.0000</qCom><vUnCom>15.5000</vUnCom><NCM>84212300</NCM><CFOP>5102</CFOP><uCom>UN</uCom></prod>
        <imposto><ICMS><ICMS00><orig>0</orig><CST>00</CST></ICMS00></ICMS></imposto>
      </det>
    </infNFe>
  </NFe>
</nfeProc>
XML;

        Http::fake([
            '*/inbound-product-invoices?*' => Http::response([
                'items' => [['id' => 'inv-abc', 'accessKey' => '35260712345678000199550010000012340000000001', 'isComplete' => true]],
            ], 200),
            '*/inbound-product-invoices/inv-abc/xml' => Http::response($xml, 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultarNotaRecebida('35260712345678000199550010000012340000000001');

        $this->assertSame('COMPLETA', $r->status);
        $this->assertSame('Fornecedor Teste', $r->dados['fornecedor_nome']);
        $this->assertCount(1, $r->dados['itens']);
        $this->assertSame('84212300', $r->dados['itens'][0]['ncm']);
        $this->assertSame('7891234567890', $r->dados['itens'][0]['codigo_barras']);
    }

    public function test_consultar_nota_recebida_incompleta_manifesta_e_retorna_aguardando(): void
    {
        Http::fake([
            '*/inbound-product-invoices?*' => Http::response([
                'items' => [['id' => 'inv-abc', 'accessKey' => 'CHAVE1', 'isComplete' => false]],
            ], 200),
            '*/inbound-product-invoices/inv-abc/manifest' => Http::response(['status' => 'ok'], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultarNotaRecebida('CHAVE1');

        $this->assertSame('AGUARDANDO_MANIFESTACAO', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/manifest') && $req['status'] === 'acknowledged');
    }

    public function test_consultar_nota_recebida_nao_encontrada(): void
    {
        Http::fake(['*/inbound-product-invoices?*' => Http::response(['items' => []], 200)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultarNotaRecebida('CHAVE-INEXISTENTE');

        $this->assertSame('NAO_ENCONTRADA', $r->status);
    }

    public function test_consultar_nota_recebida_erro_do_provedor(): void
    {
        Http::fake(['*/inbound-product-invoices?*' => Http::response(['message' => 'Chave de API inválida'], 403)]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->consultarNotaRecebida('CHAVE1');

        $this->assertSame('ERRO', $r->status);
        $this->assertStringContainsString('Chave de API inválida', (string) $r->mensagemErro);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/SpedyProviderTest.php --filter=consultar_nota_recebida`
Expected: FAIL — `Call to undefined method App\Services\Fiscal\Providers\SpedyProvider::consultarNotaRecebida()`.

- [ ] **Step 3: Implement `consultarNotaRecebida()`**

Em `backend/app/Services/Fiscal/Providers/SpedyProvider.php`: adicionar `use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;`, `use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;`, `use App\Services\NotaEntradaXmlParser;` no topo; mudar a declaração da classe para `class SpedyProvider implements FiscalProvider, ConsultaNotaTerceiroProvider`; adicionar o método:

```php
    public function consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->get("{$this->baseUrl}/inbound-product-invoices", ['accessKey' => $chaveAcesso]);

        if ($resp->failed()) {
            return ConsultaNotaTerceiroResultado::erro($resp->json('message') ?? 'Erro ao consultar nota na Spedy.');
        }

        $itens = $resp->json('items') ?? [];
        $nota  = $itens[0] ?? null;
        if ($nota === null) {
            return ConsultaNotaTerceiroResultado::naoEncontrada();
        }

        if (($nota['isComplete'] ?? false) !== true) {
            Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
                ->post("{$this->baseUrl}/inbound-product-invoices/{$nota['id']}/manifest", ['status' => 'acknowledged']);

            return ConsultaNotaTerceiroResultado::aguardandoManifestacao();
        }

        $xmlResp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->get("{$this->baseUrl}/inbound-product-invoices/{$nota['id']}/xml");

        if ($xmlResp->failed()) {
            return ConsultaNotaTerceiroResultado::erro('Erro ao baixar o XML da nota na Spedy.');
        }

        return ConsultaNotaTerceiroResultado::completa((new NotaEntradaXmlParser())->parse($xmlResp->body()));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/SpedyProviderTest.php`
Expected: PASS (todos os testes existentes + os 4 novos).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/SpedyProvider.php backend/tests/Unit/Fiscal/SpedyProviderTest.php
git commit -m "feat(fiscal): SpedyProvider consulta nota de terceiro por chave de acesso"
```

---

### Task 3: `SpedyProvider::listarNotasRecebidas()`

**Files:**
- Modify: `backend/app/Services/Fiscal/Providers/SpedyProvider.php`
- Test: `backend/tests/Unit/Fiscal/SpedyProviderTest.php`

**Interfaces:**
- Produces: `SpedyProvider::listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array<ConsultaNotaTerceiroResumo>`.

- [ ] **Step 1: Write the failing test**

```php
    public function test_listar_notas_recebidas_mapeia_a_lista(): void
    {
        Http::fake([
            '*/inbound-product-invoices' => Http::response([
                'items' => [
                    ['accessKey' => 'CHAVE1', 'isComplete' => true, 'amount' => 250.5, 'issuedOn' => '2026-09-01T10:00:00', 'issuer' => ['name' => 'Fornecedor A', 'federalTaxNumber' => '11111111000191']],
                    ['accessKey' => 'CHAVE2', 'isComplete' => false, 'amount' => 80.0, 'issuedOn' => '2026-09-02T10:00:00', 'issuer' => ['name' => 'Fornecedor B', 'federalTaxNumber' => '22222222000192']],
                ],
            ], 200),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $resumos = $p->listarNotasRecebidas('12345678000199');

        $this->assertCount(2, $resumos);
        $this->assertSame('CHAVE1', $resumos[0]->chaveAcesso);
        $this->assertSame('Fornecedor A', $resumos[0]->fornecedorNome);
        $this->assertTrue($resumos[0]->completa);
        $this->assertSame('2026-09-01', $resumos[0]->dataEmissao);
        $this->assertFalse($resumos[1]->completa);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/SpedyProviderTest.php --filter=test_listar_notas_recebidas_mapeia_a_lista`
Expected: FAIL — `Call to undefined method ... listarNotasRecebidas()`.

- [ ] **Step 3: Implement**

Adicionar em `SpedyProvider.php` (mesmo `use ConsultaNotaTerceiroResumo` a acrescentar no topo):

```php
    public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array
    {
        $query = [];
        if ($desde !== null) {
            $query['initialDate'] = $desde->format('Y-m-d');
        }

        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->get("{$this->baseUrl}/inbound-product-invoices", $query);

        if ($resp->failed()) {
            return [];
        }

        return array_map(fn (array $item) => new ConsultaNotaTerceiroResumo(
            chaveAcesso: (string) ($item['accessKey'] ?? ''),
            fornecedorNome: $item['issuer']['name'] ?? null,
            fornecedorCnpj: $item['issuer']['federalTaxNumber'] ?? null,
            dataEmissao: isset($item['issuedOn']) ? substr((string) $item['issuedOn'], 0, 10) : null,
            valorTotal: (float) ($item['amount'] ?? 0),
            completa: ($item['isComplete'] ?? false) === true,
        ), $resp->json('items') ?? []);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/SpedyProviderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/SpedyProvider.php backend/tests/Unit/Fiscal/SpedyProviderTest.php
git commit -m "feat(fiscal): SpedyProvider lista notas recebidas por CNPJ"
```

---

### Task 4: `FocusNfeRecebidaMapper`

**Files:**
- Create: `backend/app/Services/Fiscal/Providers/FocusNfeRecebidaMapper.php`
- Test: `backend/tests/Unit/Fiscal/FocusNfeRecebidaMapperTest.php`

**Interfaces:**
- Consumes: `ValidadorCamposFiscais::{ncm,cest,cfop,origem}` e `ClassificacaoIcms::derivar()` (já existem em `backend/app/Services/Fiscal/`).
- Produces: `FocusNfeRecebidaMapper::paraArray(array $json): array` — mesmo shape de `NotaEntradaXmlParser::parse()`. Usado pelo Task 5.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Providers\FocusNfeRecebidaMapper;
use PHPUnit\Framework\TestCase;

class FocusNfeRecebidaMapperTest extends TestCase
{
    private function jsonBase(array $itemOverrides = []): array
    {
        return [
            'nome_emitente' => 'Fornecedor Teste',
            'documento_emitente' => '12345678000199',
            'chave_nfe' => '35260712345678000199550010000012340000000001',
            'valor_total' => '155.00',
            'data_emissao' => '2026-09-01T10:00:00-03:00',
            'requisicao_nota_fiscal' => [
                'itens' => [array_merge([
                    'codigo_produto' => '88888',
                    'codigo_barras_comercial' => '7891234567890',
                    'descricao' => 'Filtro de oleo',
                    'codigo_ncm' => '84212300',
                    'cfop' => '5102',
                    'icms_situacao_tributaria' => '00',
                    'unidade_comercial' => 'UN',
                    'quantidade_comercial' => '10.0000',
                    'valor_unitario_comercial' => '15.5000',
                ], $itemOverrides)],
            ],
        ];
    }

    public function test_mapeia_dados_da_nota(): void
    {
        $dados = FocusNfeRecebidaMapper::paraArray($this->jsonBase());

        $this->assertSame('35260712345678000199550010000012340000000001', $dados['chave_acesso']);
        $this->assertSame('Fornecedor Teste', $dados['fornecedor_nome']);
        $this->assertSame('12345678000199', $dados['fornecedor_cnpj']);
        $this->assertSame(155.0, $dados['valor_total']);
        $this->assertSame('2026-09-01', $dados['data_emissao']);
        $this->assertSame('1', $dados['serie']);
        $this->assertSame('1234', $dados['numero_nf']);
    }

    public function test_mapeia_item_com_cst(): void
    {
        $dados = FocusNfeRecebidaMapper::paraArray($this->jsonBase(['icms_situacao_tributaria' => '00']));
        $item = $dados['itens'][0];

        $this->assertSame('7891234567890', $item['codigo_barras']);
        $this->assertSame('84212300', $item['ncm']);
        $this->assertSame('5102', $item['cfop']);
        $this->assertSame('00', $item['cst_csosn']);
        $this->assertSame('NORMAL', $item['tributacao_icms']);
        $this->assertSame(10.0, $item['quantidade']);
        $this->assertSame(15.5, $item['valor_unitario']);
        $this->assertNull($item['origem']);
        $this->assertNull($item['cest']);
    }

    public function test_mapeia_item_com_csosn(): void
    {
        $dados = FocusNfeRecebidaMapper::paraArray($this->jsonBase(['icms_situacao_tributaria' => '102']));
        $item = $dados['itens'][0];

        $this->assertSame('102', $item['cst_csosn']);
        $this->assertSame('NORMAL', $item['tributacao_icms']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/FocusNfeRecebidaMapperTest.php`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\ClassificacaoIcms;
use App\Services\Fiscal\ValidadorCamposFiscais;

/**
 * Traduz o JSON de "NFe recebida completa" da Focus
 * (GET /v2/nfes_recebidas/{chave}.json?completa=1) pro mesmo array shape
 * que NotaEntradaXmlParser::parse() produz a partir de XML — é o contrato
 * comum que EntradaNfController já sabe consumir, seja qual for a origem.
 *
 * A Focus não expõe o campo de origem da mercadoria (0-8) nesse JSON — fica
 * sempre null aqui, diferente do caminho via XML. Não bloqueia o
 * lançamento sozinho (fiscal_pendente só olha NCM e tributacao_icms).
 */
final class FocusNfeRecebidaMapper
{
    /**
     * @return array{chave_acesso: ?string, numero_nf: ?string, serie: ?string,
     *   data_emissao: ?string, fornecedor_nome: ?string, fornecedor_cnpj: ?string,
     *   valor_total: float, itens: list<array<string, mixed>>}
     */
    public static function paraArray(array $json): array
    {
        $chave = (string) ($json['chave_nfe'] ?? '');

        $itensJson = $json['requisicao_nota_fiscal']['itens'] ?? [];
        $itens = array_map(function (array $item): array {
            $cstCsosnBruto = (string) ($item['icms_situacao_tributaria'] ?? '');
            $digitos       = preg_replace('/\D/', '', $cstCsosnBruto) ?? '';
            $ehCsosn       = strlen($digitos) === 3;

            $ean = (string) ($item['codigo_barras_comercial'] ?? '');

            return [
                'codigo_barras'   => $ean !== '' ? $ean : null,
                'descricao'       => (string) ($item['descricao'] ?? ''),
                'quantidade'      => (float) ($item['quantidade_comercial'] ?? 0),
                'valor_unitario'  => (float) ($item['valor_unitario_comercial'] ?? 0),
                'ncm'             => ValidadorCamposFiscais::ncm($item['codigo_ncm'] ?? null),
                'cfop'            => ValidadorCamposFiscais::cfop($item['cfop'] ?? null),
                'cest'            => null,
                'unidade'         => ((string) ($item['unidade_comercial'] ?? '')) ?: null,
                'origem'          => ValidadorCamposFiscais::origem(null),
                'cst_csosn'       => $digitos !== '' ? $digitos : null,
                'tributacao_icms' => $ehCsosn
                    ? ClassificacaoIcms::derivar(null, $digitos)
                    : ClassificacaoIcms::derivar($digitos, null),
            ];
        }, $itensJson);

        return [
            'chave_acesso'    => $chave ?: null,
            'numero_nf'       => self::numeroDaChave($chave),
            'serie'           => self::serieDaChave($chave),
            'data_emissao'    => isset($json['data_emissao']) ? substr((string) $json['data_emissao'], 0, 10) : null,
            'fornecedor_nome' => ((string) ($json['nome_emitente'] ?? '')) ?: null,
            'fornecedor_cnpj' => ((string) ($json['documento_emitente'] ?? '')) ?: null,
            'valor_total'     => (float) ($json['valor_total'] ?? 0),
            'itens'           => $itens,
        ];
    }

    // A chave de acesso codifica série (posições 23-25) e número (26-34) —
    // formato fixo do Manual de Orientação do Contribuinte da NF-e, vale
    // pra qualquer provedor, não só a Focus.
    private static function serieDaChave(string $chave): ?string
    {
        if (strlen($chave) !== 44) return null;
        return ltrim(substr($chave, 22, 3), '0') ?: '0';
    }

    private static function numeroDaChave(string $chave): ?string
    {
        if (strlen($chave) !== 44) return null;
        return ltrim(substr($chave, 25, 9), '0') ?: '0';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/FocusNfeRecebidaMapperTest.php`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/FocusNfeRecebidaMapper.php backend/tests/Unit/Fiscal/FocusNfeRecebidaMapperTest.php
git commit -m "feat(fiscal): mapper do JSON de nota recebida da Focus pro shape comum"
```

---

### Task 5: `FocusNfeProvider::consultarNotaRecebida()`

**Files:**
- Modify: `backend/app/Services/Fiscal/Providers/FocusNfeProvider.php`
- Test: `backend/tests/Unit/Fiscal/FocusNfeProviderTest.php`

**Interfaces:**
- Consumes: `FocusNfeRecebidaMapper::paraArray()` (Task 4).
- Produces: `FocusNfeProvider implements ConsultaNotaTerceiroProvider`.

- [ ] **Step 1: Write the failing tests**

Adicionar ao final de `FocusNfeProviderTest`:

```php
    public function test_consultar_nota_recebida_completa_mapeia_via_mapper(): void
    {
        Http::fake([
            '*/nfes_recebidas/CHAVE1.json*' => Http::response([
                'chave_nfe' => 'CHAVE1',
                'nome_emitente' => 'Fornecedor Focus',
                'documento_emitente' => '12345678000199',
                'valor_total' => '99.90',
                'data_emissao' => '2026-09-01T10:00:00-03:00',
                'manifestacao_destinatario' => 'ciencia',
                'requisicao_nota_fiscal' => ['itens' => []],
            ], 200),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->consultarNotaRecebida('CHAVE1');

        $this->assertSame('COMPLETA', $r->status);
        $this->assertSame('Fornecedor Focus', $r->dados['fornecedor_nome']);
    }

    public function test_consultar_nota_recebida_sem_manifestacao_manifesta_e_retorna_aguardando(): void
    {
        Http::fake([
            '*/nfes_recebidas/CHAVE1.json*' => Http::response([
                'chave_nfe' => 'CHAVE1', 'manifestacao_destinatario' => null,
            ], 200),
            '*/nfes_recebidas/CHAVE1/manifesto' => Http::response(['status' => 'evento_registrado'], 200),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->consultarNotaRecebida('CHAVE1');

        $this->assertSame('AGUARDANDO_MANIFESTACAO', $r->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/manifesto') && $req['tipo'] === 'ciencia');
    }

    public function test_consultar_nota_recebida_nao_encontrada(): void
    {
        Http::fake(['*/nfes_recebidas/CHAVE1.json*' => Http::response(['mensagem' => 'não encontrada'], 404)]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->consultarNotaRecebida('CHAVE1');

        $this->assertSame('NAO_ENCONTRADA', $r->status);
    }

    public function test_consultar_nota_recebida_erro_do_provedor(): void
    {
        Http::fake(['*/nfes_recebidas/CHAVE1.json*' => Http::response(['mensagem' => 'Token inválido'], 401)]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $r = $p->consultarNotaRecebida('CHAVE1');

        $this->assertSame('ERRO', $r->status);
        $this->assertStringContainsString('Token inválido', (string) $r->mensagemErro);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/FocusNfeProviderTest.php --filter=consultar_nota_recebida`
Expected: FAIL — `Call to undefined method ... consultarNotaRecebida()`.

- [ ] **Step 3: Implement**

Em `backend/app/Services/Fiscal/Providers/FocusNfeProvider.php`: adicionar `use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;` e `use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;` no topo; mudar a declaração pra `class FocusNfeProvider implements FiscalProvider, ConsultaNotaTerceiroProvider`; adicionar:

```php
    public function consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->get("{$this->baseUrl}/v2/nfes_recebidas/{$chaveAcesso}.json", ['completa' => 1]);

        if ($resp->status() === 404) {
            return ConsultaNotaTerceiroResultado::naoEncontrada();
        }

        if ($resp->failed()) {
            return ConsultaNotaTerceiroResultado::erro($resp->json('mensagem') ?? 'Erro ao consultar nota na Focus.');
        }

        $json = $resp->json();

        if (empty($json['manifestacao_destinatario'])) {
            Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
                ->post("{$this->baseUrl}/v2/nfes_recebidas/{$chaveAcesso}/manifesto", ['tipo' => 'ciencia']);

            return ConsultaNotaTerceiroResultado::aguardandoManifestacao();
        }

        return ConsultaNotaTerceiroResultado::completa(FocusNfeRecebidaMapper::paraArray($json));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/FocusNfeProviderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/FocusNfeProvider.php backend/tests/Unit/Fiscal/FocusNfeProviderTest.php
git commit -m "feat(fiscal): FocusNfeProvider consulta nota de terceiro por chave de acesso"
```

---

### Task 6: `FocusNfeProvider::listarNotasRecebidas()`

**Files:**
- Modify: `backend/app/Services/Fiscal/Providers/FocusNfeProvider.php`
- Test: `backend/tests/Unit/Fiscal/FocusNfeProviderTest.php`

**Interfaces:**
- Produces: `FocusNfeProvider::listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array<ConsultaNotaTerceiroResumo>`.

- [ ] **Step 1: Write the failing test**

```php
    public function test_listar_notas_recebidas_mapeia_a_lista(): void
    {
        Http::fake([
            '*/nfes_recebidas?*' => Http::response([
                ['chave_nfe' => 'CHAVE1', 'nome_emitente' => 'Fornecedor A', 'documento_emitente' => '111', 'valor_total' => '10.00', 'data_emissao' => '2026-09-01T10:00:00-03:00', 'nfe_completa' => true],
                ['chave_nfe' => 'CHAVE2', 'nome_emitente' => 'Fornecedor B', 'documento_emitente' => '222', 'valor_total' => '20.00', 'data_emissao' => '2026-09-02T10:00:00-03:00', 'nfe_completa' => false],
            ], 200),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'HOMOLOGACAO', 'tok');
        $resumos = $p->listarNotasRecebidas('12.345.678/0001-99');

        $this->assertCount(2, $resumos);
        $this->assertSame('CHAVE1', $resumos[0]->chaveAcesso);
        $this->assertTrue($resumos[0]->completa);
        $this->assertFalse($resumos[1]->completa);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'cnpj=12345678000199'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/FocusNfeProviderTest.php --filter=test_listar_notas_recebidas_mapeia_a_lista`
Expected: FAIL — método não existe.

- [ ] **Step 3: Implement**

Adicionar `use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;` no topo do arquivo e o método:

```php
    public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array
    {
        $cnpjLimpo = preg_replace('/\D/', '', $cnpjOficina) ?? '';

        // A Focus não filtra por data — só por "versao" (paginação
        // incremental). $desde fica sem uso aqui por ora; mantido na
        // assinatura pra simetria com a interface e com o SpedyProvider.
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->get("{$this->baseUrl}/v2/nfes_recebidas", ['cnpj' => $cnpjLimpo]);

        if ($resp->failed()) {
            return [];
        }

        return array_map(fn (array $item) => new ConsultaNotaTerceiroResumo(
            chaveAcesso: (string) ($item['chave_nfe'] ?? ''),
            fornecedorNome: $item['nome_emitente'] ?? null,
            fornecedorCnpj: $item['documento_emitente'] ?? null,
            dataEmissao: isset($item['data_emissao']) ? substr((string) $item['data_emissao'], 0, 10) : null,
            valorTotal: (float) ($item['valor_total'] ?? 0),
            completa: ($item['nfe_completa'] ?? false) === true,
        ), $resp->json() ?? []);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php vendor/bin/phpunit tests/Unit/Fiscal/FocusNfeProviderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/FocusNfeProvider.php backend/tests/Unit/Fiscal/FocusNfeProviderTest.php
git commit -m "feat(fiscal): FocusNfeProvider lista notas recebidas por CNPJ"
```

---

### Task 7: Refactor — extrair `EntradaNfController::montarPreview()`

Refactor puro (sem mudança de comportamento), habilitado pela rede de segurança do `EntradaNfTest` já existente. Sem teste novo — a validação é "os testes que já existem continuam passando".

**Files:**
- Modify: `backend/app/Http/Controllers/EntradaNfController.php`

**Interfaces:**
- Produces: `EntradaNfController::montarPreview(array $dados, ProdutoFiscalService $fiscalService): array` — usado pelos Tasks 8 e 9.

- [ ] **Step 1: Extrair o método**

Em `backend/app/Http/Controllers/EntradaNfController.php`, substituir o corpo de `parse()` (o trecho entre calcular `$config`/`$markup`/`$qtyMinimaPadrao` e o `return response()->json([...])` final, ou seja as linhas que hoje ficam entre a chamada de `$parser->parse($conteudo)` e o fim do método) por uma chamada ao novo método privado. O método `parse()` inteiro passa a ser:

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

        return response()->json($this->montarPreview($dados, $fiscalService) + ['xml_original' => $conteudo]);
    }
```

E adicionar, como método privado da mesma classe, exatamente o que antes estava inline em `parse()` (o `array_values(array_filter(array_map(...)))` de itens e a montagem do array de resposta), só que devolvendo o array em vez de um `JsonResponse`, e sem a chave `xml_original` (que cada chamador acrescenta por fora, já que só o upload de XML tem o conteúdo bruto disponível):

```php
    private function montarPreview(array $dados, ProdutoFiscalService $fiscalService): array
    {
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

        return [
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
        ];
    }
```

- [ ] **Step 2: Rodar os testes existentes pra confirmar que nada quebrou**

Isso é um teste Feature (`RefreshDatabase`, precisa de Postgres) — rodar via túnel SSH pro Postgres efêmero (ver `feedback-local-testing` na memória) ou via CI:

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=15432 php artisan test tests/Feature/EntradaNfTest.php`
Expected: PASS — mesmo resultado de antes do refactor (nenhuma mudança de comportamento).

- [ ] **Step 3: Commit**

```bash
git add backend/app/Http/Controllers/EntradaNfController.php
git commit -m "refactor(fiscal): extrai EntradaNfController::montarPreview() de parse(), sem mudanca de comportamento"
```

---

### Task 8: Endpoint `POST /entradas-nf/consultar`

**Files:**
- Modify: `backend/app/Http/Controllers/EntradaNfController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Fiscal/EntradaNfConsultaTest.php`

**Interfaces:**
- Consumes: `FiscalProviderManager::forTenant(): FiscalProvider` (já existe), `ConsultaNotaTerceiroProvider` (Task 1), `EntradaNfController::montarPreview()` (Task 7).
- Produces: rota `POST entradas-nf/consultar`, body `{chave_acesso: string}` → 200 (mesmo shape de `parse()`) / 202 / 404 / 422.

- [ ] **Step 1: Write the failing tests**

Criar `backend/tests/Feature/Fiscal/EntradaNfConsultaTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\Oficina;
use App\Models\Usuario;
use App\Tenancy\TenancyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntradaNfConsultaTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(string $provedor = 'SPEDY'): array
    {
        $oficina = Oficina::create([
            'nome' => 'Oficina Teste', 'slug' => 'oficina-teste',
            'cnpj' => (string) mt_rand(10000000000000, 99999999999999), 'status' => 'ATIVA',
            'provedor_fiscal' => $provedor,
        ]);
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
            'oficina_id' => $oficina->id,
        ]);
        TenancyContext::set($oficina->id, $oficina->slug);
        return [$user->createToken('test')->plainTextToken, $oficina];
    }

    protected function tearDown(): void
    {
        TenancyContext::clear();
        parent::tearDown();
    }

    public function test_consultar_chave_completa_devolve_preview(): void
    {
        [$token, $oficina] = $this->loginAdmin('SPEDY');

        Http::fake([
            '*/inbound-product-invoices?*' => Http::response([
                'items' => [['id' => 'inv-1', 'accessKey' => 'CHAVE-QR-1', 'isComplete' => true]],
            ], 200),
            '*/inbound-product-invoices/inv-1/xml' => Http::response(<<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe><infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
    <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-07-01T09:15:32-03:00</dhEmi></ide>
    <emit><CNPJ>12345678000199</CNPJ><xNome>Fornecedor QR</xNome></emit>
    <det nItem="1"><prod><cEAN>SEM GTIN</cEAN><xProd>ITEM QR</xProd><qCom>1.0000</qCom><vUnCom>10.0000</vUnCom></prod></det>
  </infNFe></NFe>
</nfeProc>
XML, 200),
        ]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => 'CHAVE-QR-1'])
            ->assertStatus(200)
            ->assertJsonPath('fornecedor_nome', 'Fornecedor QR')
            ->assertJsonPath('ja_lancada', false);
    }

    public function test_consultar_chave_aguardando_manifestacao_retorna_202(): void
    {
        [$token, $oficina] = $this->loginAdmin('SPEDY');

        Http::fake([
            '*/inbound-product-invoices?*' => Http::response([
                'items' => [['id' => 'inv-1', 'accessKey' => 'CHAVE-QR-1', 'isComplete' => false]],
            ], 200),
            '*/inbound-product-invoices/inv-1/manifest' => Http::response([], 200),
        ]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => 'CHAVE-QR-1'])
            ->assertStatus(202);
    }

    public function test_consultar_chave_nao_encontrada_retorna_404(): void
    {
        [$token, $oficina] = $this->loginAdmin('SPEDY');

        Http::fake(['*/inbound-product-invoices?*' => Http::response(['items' => []], 200)]);

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => 'CHAVE-INEXISTENTE'])
            ->assertStatus(404);
    }

    public function test_consultar_chave_com_motor_nfephp_retorna_422_sem_chamar_http(): void
    {
        [$token, $oficina] = $this->loginAdmin('NFEPHP');
        // Http::fake() sem stub registrado: qualquer chamada real ficaria
        // faked como 200 vazio (não lança) — por isso a prova de "não
        // chamou o provedor" é o assertNothingSent() explícito abaixo, não
        // a mera presença do fake.
        Http::fake();

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => 'CHAVE-QR-1'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_consultar_chave_ja_lancada_retorna_422_sem_consultar_provedor(): void
    {
        [$token, $oficina] = $this->loginAdmin('SPEDY');
        \App\Models\NotaEntrada::create(['chave_acesso' => 'CHAVE-JA-LANCADA', 'valor_total' => 10]);
        Http::fake();

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->postJson('/api/entradas-nf/consultar', ['chave_acesso' => 'CHAVE-JA-LANCADA'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=15432 php artisan test tests/Feature/Fiscal/EntradaNfConsultaTest.php`
Expected: FAIL — rota `entradas-nf/consultar` não existe (404 em todos).

- [ ] **Step 3: Implementar o endpoint e registrar a rota**

Em `backend/app/Http/Controllers/EntradaNfController.php`, acrescentar os `use` no topo:

```php
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\FiscalProviderManager;
```

E o método público:

```php
    public function consultar(Request $request, FiscalProviderManager $providerManager, ProdutoFiscalService $fiscalService): JsonResponse
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
            'COMPLETA' => response()->json($this->montarPreview($resultado->dados, $fiscalService)),
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

Em `backend/routes/api.php`, no grupo `Route::middleware(['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE'])` que já contém `entradas-nf/parse`/`entradas-nf`/`entradas-nf/atualizar-fiscal` (por volta da linha 255-257), acrescentar logo abaixo de `entradas-nf/atualizar-fiscal`:

```php
    Route::post('entradas-nf/consultar', [EntradaNfController::class, 'consultar']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=15432 php artisan test tests/Feature/Fiscal/EntradaNfConsultaTest.php`
Expected: PASS (5 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/EntradaNfController.php backend/routes/api.php backend/tests/Feature/Fiscal/EntradaNfConsultaTest.php
git commit -m "feat(fiscal): endpoint POST entradas-nf/consultar (leitura de QR/codigo de barras)"
```

---

### Task 9: Endpoint `GET /entradas-nf/recebidas`

**Files:**
- Modify: `backend/app/Http/Controllers/EntradaNfController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Fiscal/EntradaNfConsultaTest.php`

**Interfaces:**
- Consumes: `ConsultaNotaTerceiroProvider::listarNotasRecebidas()`, `Configuracao::first()?->cnpj` (já existe).
- Produces: rota `GET entradas-nf/recebidas` → `{notas: [{chave_acesso, fornecedor_nome, fornecedor_cnpj, data_emissao, valor_total, completa, ja_lancada}]}`.

- [ ] **Step 1: Write the failing tests**

Acrescentar a `EntradaNfConsultaTest`:

```php
    public function test_recebidas_lista_com_ja_lancada_calculado(): void
    {
        [$token, $oficina] = $this->loginAdmin('SPEDY');
        \App\Models\NotaEntrada::create(['chave_acesso' => 'CHAVE1', 'valor_total' => 10]);

        Http::fake([
            '*/inbound-product-invoices' => Http::response([
                'items' => [
                    ['accessKey' => 'CHAVE1', 'isComplete' => true, 'amount' => 10, 'issuedOn' => '2026-09-01T10:00:00', 'issuer' => ['name' => 'Fornecedor A', 'federalTaxNumber' => '111']],
                    ['accessKey' => 'CHAVE2', 'isComplete' => false, 'amount' => 20, 'issuedOn' => '2026-09-02T10:00:00', 'issuer' => ['name' => 'Fornecedor B', 'federalTaxNumber' => '222']],
                ],
            ], 200),
        ]);

        $res = $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson('/api/entradas-nf/recebidas')
            ->assertStatus(200);

        $notas = $res->json('notas');
        $this->assertTrue(collect($notas)->firstWhere('chave_acesso', 'CHAVE1')['ja_lancada']);
        $this->assertFalse(collect($notas)->firstWhere('chave_acesso', 'CHAVE2')['ja_lancada']);
    }

    public function test_recebidas_com_motor_nfephp_retorna_422(): void
    {
        [$token, $oficina] = $this->loginAdmin('NFEPHP');

        $this->withToken($token)->withHeaders(['X-Tenant' => $oficina->slug])
            ->getJson('/api/entradas-nf/recebidas')
            ->assertStatus(422);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=15432 php artisan test tests/Feature/Fiscal/EntradaNfConsultaTest.php --filter=test_recebidas`
Expected: FAIL — rota não existe.

- [ ] **Step 3: Implementar**

Adicionar `use App\Models\Configuracao;` se ainda não presente (já está, confirmado no arquivo original), `use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;`, e o método:

```php
    public function recebidas(FiscalProviderManager $providerManager): JsonResponse
    {
        $provider = $providerManager->forTenant();
        if (!$provider instanceof ConsultaNotaTerceiroProvider) {
            return response()->json(['message' => 'O motor fiscal desta oficina ainda não suporta consultar notas recebidas.'], 422);
        }

        $cnpjOficina      = (string) (Configuracao::first()?->cnpj ?? '');
        $chavesJaLancadas = NotaEntrada::whereNotNull('chave_acesso')->pluck('chave_acesso')->all();

        $resumos = array_map(fn (ConsultaNotaTerceiroResumo $r) => [
            'chave_acesso'    => $r->chaveAcesso,
            'fornecedor_nome' => $r->fornecedorNome,
            'fornecedor_cnpj' => $r->fornecedorCnpj,
            'data_emissao'    => $r->dataEmissao,
            'valor_total'     => $r->valorTotal,
            'completa'        => $r->completa,
            'ja_lancada'      => in_array($r->chaveAcesso, $chavesJaLancadas, true),
        ], $provider->listarNotasRecebidas($cnpjOficina));

        return response()->json(['notas' => $resumos]);
    }
```

Em `backend/routes/api.php`, no grupo `Route::middleware(['tenant', 'auth:sanctum'])` que já contém `entradas-nf`/`entradas-nf/{id}` (leitura, por volta da linha 243-244), acrescentar:

```php
    Route::get('entradas-nf/recebidas', [EntradaNfController::class, 'recebidas']);
```

**Atenção de ordem de rota:** essa linha precisa vir ANTES de `Route::get('entradas-nf/{id}', ...)` no arquivo (ou o Laravel vai tentar casar `recebidas` como `{id}` primeiro). Registrar logo acima da linha existente `Route::get('entradas-nf/{id}', [EntradaNfController::class, 'show']);`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=15432 php artisan test tests/Feature/Fiscal/EntradaNfConsultaTest.php`
Expected: PASS (todos os 7 testes do arquivo).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/EntradaNfController.php backend/routes/api.php backend/tests/Feature/Fiscal/EntradaNfConsultaTest.php
git commit -m "feat(fiscal): endpoint GET entradas-nf/recebidas (notas emitidas contra o CNPJ da oficina)"
```

---

### Task 10: Frontend — tipos e digitação manual da chave (sem câmera ainda)

**Files:**
- Modify: `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`

**Interfaces:**
- Produces: `consultarChave(chave: string): Promise<void>` — usado pelo Task 11 (câmera) e Task 12 (Notas Recebidas).

- [ ] **Step 1: Ajustar o tipo `NotaPreview` e acrescentar estado/função novos**

Em `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`, mudar o campo `xml_original` da interface `NotaPreview` (linha 43) de `string` pra `string | null` (a consulta por chave não tem XML bruto de todo provedor — a Focus, por exemplo, nunca tem):

```ts
  xml_original: string | null
```

E em `handleConfirmar()`, o campo `xml_original: preview.xml_original` no corpo do POST já funciona sem mudança (o backend já aceita `null`).

Adicionar, junto aos `useState` já existentes:

```ts
  const [modo, setModo] = useState<'upload' | 'scan'>('upload')
  const [chaveDigitada, setChaveDigitada] = useState('')
  const [consultando, setConsultando] = useState(false)
```

E a função, logo depois de `handleUpload`:

```ts
  async function consultarChave(chave: string) {
    const chaveLimpa = chave.replace(/\D/g, '')
    if (chaveLimpa.length !== 44) {
      toast('A chave de acesso precisa ter 44 dígitos.', 'danger')
      return
    }
    setConsultando(true)
    try {
      const res = await api.post<NotaPreview | { message: string }>('/entradas-nf/consultar', { chave_acesso: chaveLimpa })
      if (res.status === 202) {
        toast((res.data as { message: string }).message, 'success')
        return
      }
      const nota = res.data as NotaPreview
      setPreview(nota)
      setItens(nota.itens)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao consultar a nota.', 'danger')
    } finally {
      setConsultando(false)
    }
  }
```

- [ ] **Step 2: Adicionar o seletor de modo e o formulário de digitação manual**

Substituir o bloco `{!preview && (...)}` (linhas 171-185 do arquivo original) por:

```tsx
      {!preview && (
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32 }}>
          <div style={{ display: 'flex', gap: 8, marginBottom: 24, justifyContent: 'center' }}>
            {([['upload', 'Upload de XML'], ['scan', 'Ler QR / código de barras']] as const).map(([m, label]) => (
              <button key={m} type="button" onClick={() => setModo(m)}
                style={{
                  padding: '8px 16px', borderRadius: 8, cursor: 'pointer', fontSize: 13, fontWeight: 600,
                  border: '1px solid var(--border)',
                  background: modo === m ? 'var(--accent)' : 'transparent',
                  color: modo === m ? '#000' : 'var(--muted)',
                }}>
                {label}
              </button>
            ))}
          </div>

          {modo === 'upload' && (
            <div style={{ textAlign: 'center' }}>
              <p style={{ color: 'var(--muted)', marginBottom: 16 }}>
                Selecione o arquivo XML da NF-e enviado pelo fornecedor.
              </p>
              <input type="file" accept=".xml" disabled={uploading}
                onChange={e => { const f = e.target.files?.[0]; if (f) handleUpload(f) }}
                style={{ color: 'var(--text)' }} />
              {uploading && <p style={{ color: 'var(--muted)', marginTop: 12 }}>Lendo XML...</p>}
            </div>
          )}

          {modo === 'scan' && (
            <div style={{ textAlign: 'center' }}>
              <p style={{ color: 'var(--muted)', marginBottom: 16 }}>
                Digite a chave de acesso de 44 dígitos impressa na nota.
              </p>
              <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
                <input value={chaveDigitada} maxLength={44}
                  onChange={e => setChaveDigitada(e.target.value.replace(/\D/g, ''))}
                  placeholder="Chave de acesso (44 dígitos)"
                  style={{ ...inputStyle, width: 320 }} />
                <button type="button" disabled={consultando || chaveDigitada.length !== 44}
                  onClick={() => consultarChave(chaveDigitada)}
                  style={{
                    padding: '8px 20px', borderRadius: 8, border: 'none', fontWeight: 700,
                    background: chaveDigitada.length === 44 ? 'var(--accent)' : 'var(--muted)', color: '#000',
                    cursor: chaveDigitada.length === 44 ? 'pointer' : 'not-allowed',
                  }}>
                  {consultando ? 'Consultando...' : 'Consultar'}
                </button>
              </div>
            </div>
          )}
        </div>
      )}
```

- [ ] **Step 3: Verificar**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros novos.

Manual: `npm run dev`, abrir `/produtos/entrada-nf`, alternar entre os dois modos, digitar uma chave de 44 dígitos qualquer e clicar Consultar — deve dar erro 404 (nota inexistente) com toast, já que não há nota real no ambiente local. Confirma que a chamada chega no backend.

- [ ] **Step 4: Commit**

```bash
git add frontend/app/\(dashboard\)/produtos/entrada-nf/page.tsx
git commit -m "feat(entrada-nf): digitacao manual da chave de acesso pra consulta ao provedor"
```

---

### Task 11: Frontend — leitura por câmera (QR Code + código de barras)

**Files:**
- Modify: `frontend/package.json` (via `npm install`)
- Modify: `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`

**Interfaces:**
- Consumes: `consultarChave(chave: string)` (Task 10).

- [ ] **Step 1: Instalar as dependências**

Run: `cd frontend && npm install @zxing/browser @zxing/library`

- [ ] **Step 2: Adicionar o scanner de câmera no modo "scan"**

No topo de `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`, acrescentar aos imports:

```ts
import { useEffect, useRef, useState } from 'react'
import { BrowserMultiFormatReader } from '@zxing/browser'
import { BarcodeFormat, DecodeHintType } from '@zxing/library'
```

(troca a linha `import { useState } from 'react'` original por essa, incluindo `useEffect`/`useRef`.)

Dentro do componente, junto aos estados já existentes:

```ts
  const videoRef = useRef<HTMLVideoElement>(null)
  const controlsRef = useRef<{ stop: () => void } | null>(null)
  const [cameraAtiva, setCameraAtiva] = useState(false)
```

Função de iniciar a câmera, logo depois de `consultarChave`:

```ts
  async function iniciarCamera() {
    if (!videoRef.current) return
    setCameraAtiva(true)

    const hints = new Map()
    hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.QR_CODE, BarcodeFormat.CODE_128])
    hints.set(DecodeHintType.TRY_HARDER, true)
    const reader = new BrowserMultiFormatReader(hints)

    try {
      const controls = await reader.decodeFromVideoDevice(undefined, videoRef.current, (result) => {
        if (!result) return
        const texto = result.getText()
        const match = texto.match(/\d{44}/)
        controlsRef.current?.stop()
        setCameraAtiva(false)
        if (match) {
          consultarChave(match[0])
        } else {
          toast('Não encontrei uma chave de acesso de 44 dígitos nesse código.', 'danger')
        }
      })
      controlsRef.current = controls
    } catch {
      toast('Não foi possível acessar a câmera. Digite a chave manualmente.', 'danger')
      setCameraAtiva(false)
    }
  }

  useEffect(() => {
    return () => { controlsRef.current?.stop() }
  }, [])
```

No JSX do modo `scan` (dentro do bloco criado no Task 10), acrescentar ANTES do texto "Digite a chave de acesso...":

```tsx
              {!cameraAtiva && (
                <button type="button" onClick={iniciarCamera}
                  style={{ marginBottom: 16, padding: '8px 20px', borderRadius: 8, border: '1px solid var(--border)', background: 'transparent', color: 'var(--text)', cursor: 'pointer' }}>
                  📷 Abrir câmera
                </button>
              )}
              <video ref={videoRef} style={{ display: cameraAtiva ? 'block' : 'none', width: '100%', maxWidth: 480, margin: '0 auto 16px', borderRadius: 8 }} />
```

- [ ] **Step 3: Verificar**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros novos.

Manual (precisa de HTTPS ou `localhost` — câmera do navegador exige contexto seguro): `npm run dev`, abrir `/produtos/entrada-nf` em `localhost`, ir no modo "Ler QR / código de barras", clicar "Abrir câmera", autorizar o navegador, apontar pra um QR Code de teste contendo uma sequência de 44 dígitos — confirmar que `consultarChave` é chamado e a câmera para sozinha.

- [ ] **Step 4: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/app/\(dashboard\)/produtos/entrada-nf/page.tsx
git commit -m "feat(entrada-nf): leitura de QR Code e codigo de barras via camera (@zxing/browser)"
```

---

### Task 12: Frontend — aba "Notas Recebidas"

**Files:**
- Modify: `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`

**Interfaces:**
- Consumes: `GET /entradas-nf/recebidas` (Task 9), `consultarChave(chave: string)` (Task 10).

- [ ] **Step 1: Ampliar o tipo de `modo` e adicionar o componente da aba**

Mudar `useState<'upload' | 'scan'>('upload')` (Task 10) pra `useState<'upload' | 'scan' | 'recebidas'>('upload')`, e a lista do seletor de modo pra incluir a 3ª opção:

```ts
{([['upload', 'Upload de XML'], ['scan', 'Ler QR / código de barras'], ['recebidas', 'Notas Recebidas']] as const).map(([m, label]) => (
```

Adicionar, fora do componente `EntradaNfPage` (no mesmo arquivo, depois dele), a interface e o componente da aba:

```tsx
interface NotaRecebidaResumo {
  chave_acesso: string
  fornecedor_nome: string | null
  fornecedor_cnpj: string | null
  data_emissao: string | null
  valor_total: number
  completa: boolean
  ja_lancada: boolean
}

function NotasRecebidasTab({ onImportar }: { onImportar: (chave: string) => void }) {
  const [notas, setNotas] = useState<NotaRecebidaResumo[]>([])
  const [carregando, setCarregando] = useState(true)
  const [erro, setErro] = useState<string | null>(null)

  useEffect(() => {
    let ativo = true
    api.get<{ notas: NotaRecebidaResumo[] }>('/entradas-nf/recebidas')
      .then(res => { if (ativo) setNotas(res.data.notas) })
      .catch((err: unknown) => {
        const e = err as { response?: { data?: { message?: string } } }
        if (ativo) setErro(e.response?.data?.message ?? 'Erro ao listar notas recebidas.')
      })
      .finally(() => { if (ativo) setCarregando(false) })
    return () => { ativo = false }
  }, [])

  if (carregando) return <p style={{ color: 'var(--muted)' }}>Carregando notas recebidas...</p>
  if (erro) return <p style={{ color: 'var(--danger)' }}>{erro}</p>
  if (notas.length === 0) return <p style={{ color: 'var(--muted)' }}>Nenhuma nota pendente encontrada no provedor fiscal.</p>

  return (
    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
      <thead>
        <tr>
          {['Fornecedor', 'Emissão', 'Valor', 'Status', ''].map(h => (
            <th key={h} style={{ padding: '8px 12px', textAlign: 'left', fontSize: 12, color: 'var(--muted)', borderBottom: '1px solid var(--border)' }}>{h}</th>
          ))}
        </tr>
      </thead>
      <tbody>
        {notas.map(n => (
          <tr key={n.chave_acesso}>
            <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>{n.fornecedor_nome ?? '-'}</td>
            <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>{n.data_emissao ?? '-'}</td>
            <td className="font-mono" style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>{formatarMoeda(n.valor_total)}</td>
            <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
              {n.ja_lancada ? 'Já lançada' : n.completa ? 'Pronta pra importar' : 'Aguardando manifestação'}
            </td>
            <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
              <button type="button" disabled={n.ja_lancada} onClick={() => onImportar(n.chave_acesso)}
                style={{
                  padding: '4px 12px', borderRadius: 6, border: 'none', fontWeight: 700, fontSize: 12,
                  background: n.ja_lancada ? 'var(--muted)' : 'var(--accent)', color: '#000',
                  cursor: n.ja_lancada ? 'not-allowed' : 'pointer',
                }}>
                Importar
              </button>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
```

E no JSX de `EntradaNfPage`, logo depois do bloco `{modo === 'scan' && (...)}`:

```tsx
          {modo === 'recebidas' && <NotasRecebidasTab onImportar={consultarChave} />}
```

- [ ] **Step 2: Verificar**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros novos.

Manual: `npm run dev`, abrir `/produtos/entrada-nf`, ir na aba "Notas Recebidas" — deve mostrar "Nenhuma nota pendente..." ou um erro claro se a oficina de teste não tiver provedor fiscal configurado (comportamento esperado, sem crash de tela).

- [ ] **Step 3: Commit**

```bash
git add frontend/app/\(dashboard\)/produtos/entrada-nf/page.tsx
git commit -m "feat(entrada-nf): aba Notas Recebidas listando notas emitidas contra o CNPJ da oficina"
```

---

### Task 13: Atualizar `PROGRESSO.md` e memória

**Files:**
- Modify: `PROGRESSO.md`
- Modify (memória, fora do git): `project-roadmap-fiscal-3-etapas.md` (ou novo arquivo de memória dedicado, se fizer mais sentido)

- [ ] **Step 1: Registrar a rodada em `PROGRESSO.md`**

Nova seção descrevendo: o que foi implementado (consulta por QR/código de barras + listagem por CNPJ, Spedy e Focus), decisões não óbvias (NFePHP fora de escopo; manifestação automática só "ciência"; Focus não expõe `origem` no JSON de consulta; `serie`/`numero_nf` derivados da própria chave de acesso quando o provedor não os fornece), e o que fica pendente de validação em produção (testar com uma nota real assim que a stuntmotos tiver o motor Spedy/Focus com "notas recebidas" habilitado).

- [ ] **Step 2: Commit**

```bash
git add PROGRESSO.md
git commit -m "docs: registra entrada de NF via consulta ao provedor (QR/codigo de barras + notas recebidas)"
git push origin main
```
