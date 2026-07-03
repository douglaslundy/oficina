# Entrada de Nota Fiscal no Estoque (Fase 1 — XML) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Uma página que lê o XML de uma NF-e de compra do fornecedor, casa os itens com produtos já
cadastrados por código de barras, deixa o usuário revisar/editar tudo numa tela de conferência, e ao
confirmar cria os produtos novos e alimenta o estoque dos existentes em uma única transação.

**Architecture:** Backend Laravel: parser XML puro (sem dependência nova) → endpoint `parse` stateless
que devolve um preview → endpoint `store` transacional que grava nota + itens + produtos + movimentações
de estoque, reaproveitando o `EstoqueService` já existente. Frontend Next.js: uma página com upload →
tabela de conferência editável → confirmar.

**Tech Stack:** Laravel 11 (PHP 8.3, `declare(strict_types=1)`), PostgreSQL, `SimpleXMLElement` (nativo,
sem lib nova), Next.js 14 App Router, TypeScript, react-hook-form não é necessário aqui (tabela dinâmica,
não formulário schema-driven).

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo.
- TypeScript strict — sem `any` explícito.
- Toda mutation de estoque é transacional (`DB::transaction`), seguindo o padrão já usado em
  `EstoqueService`.
- Sem dados fake no código — sem seeders/fixtures fora de teste.
- **Ambiente local (Windows, sem Docker/Postgres):** testes de **Feature** (`RefreshDatabase`) só rodam
  em ambiente com banco (VPS/CI) — não dá para confirmá-los localmente. Testes **Unit** puros (sem tocar
  banco) rodam local: `cd backend && php vendor/bin/phpunit tests/Unit`. Verificação local possível sem
  banco: `php -l <arquivo>` (lint de sintaxe) em cada arquivo PHP novo/alterado. Frontend: não há test
  runner configurado — verificar com `npx tsc --noEmit` e `npx eslint <arquivo>`.
- Cada task backend com Feature test: rodar `php -l` no arquivo pra confirmar sintaxe, deixar o teste
  escrito e marcá-lo como "a confirmar em ambiente com banco" — não é possível declarar "testes passam"
  localmente para esses casos.
- Convenção de migrations: uma classe anônima `return new class extends Migration`, timestamp no nome
  do arquivo `YYYY_MM_DD_NNNNNN_descricao.php`. Próximo timestamp livre nesta feature: `2026_07_03`.
- Modelos: UUID primary key não-incremental (`public $incrementing = false; protected $keyType = 'string';`),
  `public $timestamps = false` (usam `criado_em` manual), `oficina_id` preenchido automaticamente pela
  trait `App\Tenancy\HasTenantScope` (não setar manualmente em `create()`).

---

### Task 1: Migrations — schema novo

**Files:**
- Create: `backend/database/migrations/2026_07_03_000001_add_codigo_barras_to_produtos_table.php`
- Create: `backend/database/migrations/2026_07_03_000002_create_notas_entrada_table.php`
- Create: `backend/database/migrations/2026_07_03_000003_create_notas_entrada_itens_table.php`
- Create: `backend/database/migrations/2026_07_03_000004_add_nota_entrada_id_to_movimentacoes_estoque_table.php`
- Create: `backend/database/migrations/2026_07_03_000005_add_entrada_nf_config_to_configuracoes_table.php`

**Interfaces:**
- Produces: colunas `produtos.codigo_barras` (string, nullable), tabelas `notas_entrada` e
  `notas_entrada_itens`, coluna `movimentacoes_estoque.nota_entrada_id` (nullable FK), colunas
  `configuracoes.markup_padrao_entrada_nf` (decimal, default 40.00) e
  `configuracoes.atualizar_custo_entrada_nf` (boolean, default true). Todas as tasks seguintes dependem
  deste schema existir.

- [ ] **Step 1: Criar migration de `codigo_barras` em produtos**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->string('codigo_barras', 20)->nullable()->after('sku');
            $table->index(['oficina_id', 'codigo_barras']);
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropIndex(['oficina_id', 'codigo_barras']);
            $table->dropColumn('codigo_barras');
        });
    }
};
```

Salvar em `backend/database/migrations/2026_07_03_000001_add_codigo_barras_to_produtos_table.php`.

- [ ] **Step 2: Criar migration da tabela `notas_entrada`**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_entrada', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->string('numero_nf', 20)->nullable();
            $table->string('serie', 5)->nullable();
            $table->string('chave_acesso', 44)->nullable();
            $table->string('fornecedor_nome', 150)->nullable();
            $table->string('fornecedor_cnpj', 18)->nullable();
            $table->decimal('valor_total', 10, 2)->default(0);
            $table->date('data_emissao')->nullable();
            $table->text('xml_original')->nullable();
            $table->uuid('usuario_id')->nullable();
            $table->timestampTz('criado_em')->useCurrent();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('usuarios')->onDelete('set null');
            // NULL é tratado como distinto pelo Postgres — várias notas sem chave não colidem entre si.
            $table->unique(['oficina_id', 'chave_acesso']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_entrada');
    }
};
```

Salvar em `backend/database/migrations/2026_07_03_000002_create_notas_entrada_table.php`.

- [ ] **Step 3: Criar migration da tabela `notas_entrada_itens`**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_entrada_itens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('nota_entrada_id');
            $table->uuid('produto_id');
            $table->string('codigo_barras_xml', 20)->nullable();
            $table->string('descricao_xml', 200)->nullable();
            $table->decimal('quantidade', 10, 2)->default(0);
            $table->decimal('valor_unitario', 10, 2)->default(0);
            $table->boolean('produto_criado')->default(false);

            $table->foreign('nota_entrada_id')->references('id')->on('notas_entrada')->onDelete('cascade');
            $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_entrada_itens');
    }
};
```

Salvar em `backend/database/migrations/2026_07_03_000003_create_notas_entrada_itens_table.php`.

- [ ] **Step 4: Criar migration de `nota_entrada_id` em `movimentacoes_estoque`**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->uuid('nota_entrada_id')->nullable()->after('os_id');
            $table->foreign('nota_entrada_id')->references('id')->on('notas_entrada')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->dropForeign(['nota_entrada_id']);
            $table->dropColumn('nota_entrada_id');
        });
    }
};
```

Salvar em `backend/database/migrations/2026_07_03_000004_add_nota_entrada_id_to_movimentacoes_estoque_table.php`.

- [ ] **Step 5: Criar migration dos campos novos em `configuracoes`**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->decimal('markup_padrao_entrada_nf', 5, 2)->default(40.00);
            $table->boolean('atualizar_custo_entrada_nf')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn(['markup_padrao_entrada_nf', 'atualizar_custo_entrada_nf']);
        });
    }
};
```

Salvar em `backend/database/migrations/2026_07_03_000005_add_entrada_nf_config_to_configuracoes_table.php`.

- [ ] **Step 6: Verificar sintaxe (sem banco local)**

Rodar para cada um dos 5 arquivos:
```bash
cd backend && php -l database/migrations/2026_07_03_000001_add_codigo_barras_to_produtos_table.php
php -l database/migrations/2026_07_03_000002_create_notas_entrada_table.php
php -l database/migrations/2026_07_03_000003_create_notas_entrada_itens_table.php
php -l database/migrations/2026_07_03_000004_add_nota_entrada_id_to_movimentacoes_estoque_table.php
php -l database/migrations/2026_07_03_000005_add_entrada_nf_config_to_configuracoes_table.php
```
Esperado: `No syntax errors detected` nos 5. A aplicação real (`migrate`) só acontece em ambiente com
Postgres (VPS, no deploy — `docker-entrypoint.sh` já roda `migrate --force` sozinho).

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_07_03_*.php
git commit -m "feat(estoque): schema para entrada de NF por XML"
```

---

### Task 2: Modelos — `NotaEntrada`, `NotaEntradaItem` e fillable novos

**Files:**
- Create: `backend/app/Models/NotaEntrada.php`
- Create: `backend/app/Models/NotaEntradaItem.php`
- Modify: `backend/app/Models/Produto.php:24-27` (fillable — adicionar `codigo_barras`)
- Modify: `backend/app/Models/MovimentacaoEstoque.php:20` (fillable — adicionar `nota_entrada_id`)

Nota: `Configuracao.php` (fillable/casts para `markup_padrao_entrada_nf`/`atualizar_custo_entrada_nf`)
é responsabilidade do Task 3, não deste — referência removida daqui para evitar duplicidade.

**Interfaces:**
- Consumes: tabelas do Task 1.
- Produces: `App\Models\NotaEntrada` (fillable: `numero_nf, serie, chave_acesso, fornecedor_nome,
  fornecedor_cnpj, valor_total, data_emissao, xml_original, usuario_id, oficina_id`; relação
  `itens(): HasMany` → `NotaEntradaItem`). `App\Models\NotaEntradaItem` (fillable: `nota_entrada_id,
  produto_id, codigo_barras_xml, descricao_xml, quantidade, valor_unitario, produto_criado`). Tasks
  seguintes (Service, Controller) dependem desses fillables exatos.

- [ ] **Step 1: Criar o model `NotaEntrada`**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class NotaEntrada extends Model
{
    use HasTenantScope;

    protected $table = 'notas_entrada';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'numero_nf', 'serie', 'chave_acesso', 'fornecedor_nome', 'fornecedor_cnpj',
        'valor_total', 'data_emissao', 'xml_original', 'usuario_id', 'oficina_id',
    ];

    protected $casts = [
        'criado_em'    => 'datetime',
        'data_emissao' => 'date',
        'valor_total'  => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function itens(): HasMany
    {
        return $this->hasMany(NotaEntradaItem::class, 'nota_entrada_id');
    }
}
```

Salvar em `backend/app/Models/NotaEntrada.php`.

- [ ] **Step 2: Criar o model `NotaEntradaItem`**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotaEntradaItem extends Model
{
    protected $table = 'notas_entrada_itens';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nota_entrada_id', 'produto_id', 'codigo_barras_xml', 'descricao_xml',
        'quantidade', 'valor_unitario', 'produto_criado',
    ];

    protected $casts = [
        'quantidade'     => 'float',
        'valor_unitario' => 'float',
        'produto_criado' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function notaEntrada(): BelongsTo
    {
        return $this->belongsTo(NotaEntrada::class, 'nota_entrada_id');
    }
}
```

Salvar em `backend/app/Models/NotaEntradaItem.php`. Sem `HasTenantScope` aqui — o tenant já é aplicado
via `nota_entrada_id` (o pai já é escopado), mesmo padrão de `OsItem`.

- [ ] **Step 3: Adicionar `codigo_barras` ao fillable de `Produto`**

Em `backend/app/Models/Produto.php`, trocar:
```php
    protected $fillable = [
        'nome', 'sku', 'categoria', 'unidade',
        'qty_atual', 'qty_minima', 'preco_custo', 'preco_venda', 'ativo', 'oficina_id',
    ];
```
por:
```php
    protected $fillable = [
        'nome', 'sku', 'codigo_barras', 'categoria', 'unidade',
        'qty_atual', 'qty_minima', 'preco_custo', 'preco_venda', 'ativo', 'oficina_id',
    ];
```

- [ ] **Step 4: Adicionar `nota_entrada_id` ao fillable de `MovimentacaoEstoque`**

Em `backend/app/Models/MovimentacaoEstoque.php:20`, trocar:
```php
    protected $fillable = ['produto_id', 'tipo', 'quantidade', 'motivo', 'os_id', 'usuario_id', 'oficina_id'];
```
por:
```php
    protected $fillable = ['produto_id', 'tipo', 'quantidade', 'motivo', 'os_id', 'nota_entrada_id', 'usuario_id', 'oficina_id'];
```

- [ ] **Step 5: Verificar sintaxe**

```bash
cd backend && php -l app/Models/NotaEntrada.php && php -l app/Models/NotaEntradaItem.php && php -l app/Models/Produto.php && php -l app/Models/MovimentacaoEstoque.php
```
Esperado: `No syntax errors detected` nos 4 arquivos.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Models/NotaEntrada.php backend/app/Models/NotaEntradaItem.php backend/app/Models/Produto.php backend/app/Models/MovimentacaoEstoque.php
git commit -m "feat(estoque): modelos NotaEntrada/NotaEntradaItem e fillable novos"
```

---

### Task 3: Configurações — markup padrão e toggle de atualização de custo

**Files:**
- Modify: `backend/app/Models/Configuracao.php:20-28`
- Modify: `backend/app/Http/Controllers/ConfiguracaoController.php:22-46`
- Test: `backend/tests/Feature/ConfiguracaoEntradaNfTest.php`

**Interfaces:**
- Consumes: colunas de `configuracoes` do Task 1.
- Produces: `Configuracao->markup_padrao_entrada_nf` (float), `Configuracao->atualizar_custo_entrada_nf`
  (bool) — usados no Task 6 (parse, pro cálculo de preço sugerido) e Task 7 (store, pra decidir se
  atualiza `preco_custo`).

- [ ] **Step 1: Escrever o teste (falha primeiro — endpoint ainda não aceita os campos)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConfiguracaoEntradaNfTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    public function test_atualiza_markup_e_toggle_de_custo(): void
    {
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->putJson('/api/configuracoes', [
            'markup_padrao_entrada_nf'   => 55.5,
            'atualizar_custo_entrada_nf' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('configuracoes', [
            'markup_padrao_entrada_nf'   => 55.5,
            'atualizar_custo_entrada_nf' => false,
        ]);
    }

    public function test_show_retorna_valores_padrao(): void
    {
        Configuracao::create([]);
        $token = $this->loginAdmin();

        $response = $this->withToken($token)->getJson('/api/configuracoes');

        $response->assertStatus(200);
        $this->assertSame(40.0, (float) $response->json('markup_padrao_entrada_nf'));
        $this->assertTrue($response->json('atualizar_custo_entrada_nf'));
    }
}
```

Salvar em `backend/tests/Feature/ConfiguracaoEntradaNfTest.php`. **Não é possível rodar este teste
localmente** (precisa de Postgres) — deixá-lo pronto para rodar em ambiente com banco (VPS/CI).

- [ ] **Step 2: Adicionar os campos ao fillable/casts de `Configuracao`**

Em `backend/app/Models/Configuracao.php`, trocar:
```php
    protected $fillable = [
        'razao_social', 'nome_fantasia', 'cnpj', 'inscricao_estadual',
        'inscricao_municipal', 'regime_tributario', 'cep', 'endereco',
        'cidade', 'uf', 'telefone', 'email', 'ambiente_fiscal', 'serie_nf',
        'proximo_numero_nf', 'aliquota_iss', 'cnae', 'codigo_ibge',
        'estoque_limite_padrao', 'alertas_email', 'email_alertas', 'certificado_pfx_encrypted',
        'oficina_id',
        'certificado_senha_encrypted', 'certificado_validade', 'certificado_nome', 'certificado_status',
    ];

    protected $casts = ['alertas_email' => 'boolean'];
```
por:
```php
    protected $fillable = [
        'razao_social', 'nome_fantasia', 'cnpj', 'inscricao_estadual',
        'inscricao_municipal', 'regime_tributario', 'cep', 'endereco',
        'cidade', 'uf', 'telefone', 'email', 'ambiente_fiscal', 'serie_nf',
        'proximo_numero_nf', 'aliquota_iss', 'cnae', 'codigo_ibge',
        'estoque_limite_padrao', 'alertas_email', 'email_alertas', 'certificado_pfx_encrypted',
        'oficina_id', 'markup_padrao_entrada_nf', 'atualizar_custo_entrada_nf',
        'certificado_senha_encrypted', 'certificado_validade', 'certificado_nome', 'certificado_status',
    ];

    protected $casts = [
        'alertas_email'              => 'boolean',
        'atualizar_custo_entrada_nf' => 'boolean',
        'markup_padrao_entrada_nf'   => 'float',
    ];
```

- [ ] **Step 3: Adicionar validação em `ConfiguracaoController::update`**

Em `backend/app/Http/Controllers/ConfiguracaoController.php:24-46`, dentro do array de `$request->validate([...])`,
depois da linha `'certificado_base64'    => ['nullable', 'string'],`, adicionar:
```php
            'markup_padrao_entrada_nf'   => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'atualizar_custo_entrada_nf' => ['nullable', 'boolean'],
```

- [ ] **Step 4: Verificar sintaxe**

```bash
cd backend && php -l app/Models/Configuracao.php && php -l app/Http/Controllers/ConfiguracaoController.php && php -l tests/Feature/ConfiguracaoEntradaNfTest.php
```
Esperado: `No syntax errors detected` nos 3.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Models/Configuracao.php backend/app/Http/Controllers/ConfiguracaoController.php backend/tests/Feature/ConfiguracaoEntradaNfTest.php
git commit -m "feat(configuracoes): markup padrao e toggle de atualizacao de custo na entrada de NF"
```

---

### Task 4: `NotaEntradaXmlParser` — parser de XML puro (TDD, roda local sem banco)

**Files:**
- Create: `backend/app/Services/NotaEntradaXmlParser.php`
- Test: `backend/tests/Unit/NotaEntradaXmlParserTest.php`

**Interfaces:**
- Produces: `App\Services\NotaEntradaXmlParser::parse(string $xmlContent): array` com o shape:
  `['chave_acesso' => ?string, 'numero_nf' => ?string, 'serie' => ?string, 'data_emissao' => ?string
  (Y-m-d), 'fornecedor_nome' => ?string, 'fornecedor_cnpj' => ?string, 'valor_total' => float,
  'itens' => list<['codigo_barras' => ?string, 'descricao' => string, 'quantidade' => float,
  'valor_unitario' => float]>]`. Lança `\InvalidArgumentException` se o XML for inválido ou não tiver o
  nó `infNFe`. Task 6 (`EntradaNfController::parse`) consome exatamente este contrato.

- [ ] **Step 1: Escrever o teste (falha primeiro — classe ainda não existe)**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NotaEntradaXmlParser;
use PHPUnit\Framework\TestCase;

class NotaEntradaXmlParserTest extends TestCase
{
    private function xmlValido(): string
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
        </prod>
      </det>
      <det nItem="2">
        <prod>
          <cProd>FORN-002</cProd>
          <cEAN>SEM GTIN</cEAN>
          <xProd>PASTILHA DE FREIO GENERICA</xProd>
          <qCom>4.0000</qCom>
          <vUnCom>42.0000</vUnCom>
        </prod>
      </det>
      <total>
        <ICMSTot>
          <vNF>323.00</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }

    public function test_extrai_dados_da_nota(): void
    {
        $parser    = new NotaEntradaXmlParser();
        $resultado = $parser->parse($this->xmlValido());

        $this->assertSame('35260712345678000199550010000012340000000001', $resultado['chave_acesso']);
        $this->assertSame('1234', $resultado['numero_nf']);
        $this->assertSame('1', $resultado['serie']);
        $this->assertSame('2026-07-01', $resultado['data_emissao']);
        $this->assertSame('Auto Pecas Distribuidora LTDA', $resultado['fornecedor_nome']);
        $this->assertSame('12345678000199', $resultado['fornecedor_cnpj']);
        $this->assertSame(323.00, $resultado['valor_total']);
        $this->assertCount(2, $resultado['itens']);
    }

    public function test_item_com_codigo_de_barras(): void
    {
        $parser    = new NotaEntradaXmlParser();
        $resultado = $parser->parse($this->xmlValido());
        $item      = $resultado['itens'][0];

        $this->assertSame('7891234567890', $item['codigo_barras']);
        $this->assertSame('FILTRO DE OLEO XPTO', $item['descricao']);
        $this->assertSame(10.0, $item['quantidade']);
        $this->assertSame(15.5, $item['valor_unitario']);
    }

    public function test_item_sem_gtin_vira_codigo_de_barras_nulo(): void
    {
        $parser    = new NotaEntradaXmlParser();
        $resultado = $parser->parse($this->xmlValido());
        $item      = $resultado['itens'][1];

        $this->assertNull($item['codigo_barras']);
        $this->assertSame('PASTILHA DE FREIO GENERICA', $item['descricao']);
    }

    public function test_xml_sem_infnfe_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $parser = new NotaEntradaXmlParser();
        $parser->parse('<xml><foo>bar</foo></xml>');
    }

    public function test_xml_malformado_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $parser = new NotaEntradaXmlParser();
        $parser->parse('<not-valid-xml');
    }
}
```

Salvar em `backend/tests/Unit/NotaEntradaXmlParserTest.php`.

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
cd backend && php vendor/bin/phpunit tests/Unit/NotaEntradaXmlParserTest.php
```
Esperado: erro de classe `App\Services\NotaEntradaXmlParser` não encontrada.

- [ ] **Step 3: Implementar o parser**

```php
<?php
declare(strict_types=1);

namespace App\Services;

class NotaEntradaXmlParser
{
    /**
     * @return array{
     *   chave_acesso: ?string, numero_nf: ?string, serie: ?string, data_emissao: ?string,
     *   fornecedor_nome: ?string, fornecedor_cnpj: ?string, valor_total: float,
     *   itens: list<array{codigo_barras: ?string, descricao: string, quantidade: float, valor_unitario: float}>
     * }
     */
    public function parse(string $xmlContent): array
    {
        $semNamespace = preg_replace('/xmlns="[^"]*"/', '', $xmlContent);

        libxml_use_internal_errors(true);
        $sxml = simplexml_load_string((string) $semNamespace);
        libxml_clear_errors();

        if ($sxml === false) {
            throw new \InvalidArgumentException('Arquivo XML inválido ou corrompido.');
        }

        $infNFe = null;
        if (isset($sxml->infNFe)) {
            $infNFe = $sxml->infNFe;
        } elseif (isset($sxml->NFe->infNFe)) {
            $infNFe = $sxml->NFe->infNFe;
        }

        if ($infNFe === null) {
            throw new \InvalidArgumentException('XML não é uma NF-e válida (modelo 55): nó infNFe não encontrado.');
        }

        $chaveBruta = (string) ($infNFe['Id'] ?? '');
        $chave      = str_starts_with($chaveBruta, 'NFe') ? substr($chaveBruta, 3) : ($chaveBruta ?: null);

        $itens = [];
        foreach ($infNFe->det as $det) {
            $prod = $det->prod;
            $ean  = (string) ($prod->cEAN ?? '');
            if ($ean === '' || $ean === 'SEM GTIN') {
                $ean = (string) ($prod->cEANTrib ?? '');
            }
            if ($ean === '' || $ean === 'SEM GTIN') {
                $ean = null;
            }

            $itens[] = [
                'codigo_barras'  => $ean,
                'descricao'      => (string) ($prod->xProd ?? ''),
                'quantidade'     => (float) ($prod->qCom ?? 0),
                'valor_unitario' => (float) ($prod->vUnCom ?? 0),
            ];
        }

        $dhEmi = (string) ($infNFe->ide->dhEmi ?? $infNFe->ide->dEmi ?? '');

        return [
            'chave_acesso'    => $chave,
            'numero_nf'       => ((string) ($infNFe->ide->nNF ?? '')) ?: null,
            'serie'           => ((string) ($infNFe->ide->serie ?? '')) ?: null,
            'data_emissao'    => $dhEmi !== '' ? substr($dhEmi, 0, 10) : null,
            'fornecedor_nome' => ((string) ($infNFe->emit->xNome ?? '')) ?: null,
            'fornecedor_cnpj' => ((string) ($infNFe->emit->CNPJ ?? '')) ?: null,
            'valor_total'     => (float) ($infNFe->total->ICMSTot->vNF ?? 0),
            'itens'           => $itens,
        ];
    }
}
```

Salvar em `backend/app/Services/NotaEntradaXmlParser.php`.

- [ ] **Step 4: Rodar o teste e confirmar que passa**

```bash
cd backend && php vendor/bin/phpunit tests/Unit/NotaEntradaXmlParserTest.php
```
Esperado: `OK (5 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/NotaEntradaXmlParser.php backend/tests/Unit/NotaEntradaXmlParserTest.php
git commit -m "feat(estoque): parser de XML de NF-e para entrada de estoque"
```

---

### Task 5: `EstoqueService::registrarEntradaItem`

**Files:**
- Modify: `backend/app/Services/EstoqueService.php`
- Test: `backend/tests/Feature/EstoqueServiceTest.php` (adicionar ao final da classe existente)

**Interfaces:**
- Consumes: `App\Models\NotaEntrada` (Task 2), `App\Models\Produto`, `App\Models\MovimentacaoEstoque`.
- Produces: `EstoqueService::registrarEntradaItem(string $produtoId, int $quantidade, string
  $notaEntradaId, string $usuarioId): Produto` — trava o produto, soma `qty_atual`, cria a
  `MovimentacaoEstoque` (`tipo=ENTRADA`, `nota_entrada_id` preenchido) e devolve o produto atualizado.
  Task 7 (`EntradaNfController::store`) consome exatamente esta assinatura.

- [ ] **Step 1: Escrever o teste (falha primeiro — método ainda não existe)**

Adicionar ao final da classe em `backend/tests/Feature/EstoqueServiceTest.php` (antes do `}` de fechamento):
```php

    // -------------------------------------------------------------------------
    // registrarEntradaItem (entrada por NF-e)
    // -------------------------------------------------------------------------

    public function test_registrar_entrada_item_soma_estoque_e_cria_movimentacao(): void
    {
        $admin = $this->criarAdmin();
        Auth::login($admin);

        $produto = Produto::create([
            'nome'        => 'Vela de Ignição',
            'sku'         => 'VEL-001',
            'categoria'   => 'Elétrica',
            'qty_atual'   => 5,
            'qty_minima'  => 2,
            'preco_venda' => 30,
        ]);

        $nota = \App\Models\NotaEntrada::create([
            'numero_nf'   => '999',
            'valor_total' => 240,
        ]);

        $atualizado = $this->service->registrarEntradaItem($produto->id, 8, $nota->id, $admin->id);

        $this->assertSame(13, $atualizado->qty_atual);
        $this->assertDatabaseHas('movimentacoes_estoque', [
            'produto_id'      => $produto->id,
            'tipo'            => 'ENTRADA',
            'quantidade'      => 8,
            'nota_entrada_id' => $nota->id,
            'usuario_id'      => $admin->id,
        ]);
    }
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
cd backend && php vendor/bin/phpunit tests/Feature/EstoqueServiceTest.php --filter test_registrar_entrada_item_soma_estoque_e_cria_movimentacao
```
Esperado (em ambiente com Postgres): erro de método `registrarEntradaItem` não existir em
`EstoqueService`. **Se rodado localmente sem banco, vai falhar por falta de conexão — não é o teste que
valida a mudança; só confirmar isso em ambiente com banco (VPS/CI).**

- [ ] **Step 3: Implementar o método**

Em `backend/app/Services/EstoqueService.php`, adicionar após o método `entradaManual` (linha 34):
```php

    /**
     * Entrada de estoque originada de uma nota fiscal de compra (ver EntradaNfController).
     */
    public function registrarEntradaItem(string $produtoId, int $quantidade, string $notaEntradaId, string $usuarioId): Produto
    {
        return DB::transaction(function () use ($produtoId, $quantidade, $notaEntradaId, $usuarioId) {
            $produto = Produto::lockForUpdate()->findOrFail($produtoId);
            $produto->increment('qty_atual', $quantidade);

            MovimentacaoEstoque::create([
                'produto_id'      => $produto->id,
                'tipo'            => 'ENTRADA',
                'quantidade'      => $quantidade,
                'motivo'          => 'Entrada por NF-e',
                'nota_entrada_id' => $notaEntradaId,
                'usuario_id'      => $usuarioId,
            ]);

            return $produto->fresh();
        });
    }
```

- [ ] **Step 4: Rodar o teste e confirmar que passa (ambiente com banco)**

```bash
cd backend && php artisan test tests/Feature/EstoqueServiceTest.php --filter test_registrar_entrada_item_soma_estoque_e_cria_movimentacao
```
Esperado: `OK (1 test, ...)`. Localmente (sem banco), confirmar só a sintaxe:
```bash
php -l app/Services/EstoqueService.php
```

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/EstoqueService.php backend/tests/Feature/EstoqueServiceTest.php
git commit -m "feat(estoque): EstoqueService::registrarEntradaItem para entrada por NF-e"
```

---

### Task 6: `EntradaNfController::parse` — preview do XML (stateless, nada é gravado)

**Files:**
- Create: `backend/app/Http/Controllers/EntradaNfController.php`
- Modify: `backend/routes/api.php` (adicionar rota `POST entradas-nf/parse`)
- Test: `backend/tests/Feature/EntradaNfTest.php` (novo arquivo)

**Interfaces:**
- Consumes: `NotaEntradaXmlParser::parse()` (Task 4), `App\Models\Configuracao`, `App\Models\Produto`
  (busca por `codigo_barras`), `App\Models\NotaEntrada` (checagem de chave já lançada).
- Produces: `POST /api/entradas-nf/parse` (multipart, campo `arquivo`) → `200` com
  `{numero_nf, serie, chave_acesso, data_emissao, fornecedor_nome, fornecedor_cnpj, valor_total,
  ja_lancada, xml_original, itens: [{codigo_barras, descricao_xml, quantidade, valor_unitario, matched,
  produto_id, nome, categoria, unidade, qty_atual, preco_venda, qty_minima}]}` ou `422` se o XML for
  inválido. Task 9 (frontend) consome exatamente este shape.

- [ ] **Step 1: Escrever o teste (falha primeiro — controller/rota ainda não existem)**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotaEntrada;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EntradaNfTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): string
    {
        $user = Usuario::create([
            'nome' => 'Admin', 'email' => 'admin@test.com', 'cpf' => '52998224725',
            'role' => 'ADMIN', 'status' => 'ATIVO', 'senha_hash' => Hash::make('admin123'),
        ]);
        return $user->createToken('test')->plainTextToken;
    }

    private function xmlValido(): string
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
        </prod>
      </det>
      <det nItem="2">
        <prod>
          <cProd>FORN-002</cProd>
          <cEAN>SEM GTIN</cEAN>
          <xProd>PASTILHA DE FREIO GENERICA</xProd>
          <qCom>4.0000</qCom>
          <vUnCom>42.0000</vUnCom>
        </prod>
      </det>
      <total>
        <ICMSTot>
          <vNF>323.00</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }

    public function test_parse_xml_retorna_preview_com_match_e_novo(): void
    {
        $token = $this->loginAdmin();
        Produto::create([
            'nome' => 'Filtro de Óleo Existente', 'sku' => 'FLT-EXIST', 'categoria' => 'Filtros',
            'codigo_barras' => '7891234567890', 'qty_atual' => 3, 'qty_minima' => 5, 'preco_venda' => 40,
        ]);

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlValido());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertSame('1234', $response->json('numero_nf'));
        $this->assertCount(2, $response->json('itens'));
        $this->assertTrue($response->json('itens.0.matched'));
        $this->assertFalse($response->json('itens.1.matched'));
        $this->assertSame('Outros', $response->json('itens.1.categoria'));
    }

    public function test_parse_avisa_nota_ja_lancada(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => '35260712345678000199550010000012340000000001', 'numero_nf' => '1234']);

        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', $this->xmlValido());
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('ja_lancada'));
    }

    public function test_parse_xml_invalido_retorna_422(): void
    {
        $token    = $this->loginAdmin();
        $arquivo  = UploadedFile::fake()->createWithContent('nota.xml', '<xml><foo>bar</foo></xml>');
        $response = $this->withToken($token)->post('/api/entradas-nf/parse', ['arquivo' => $arquivo]);

        $response->assertStatus(422);
    }
}
```

Salvar em `backend/tests/Feature/EntradaNfTest.php`.

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
cd backend && php artisan test tests/Feature/EntradaNfTest.php
```
Esperado (ambiente com banco): rota `entradas-nf/parse` não encontrada (404).

- [ ] **Step 3: Criar o controller com o método `parse`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\NotaEntrada;
use App\Models\Produto;
use App\Services\NotaEntradaXmlParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntradaNfController extends Controller
{
    public function parse(Request $request, NotaEntradaXmlParser $parser): JsonResponse
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
        $markup          = (float) ($config->markup_padrao_entrada_nf ?? 40);
        $qtyMinimaPadrao = (int) ($config->estoque_limite_padrao ?? 5);

        $itens = array_map(function (array $item) use ($markup, $qtyMinimaPadrao) {
            $produto = $item['codigo_barras']
                ? Produto::where('codigo_barras', $item['codigo_barras'])->first()
                : null;

            if ($produto) {
                return [
                    'codigo_barras'  => $item['codigo_barras'],
                    'descricao_xml'  => $item['descricao'],
                    'quantidade'     => $item['quantidade'],
                    'valor_unitario' => $item['valor_unitario'],
                    'matched'        => true,
                    'produto_id'     => $produto->id,
                    'nome'           => $produto->nome,
                    'categoria'      => $produto->categoria,
                    'unidade'        => $produto->unidade,
                    'qty_atual'      => $produto->qty_atual,
                    'preco_venda'    => $produto->preco_venda,
                    'qty_minima'     => $produto->qty_minima,
                ];
            }

            $custo = $item['valor_unitario'];

            return [
                'codigo_barras'  => $item['codigo_barras'],
                'descricao_xml'  => $item['descricao'],
                'quantidade'     => $item['quantidade'],
                'valor_unitario' => $custo,
                'matched'        => false,
                'produto_id'     => null,
                'nome'           => $item['descricao'],
                'categoria'      => 'Outros',
                'unidade'        => 'Un',
                'qty_atual'      => 0,
                'preco_venda'    => round($custo * (1 + $markup / 100), 2),
                'qty_minima'     => $qtyMinimaPadrao,
            ];
        }, $dados['itens']);

        $jaLancada = $dados['chave_acesso']
            ? NotaEntrada::where('chave_acesso', $dados['chave_acesso'])->exists()
            : false;

        return response()->json([
            'numero_nf'       => $dados['numero_nf'],
            'serie'           => $dados['serie'],
            'chave_acesso'    => $dados['chave_acesso'],
            'data_emissao'    => $dados['data_emissao'],
            'fornecedor_nome' => $dados['fornecedor_nome'],
            'fornecedor_cnpj' => $dados['fornecedor_cnpj'],
            'valor_total'     => $dados['valor_total'],
            'ja_lancada'      => $jaLancada,
            'itens'           => $itens,
            'xml_original'    => $conteudo,
        ]);
    }
}
```

Salvar em `backend/app/Http/Controllers/EntradaNfController.php`.

- [ ] **Step 4: Registrar a rota**

Em `backend/routes/api.php`, logo após o bloco `// ─── Produtos ...` (depois da linha
`Route::post('produtos/{produto}/estoque/entrada', [EstoqueController::class, 'entrada']);`, dentro do
mesmo grupo `role:ADMIN,ATENDENTE`), adicionar:
```php
    Route::post('entradas-nf/parse', [EntradaNfController::class, 'parse']);
```
E adicionar o `use` no topo do arquivo, junto aos outros `use App\Http\Controllers\...`:
```php
use App\Http\Controllers\EntradaNfController;
```

- [ ] **Step 5: Rodar o teste e confirmar que passa (ambiente com banco)**

```bash
cd backend && php artisan test tests/Feature/EntradaNfTest.php
```
Esperado: `OK (3 tests, ...)`. Localmente, verificar só sintaxe:
```bash
php -l app/Http/Controllers/EntradaNfController.php && php -l routes/api.php && php -l tests/Feature/EntradaNfTest.php
```

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/EntradaNfController.php backend/routes/api.php backend/tests/Feature/EntradaNfTest.php
git commit -m "feat(estoque): endpoint de preview do XML de entrada de NF"
```

---

### Task 7: `EntradaNfController::store` — confirmação transacional

**Files:**
- Create: `backend/app/Http/Resources/NotaEntradaResource.php`
- Modify: `backend/app/Http/Controllers/EntradaNfController.php` (adicionar método `store`)
- Modify: `backend/routes/api.php` (adicionar rota `POST entradas-nf`)
- Modify: `backend/tests/Feature/EntradaNfTest.php` (adicionar os testes de `store`)

**Interfaces:**
- Consumes: `EstoqueService::registrarEntradaItem` (Task 5), `PlanLimitService::verificarLimiteProdutos`
  (já existe), `NotaEntrada`/`NotaEntradaItem` (Task 2).
- Produces: `POST /api/entradas-nf` (JSON, payload revisado pelo frontend) → `201` com
  `NotaEntradaResource` (nota + itens), ou `422` se `chave_acesso` já foi usada por essa oficina. Efeito
  colateral: cria produtos novos, incrementa estoque dos existentes, grava `notas_entrada`,
  `notas_entrada_itens` e `movimentacoes_estoque` numa única transação.

- [ ] **Step 1: Criar o `NotaEntradaResource`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotaEntradaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'numero_nf'       => $this->numero_nf,
            'serie'           => $this->serie,
            'chave_acesso'    => $this->chave_acesso,
            'fornecedor_nome' => $this->fornecedor_nome,
            'fornecedor_cnpj' => $this->fornecedor_cnpj,
            'valor_total'     => $this->valor_total,
            'data_emissao'    => $this->data_emissao?->format('d/m/Y'),
            'criado_em'       => $this->criado_em?->format('d/m/Y'),
            'itens'           => $this->whenLoaded('itens', fn() => $this->itens->map(fn($i) => [
                'id'                => $i->id,
                'produto_id'        => $i->produto_id,
                'descricao_xml'     => $i->descricao_xml,
                'codigo_barras_xml' => $i->codigo_barras_xml,
                'quantidade'        => $i->quantidade,
                'valor_unitario'    => $i->valor_unitario,
                'produto_criado'    => $i->produto_criado,
            ])),
        ];
    }
}
```

Salvar em `backend/app/Http/Resources/NotaEntradaResource.php`.

- [ ] **Step 2: Escrever os testes de `store` (falha primeiro — método ainda não existe)**

Adicionar ao final da classe em `backend/tests/Feature/EntradaNfTest.php` (antes do `}` de fechamento):
```php

    public function test_confirmar_entrada_cria_produto_novo_e_atualiza_estoque(): void
    {
        $token = $this->loginAdmin();

        $payload = [
            'numero_nf'       => '1234',
            'serie'           => '1',
            'chave_acesso'    => '35260712345678000199550010000012340000000001',
            'fornecedor_nome' => 'Auto Pecas Distribuidora LTDA',
            'fornecedor_cnpj' => '12345678000199',
            'data_emissao'    => '2026-07-01',
            'itens'           => [[
                'codigo_barras'  => '7891234567890',
                'nome'           => 'Filtro de Óleo XPTO',
                'categoria'      => 'Filtros',
                'unidade'        => 'Un',
                'quantidade'     => 10,
                'valor_unitario' => 15.50,
                'preco_venda'    => 25.00,
                'qty_minima'     => 5,
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('notas_entrada', [
            'numero_nf'    => '1234',
            'chave_acesso' => $payload['chave_acesso'],
        ]);

        $produto = Produto::where('codigo_barras', '7891234567890')->first();
        $this->assertNotNull($produto);
        $this->assertSame(10, $produto->qty_atual);

        $this->assertDatabaseHas('notas_entrada_itens', [
            'produto_id'     => $produto->id,
            'produto_criado' => true,
        ]);
    }

    public function test_confirmar_entrada_soma_estoque_de_produto_existente(): void
    {
        $token   = $this->loginAdmin();
        $produto = Produto::create([
            'nome' => 'Vela', 'sku' => 'VEL-01', 'categoria' => 'Elétrica',
            'codigo_barras' => '789000', 'qty_atual' => 5, 'qty_minima' => 2,
            'preco_custo' => 10, 'preco_venda' => 20,
        ]);

        $payload = [
            'itens' => [[
                'produto_id'     => $produto->id,
                'codigo_barras'  => '789000',
                'quantidade'     => 7,
                'valor_unitario' => 12.00,
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(201);
        $this->assertSame(12, $produto->fresh()->qty_atual);
        $this->assertSame(12.00, (float) $produto->fresh()->preco_custo);
    }

    public function test_confirmar_entrada_rejeita_chave_ja_lancada(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['chave_acesso' => 'CHAVE-DUPLICADA', 'numero_nf' => '1']);

        $payload = [
            'chave_acesso' => 'CHAVE-DUPLICADA',
            'itens'        => [[
                'nome' => 'Produto X', 'categoria' => 'Outros',
                'quantidade' => 1, 'valor_unitario' => 10,
            ]],
        ];

        $response = $this->withToken($token)->postJson('/api/entradas-nf', $payload);

        $response->assertStatus(422);
    }
```

- [ ] **Step 3: Rodar os testes e confirmar que falham**

```bash
cd backend && php artisan test tests/Feature/EntradaNfTest.php
```
Esperado (ambiente com banco): os 3 testes novos falham (rota `entradas-nf` POST não existe).

- [ ] **Step 4: Implementar `store` no controller**

Em `backend/app/Http/Controllers/EntradaNfController.php`, adicionar os `use` no topo:
```php
use App\Http\Resources\NotaEntradaResource;
use App\Models\NotaEntradaItem;
use App\Models\Produto;
use App\Services\EstoqueService;
use App\Services\PlanLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
```
(o `use App\Models\Produto;` já existe do Task 6 — não duplicar). Adicionar o método após `parse`:
```php

    public function store(Request $request, EstoqueService $estoqueService, PlanLimitService $planLimit): JsonResponse
    {
        $validated = $request->validate([
            'numero_nf'              => ['nullable', 'string', 'max:20'],
            'serie'                  => ['nullable', 'string', 'max:5'],
            'chave_acesso'           => ['nullable', 'string', 'max:44'],
            'fornecedor_nome'        => ['nullable', 'string', 'max:150'],
            'fornecedor_cnpj'        => ['nullable', 'string', 'max:18'],
            'data_emissao'           => ['nullable', 'date'],
            'xml_original'           => ['nullable', 'string'],
            'itens'                  => ['required', 'array', 'min:1'],
            'itens.*.produto_id'     => ['nullable', 'uuid', 'exists:produtos,id'],
            'itens.*.codigo_barras'  => ['nullable', 'string', 'max:20'],
            'itens.*.nome'           => ['required_without:itens.*.produto_id', 'nullable', 'string', 'max:150'],
            'itens.*.categoria'      => ['required_without:itens.*.produto_id', 'nullable', 'string', 'max:40'],
            'itens.*.unidade'        => ['nullable', 'string', 'max:10'],
            'itens.*.quantidade'     => ['required', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario' => ['required', 'numeric', 'min:0'],
            'itens.*.preco_venda'    => ['nullable', 'numeric', 'min:0'],
            'itens.*.qty_minima'     => ['nullable', 'integer', 'min:0'],
        ]);

        if (!empty($validated['chave_acesso']) && NotaEntrada::where('chave_acesso', $validated['chave_acesso'])->exists()) {
            return response()->json(['message' => 'Esta nota fiscal já foi lançada anteriormente.'], 422);
        }

        $config         = Configuracao::first();
        $atualizarCusto = (bool) ($config->atualizar_custo_entrada_nf ?? true);
        $usuarioId      = (string) auth()->id();

        $nota = DB::transaction(function () use ($validated, $estoqueService, $planLimit, $atualizarCusto, $usuarioId) {
            $valorTotal = collect($validated['itens'])->sum(fn($i) => $i['quantidade'] * $i['valor_unitario']);

            $nota = NotaEntrada::create([
                'numero_nf'       => $validated['numero_nf'] ?? null,
                'serie'           => $validated['serie'] ?? null,
                'chave_acesso'    => $validated['chave_acesso'] ?? null,
                'fornecedor_nome' => $validated['fornecedor_nome'] ?? null,
                'fornecedor_cnpj' => $validated['fornecedor_cnpj'] ?? null,
                'valor_total'     => $valorTotal,
                'data_emissao'    => $validated['data_emissao'] ?? null,
                'xml_original'    => $validated['xml_original'] ?? null,
                'usuario_id'      => $usuarioId,
            ]);

            foreach ($validated['itens'] as $item) {
                $produtoCriado = false;

                if (!empty($item['produto_id'])) {
                    $produto = Produto::lockForUpdate()->findOrFail($item['produto_id']);
                    if ($atualizarCusto) {
                        $produto->update(['preco_custo' => $item['valor_unitario']]);
                    }
                } else {
                    $planLimit->verificarLimiteProdutos();
                    $produto = Produto::create([
                        'nome'          => $item['nome'],
                        'sku'           => strtoupper(Str::random(8)),
                        'codigo_barras' => $item['codigo_barras'] ?? null,
                        'categoria'     => $item['categoria'],
                        'unidade'       => $item['unidade'] ?? 'Un',
                        'qty_atual'     => 0,
                        'qty_minima'    => $item['qty_minima'] ?? 5,
                        'preco_custo'   => $item['valor_unitario'],
                        'preco_venda'   => $item['preco_venda'] ?? $item['valor_unitario'],
                    ]);
                    $produtoCriado = true;
                }

                $estoqueService->registrarEntradaItem(
                    $produto->id,
                    (int) $item['quantidade'],
                    $nota->id,
                    $usuarioId,
                );

                NotaEntradaItem::create([
                    'nota_entrada_id'   => $nota->id,
                    'produto_id'        => $produto->id,
                    'codigo_barras_xml' => $item['codigo_barras'] ?? null,
                    'descricao_xml'     => $item['nome'] ?? $produto->nome,
                    'quantidade'        => $item['quantidade'],
                    'valor_unitario'    => $item['valor_unitario'],
                    'produto_criado'    => $produtoCriado,
                ]);
            }

            return $nota;
        });

        return (new NotaEntradaResource($nota->load('itens')))->response()->setStatusCode(201);
    }
```

- [ ] **Step 5: Registrar a rota**

Em `backend/routes/api.php`, na mesma linha adicionada no Task 6, adicionar logo abaixo:
```php
    Route::post('entradas-nf', [EntradaNfController::class, 'store']);
```

- [ ] **Step 6: Rodar os testes e confirmar que passam (ambiente com banco)**

```bash
cd backend && php artisan test tests/Feature/EntradaNfTest.php
```
Esperado: `OK (6 tests, ...)`. Localmente, verificar só sintaxe:
```bash
php -l app/Http/Controllers/EntradaNfController.php && php -l app/Http/Resources/NotaEntradaResource.php && php -l routes/api.php
```

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/EntradaNfController.php backend/app/Http/Resources/NotaEntradaResource.php backend/routes/api.php backend/tests/Feature/EntradaNfTest.php
git commit -m "feat(estoque): endpoint de confirmacao transacional da entrada de NF"
```

---

### Task 8: `EntradaNfController::index`/`show` — histórico

**Files:**
- Modify: `backend/app/Http/Controllers/EntradaNfController.php` (adicionar `index`, `show`)
- Modify: `backend/routes/api.php` (adicionar rotas GET)
- Modify: `backend/tests/Feature/EntradaNfTest.php` (adicionar os testes)

**Interfaces:**
- Produces: `GET /api/entradas-nf` (paginado, `NotaEntradaResource::collection`), `GET
  /api/entradas-nf/{id}` (`NotaEntradaResource` com `itens` carregado).

- [ ] **Step 1: Escrever os testes (falha primeiro)**

Adicionar ao final da classe em `backend/tests/Feature/EntradaNfTest.php`:
```php

    public function test_listar_entradas_nf(): void
    {
        $token = $this->loginAdmin();
        NotaEntrada::create(['numero_nf' => '1']);
        NotaEntrada::create(['numero_nf' => '2']);

        $response = $this->withToken($token)->getJson('/api/entradas-nf');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_detalhe_entrada_nf_com_itens(): void
    {
        $token   = $this->loginAdmin();
        $produto = Produto::create(['nome' => 'X', 'sku' => 'X1', 'categoria' => 'Outros']);
        $nota    = NotaEntrada::create(['numero_nf' => '1']);
        \App\Models\NotaEntradaItem::create([
            'nota_entrada_id' => $nota->id, 'produto_id' => $produto->id,
            'quantidade' => 2, 'valor_unitario' => 10,
        ]);

        $response = $this->withToken($token)->getJson("/api/entradas-nf/{$nota->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.itens'));
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

```bash
cd backend && php artisan test tests/Feature/EntradaNfTest.php
```
Esperado (ambiente com banco): os 2 testes novos falham (404, rota não existe).

- [ ] **Step 3: Implementar `index` e `show`**

Em `backend/app/Http/Controllers/EntradaNfController.php`, adicionar o `use`:
```php
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
```
E os métodos, ao final da classe:
```php

    public function index(): AnonymousResourceCollection
    {
        return NotaEntradaResource::collection(
            NotaEntrada::orderByDesc('criado_em')->paginate(20)
        );
    }

    public function show(string $id): NotaEntradaResource
    {
        return new NotaEntradaResource(NotaEntrada::with('itens')->findOrFail($id));
    }
```

- [ ] **Step 4: Registrar as rotas**

Em `backend/routes/api.php`, no grupo de leitura `Route::middleware(['tenant', 'auth:sanctum'])` que já
contém `produtos` (linhas 189-193), adicionar:
```php
    Route::get('entradas-nf',      [EntradaNfController::class, 'index']);
    Route::get('entradas-nf/{id}', [EntradaNfController::class, 'show']);
```

- [ ] **Step 5: Rodar os testes e confirmar que passam (ambiente com banco)**

```bash
cd backend && php artisan test tests/Feature/EntradaNfTest.php
```
Esperado: `OK (8 tests, ...)`. Localmente, verificar só sintaxe:
```bash
php -l app/Http/Controllers/EntradaNfController.php && php -l routes/api.php
```

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/EntradaNfController.php backend/routes/api.php backend/tests/Feature/EntradaNfTest.php
git commit -m "feat(estoque): historico e detalhe de entradas de NF"
```

---

### Task 9: Frontend — página `/produtos/entrada-nf`

**Files:**
- Create: `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`

**Interfaces:**
- Consumes: `POST /entradas-nf/parse` (Task 6), `POST /entradas-nf` (Task 7) via `api` de
  `frontend/lib/api.ts`; `toast` de `frontend/hooks/useToast.ts`.
- Produces: página completa (upload → tabela de conferência editável → confirmar), navegável em
  `/produtos/entrada-nf`. Task 10 aponta um link para esta rota.

- [ ] **Step 1: Criar a página**

```tsx
'use client'
import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { formatarMoeda } from '@/lib/formatters'
import { toast } from '@/hooks/useToast'
import api from '@/lib/api'

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
  itens: ItemPreview[]
  xml_original: string
}

const CATEGORIAS = ['Filtros', 'Óleo/Fluidos', 'Freios', 'Suspensão', 'Elétrica', 'Motor', 'Outros']

const inputStyle: React.CSSProperties = {
  width: '100%', padding: '6px 8px', borderRadius: 6,
  background: 'var(--bg)', border: '1px solid var(--border)',
  color: 'var(--text)', fontSize: 13, outline: 'none', boxSizing: 'border-box',
}

export default function EntradaNfPage() {
  const router = useRouter()
  const [uploading, setUploading] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [preview, setPreview] = useState<NotaPreview | null>(null)
  const [itens, setItens] = useState<ItemPreview[]>([])

  async function handleUpload(file: File) {
    setUploading(true)
    try {
      const formData = new FormData()
      formData.append('arquivo', file)
      const res = await api.post<NotaPreview>('/entradas-nf/parse', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setPreview(res.data)
      setItens(res.data.itens)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao ler o XML da nota.', 'danger')
    } finally {
      setUploading(false)
    }
  }

  function updateItem<K extends keyof ItemPreview>(idx: number, field: K, value: ItemPreview[K]) {
    setItens(prev => prev.map((it, i) => i === idx ? { ...it, [field]: value } : it))
  }

  function removeItem(idx: number) {
    setItens(prev => prev.filter((_, i) => i !== idx))
  }

  async function handleConfirmar() {
    if (!preview) return
    setConfirming(true)
    try {
      await api.post('/entradas-nf', {
        numero_nf: preview.numero_nf,
        serie: preview.serie,
        chave_acesso: preview.chave_acesso,
        fornecedor_nome: preview.fornecedor_nome,
        fornecedor_cnpj: preview.fornecedor_cnpj,
        data_emissao: preview.data_emissao,
        xml_original: preview.xml_original,
        itens: itens.map(i => ({
          produto_id: i.matched ? i.produto_id : null,
          codigo_barras: i.codigo_barras,
          nome: i.nome,
          categoria: i.categoria,
          unidade: i.unidade,
          quantidade: i.quantidade,
          valor_unitario: i.valor_unitario,
          preco_venda: i.preco_venda,
          qty_minima: i.qty_minima,
        })),
      })
      toast('Entrada de estoque registrada com sucesso!', 'success')
      router.push('/produtos')
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      toast(e.response?.data?.message ?? 'Erro ao confirmar a entrada.', 'danger')
    } finally {
      setConfirming(false)
    }
  }

  const totalCalculado = itens.reduce((acc, i) => acc + i.quantidade * i.valor_unitario, 0)
  const podeConfirmar = !!preview && !preview.ja_lancada && itens.length > 0 && !confirming

  return (
    <div style={{ maxWidth: 1100, margin: '0 auto' }}>
      <h1 className="font-display" style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', marginBottom: 24 }}>
        Lançar Entrada de Nota Fiscal
      </h1>

      {!preview && (
        <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 32, textAlign: 'center' }}>
          <p style={{ color: 'var(--muted)', marginBottom: 16 }}>
            Selecione o arquivo XML da NF-e enviado pelo fornecedor.
          </p>
          <input
            type="file"
            accept=".xml"
            disabled={uploading}
            onChange={e => { const f = e.target.files?.[0]; if (f) handleUpload(f) }}
            style={{ color: 'var(--text)' }}
          />
          {uploading && <p style={{ color: 'var(--muted)', marginTop: 12 }}>Lendo XML...</p>}
        </div>
      )}

      {preview && (
        <>
          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', padding: 20, marginBottom: 20 }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16 }}>
              <div>
                <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>NF-e</p>
                <p style={{ color: 'var(--text)', fontWeight: 600, margin: 0 }}>{preview.numero_nf ?? '-'} / série {preview.serie ?? '-'}</p>
              </div>
              <div>
                <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>Fornecedor</p>
                <p style={{ color: 'var(--text)', fontWeight: 600, margin: 0 }}>{preview.fornecedor_nome ?? '-'}</p>
              </div>
              <div>
                <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>Valor da nota (XML)</p>
                <p className="font-mono" style={{ color: 'var(--text)', fontWeight: 600, margin: 0 }}>{formatarMoeda(preview.valor_total)}</p>
              </div>
              <div>
                <p style={{ color: 'var(--muted)', fontSize: 12, margin: '0 0 4px' }}>Soma dos itens (revisado)</p>
                <p className="font-mono" style={{ color: Math.abs(totalCalculado - preview.valor_total) > 0.01 ? 'var(--accent)' : 'var(--text)', fontWeight: 600, margin: 0 }}>
                  {formatarMoeda(totalCalculado)}
                </p>
              </div>
            </div>
            {preview.ja_lancada && (
              <p style={{ color: 'var(--danger)', fontSize: 13, marginTop: 16, marginBottom: 0 }}>
                Esta nota fiscal já foi lançada anteriormente. Não é possível confirmar de novo.
              </p>
            )}
          </div>

          <div style={{ background: 'var(--card)', borderRadius: 12, border: '1px solid var(--border)', overflow: 'hidden', marginBottom: 20 }}>
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr>
                    {['Cód. barras', 'Descrição', 'Status', 'Categoria', 'Qtd', 'Custo', 'Venda', ''].map(h => (
                      <th key={h} style={{ padding: '10px 12px', textAlign: 'left', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {itens.map((item, idx) => (
                    <tr key={idx}>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }} className="font-mono">
                        {item.codigo_barras ?? '-'}
                      </td>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                        <input style={inputStyle} value={item.nome} disabled={item.matched}
                          onChange={e => updateItem(idx, 'nome', e.target.value)} />
                      </td>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                        <span style={{
                          padding: '3px 8px', borderRadius: 6, fontSize: 11, fontWeight: 700,
                          background: item.matched ? 'rgba(67,160,71,0.15)' : 'rgba(245,166,35,0.15)',
                          color: item.matched ? 'var(--success)' : 'var(--accent)',
                        }}>
                          {item.matched ? 'Existente' : 'Novo'}
                        </span>
                      </td>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                        {item.matched ? (
                          <span style={{ color: 'var(--muted)', fontSize: 13 }}>{item.categoria}</span>
                        ) : (
                          <select style={inputStyle} value={item.categoria} onChange={e => updateItem(idx, 'categoria', e.target.value)}>
                            {CATEGORIAS.map(c => <option key={c} value={c}>{c}</option>)}
                          </select>
                        )}
                      </td>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', width: 80 }}>
                        <input type="number" min={0.01} step="0.01" style={inputStyle} value={item.quantidade}
                          onChange={e => updateItem(idx, 'quantidade', Math.max(0.01, parseFloat(e.target.value) || 0))} />
                      </td>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', width: 100 }}>
                        <input type="number" min={0} step="0.01" style={inputStyle} value={item.valor_unitario}
                          onChange={e => updateItem(idx, 'valor_unitario', Math.max(0, parseFloat(e.target.value) || 0))} />
                      </td>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)', width: 100 }}>
                        <input type="number" min={0} step="0.01" style={inputStyle} value={item.preco_venda} disabled={item.matched}
                          onChange={e => updateItem(idx, 'preco_venda', Math.max(0, parseFloat(e.target.value) || 0))} />
                      </td>
                      <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--border)' }}>
                        <button type="button" onClick={() => removeItem(idx)}
                          style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 16 }}
                          title="Remover item">✕</button>
                      </td>
                    </tr>
                  ))}
                  {itens.length === 0 && (
                    <tr><td colSpan={8} style={{ padding: 24, textAlign: 'center', color: 'var(--muted)' }}>Nenhum item na nota.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12 }}>
            <button type="button" onClick={() => { setPreview(null); setItens([]) }}
              style={{ padding: '10px 20px', borderRadius: 8, background: 'transparent', border: '1px solid var(--border)', color: 'var(--muted)', cursor: 'pointer' }}>
              Cancelar
            </button>
            <button type="button" onClick={handleConfirmar} disabled={!podeConfirmar} className="font-display"
              style={{
                padding: '10px 28px', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 16,
                background: podeConfirmar ? 'var(--accent)' : 'var(--muted)', color: '#000',
                cursor: podeConfirmar ? 'pointer' : 'not-allowed',
              }}>
              {confirming ? 'Confirmando...' : 'Confirmar Entrada'}
            </button>
          </div>
        </>
      )}
    </div>
  )
}
```

Salvar em `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`.

- [ ] **Step 2: Verificar tipos e lint**

```bash
cd frontend && npx tsc --noEmit
npx eslint "app/(dashboard)/produtos/entrada-nf/page.tsx"
```
Esperado: sem erros (`tsc` sem saída; eslint sem `error`, warnings pré-existentes de outras libs não
contam).

- [ ] **Step 3: Commit**

```bash
git add "frontend/app/(dashboard)/produtos/entrada-nf/page.tsx"
git commit -m "feat(estoque): pagina de entrada de NF por XML"
```

---

### Task 10: Frontend — integração (botão em Produtos + campos em Configurações)

**Files:**
- Modify: `frontend/app/(dashboard)/produtos/page.tsx`
- Modify: `frontend/app/(dashboard)/configuracoes/page.tsx`

**Interfaces:**
- Consumes: rota `/produtos/entrada-nf` (Task 9); campos `markup_padrao_entrada_nf` e
  `atualizar_custo_entrada_nf` do `GET/PUT /configuracoes` (Task 3).

- [ ] **Step 1: Adicionar o botão "+ Lançar NF" em `/produtos`**

Em `frontend/app/(dashboard)/produtos/page.tsx`, o cabeçalho atual (linhas 168-190) é:
```tsx
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1
          className="font-display"
          style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          Produtos / Estoque
        </h1>
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Buscar por nome ou SKU..."
          style={{
            padding: '8px 14px',
            borderRadius: 8,
            background: 'var(--card)',
            border: '1px solid var(--border)',
            color: 'var(--text)',
            fontSize: 14,
            width: 280,
            outline: 'none',
          }}
        />
      </div>
```
Trocar por (envolve a busca e o botão novo num wrapper `div` à direita):
```tsx
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1
          className="font-display"
          style={{ fontSize: 28, fontWeight: 800, color: 'var(--text)', margin: 0 }}>
          Produtos / Estoque
        </h1>
        <div style={{ display: 'flex', gap: 12 }}>
          <button
            onClick={() => router.push('/produtos/entrada-nf')}
            style={{
              padding: '8px 16px',
              borderRadius: 8,
              background: 'rgba(245,166,35,0.12)',
              border: '1px solid var(--accent)',
              color: 'var(--accent)',
              cursor: 'pointer',
              fontSize: 14,
              whiteSpace: 'nowrap',
            }}>
            + Lançar NF
          </button>
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Buscar por nome ou SKU..."
            style={{
              padding: '8px 14px',
              borderRadius: 8,
              background: 'var(--card)',
              border: '1px solid var(--border)',
              color: 'var(--text)',
              fontSize: 14,
              width: 280,
              outline: 'none',
            }}
          />
        </div>
      </div>
```
(`router` já existe no componente — `const router = useRouter()` na linha 41.)

- [ ] **Step 2: Adicionar os campos novos em `/configuracoes`**

Em `frontend/app/(dashboard)/configuracoes/page.tsx`, o estado inicial (linhas 7-11) é:
```tsx
  const [form, setForm] = useState({
    estoque_limite_padrao: 5,
    alertas_email: true,
    email_alertas: '',
  })
```
Trocar por:
```tsx
  const [form, setForm] = useState({
    estoque_limite_padrao: 5,
    alertas_email: true,
    email_alertas: '',
    markup_padrao_entrada_nf: 40,
    atualizar_custo_entrada_nf: true,
  })
```
O `useEffect` de carregamento (linhas 14-23) é:
```tsx
  useEffect(() => {
    api.get('/configuracoes').then(r => {
      const d = r.data
      setForm({
        estoque_limite_padrao: d.estoque_limite_padrao ?? 5,
        alertas_email: d.alertas_email ?? true,
        email_alertas: d.email_alertas ?? '',
      })
    }).catch(() => {})
  }, [])
```
Trocar por:
```tsx
  useEffect(() => {
    api.get('/configuracoes').then(r => {
      const d = r.data
      setForm({
        estoque_limite_padrao: d.estoque_limite_padrao ?? 5,
        alertas_email: d.alertas_email ?? true,
        email_alertas: d.email_alertas ?? '',
        markup_padrao_entrada_nf: d.markup_padrao_entrada_nf ?? 40,
        atualizar_custo_entrada_nf: d.atualizar_custo_entrada_nf ?? true,
      })
    }).catch(() => {})
  }, [])
```
Logo após o bloco do campo "Limite padrão de alerta (unidades)" (linhas 47-55, termina em `</div>`,
antes do `<h3>` de "Notificações" na linha 57), adicionar dois campos novos dentro da mesma seção
"Estoque":
```tsx
        <div style={{ marginBottom: 24 }}>
          <label style={lStyle}>Markup padrão para produtos novos na entrada de NF (%)</label>
          <input type="number" min={0} step="0.1" value={form.markup_padrao_entrada_nf}
            onChange={e => setForm(f => ({ ...f, markup_padrao_entrada_nf: +e.target.value }))}
            style={{ ...iStyle, width: 120 }} />
          <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 4 }}>
            Usado para sugerir o preço de venda de produtos criados ao importar uma nota fiscal de compra.
          </p>
        </div>
        <div style={{ marginBottom: 24 }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}>
            <input type="checkbox" checked={form.atualizar_custo_entrada_nf}
              onChange={e => setForm(f => ({ ...f, atualizar_custo_entrada_nf: e.target.checked }))} />
            <span style={{ color: 'var(--text)', fontSize: 14 }}>Atualizar o custo do produto ao lançar entrada por NF</span>
          </label>
        </div>
```

- [ ] **Step 3: Verificar tipos e lint**

```bash
cd frontend && npx tsc --noEmit
npx eslint "app/(dashboard)/produtos/page.tsx" "app/(dashboard)/configuracoes/page.tsx"
```
Esperado: sem erros.

- [ ] **Step 4: Commit**

```bash
git add "frontend/app/(dashboard)/produtos/page.tsx" "frontend/app/(dashboard)/configuracoes/page.tsx"
git commit -m "feat(estoque): botao de lancar NF em Produtos e config de markup/custo"
```

---

## Nota sobre deploy

Este plano **não inclui deploy**. Depois de todas as tasks implementadas (e com o fix de
`ClienteForm.tsx` já pronto de uma sessão anterior), rodar a suíte completa de Feature tests num
ambiente com Postgres (VPS/CI) antes de decidir sobre o deploy — ver [[feedback-local-testing]] e
[[feedback-deploy]] no histórico do projeto.
