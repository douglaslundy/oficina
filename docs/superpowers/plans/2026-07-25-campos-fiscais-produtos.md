# Campos Fiscais em Produtos + Importação de XML — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar a `produtos` os campos fiscais que a NF-e exige (NCM, CEST, origem, situação tributária) e fazer a importação de NF-e por XML preenchê-los automaticamente com o dado assinado pelo fornecedor.

**Architecture:** Duas classes puras de regra fiscal (derivação de tributação e validação de formato) alimentam o parser de XML já existente. Um serviço aplica os dados ao produto sob uma política de conflito que nunca sobrescreve valor existente — divergência vira registro para revisão humana. Telas novas expõem as pendências.

**Tech Stack:** Laravel 11 / PHP 8.3 (`declare(strict_types=1)`), PostgreSQL 16, PHPUnit, Next.js 14 (App Router) + TypeScript.

## Global Constraints

- Spec de referência: `docs/superpowers/specs/2026-07-25-campos-fiscais-produtos-design.md`.
- Todo arquivo PHP novo começa com `declare(strict_types=1);`.
- Models de negócio por oficina usam `App\Tenancy\HasTenantScope` + coluna `oficina_id`, seguindo `App\Models\Produto`.
- Models do projeto usam `public $timestamps = false;` e coluna `criado_em`; UUID gerado em `boot()` via `static::creating`.
- **Códigos de ST verificados na legislação — usar exatamente estes conjuntos:** CST com ST = `10, 30, 60, 70`; CSOSN com ST = `201, 202, 203, 500`; origem da mercadoria = `0` a `8`.
- **Código desconhecido nunca vira `NORMAL`.** A derivação tem três estados: `ST`, `NORMAL`, ou `null` (desconhecido → pendência). Motivo: o Ajuste SINIEF 39/2023 criou CST 12/13/52/72/74 e o Ajuste SINIEF 20/2024 revogou a criação — a tabela é instável e um `else` silencioso classificaria peça ST como NORMAL.
- **Nunca gravar valor fiscal malformado.** NCM sem 8 dígitos, CEST sem 7, origem fora de 0–8 → grava `null` e registra pendência. Vazio é visível; lixo passa por preenchido.
- **A importação nunca falha inteira por causa de dado fiscal.** Ela existe para dar entrada em estoque; enriquecer o cadastro é ganho secundário.
- **CFOP não é coluna de `produtos`** — é atributo da operação, não da mercadoria. Só é capturado como auditoria em `notas_entrada_itens.cfop_xml`.
- Testes unitários (`tests/Unit/`) **rodam localmente** — são lógica pura, sem banco. Testes de Feature **não rodam** (não há Postgres local); escreva-os mesmo assim.
- Frontend usa estilos inline (`iStyle`, `lStyle`) — seguir o padrão de `frontend/components/forms/ProdutoForm.tsx`, não introduzir CSS modules.
- Cores de status vêm do design system: `--danger` (#e53935) para bloqueante, `--accent` (#f5a623) para atenção.

---

### Task 1: Regras fiscais puras — derivação de tributação e validação de formato

**Files:**
- Create: `backend/app/Services/Fiscal/ClassificacaoIcms.php`
- Create: `backend/app/Services/Fiscal/ValidadorCamposFiscais.php`
- Test: `backend/tests/Unit/Fiscal/ClassificacaoIcmsTest.php`
- Test: `backend/tests/Unit/Fiscal/ValidadorCamposFiscaisTest.php`

**Interfaces:**
- Consumes: nada (primeira task).
- Produces:
  - `ClassificacaoIcms::ST` (`'ST'`), `ClassificacaoIcms::NORMAL` (`'NORMAL'`)
  - `ClassificacaoIcms::derivar(?string $cst, ?string $csosn): ?string`
  - `ValidadorCamposFiscais::ncm(?string $v): ?string`
  - `ValidadorCamposFiscais::cest(?string $v): ?string`
  - `ValidadorCamposFiscais::origem(int|string|null $v): ?int`

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Unit/Fiscal/ClassificacaoIcmsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\ClassificacaoIcms;
use PHPUnit\Framework\TestCase;

class ClassificacaoIcmsTest extends TestCase
{
    public function test_cst_de_substituicao_tributaria(): void
    {
        foreach (['10', '30', '60', '70'] as $cst) {
            $this->assertSame(ClassificacaoIcms::ST, ClassificacaoIcms::derivar($cst, null), "CST {$cst}");
        }
    }

    public function test_cst_normal(): void
    {
        foreach (['00', '20', '40', '41', '50', '51', '90'] as $cst) {
            $this->assertSame(ClassificacaoIcms::NORMAL, ClassificacaoIcms::derivar($cst, null), "CST {$cst}");
        }
    }

    public function test_csosn_de_substituicao_tributaria(): void
    {
        foreach (['201', '202', '203', '500'] as $csosn) {
            $this->assertSame(ClassificacaoIcms::ST, ClassificacaoIcms::derivar(null, $csosn), "CSOSN {$csosn}");
        }
    }

    public function test_csosn_normal(): void
    {
        foreach (['101', '102', '103', '300', '400', '900'] as $csosn) {
            $this->assertSame(ClassificacaoIcms::NORMAL, ClassificacaoIcms::derivar(null, $csosn), "CSOSN {$csosn}");
        }
    }

    /** CST 12 foi criado pelo Ajuste SINIEF 39/2023 e revogado pelo 20/2024. */
    public function test_cst_revogado_nao_e_assumido_como_normal(): void
    {
        $this->assertNull(ClassificacaoIcms::derivar('12', null));
        $this->assertNull(ClassificacaoIcms::derivar('52', null));
        $this->assertNull(ClassificacaoIcms::derivar('72', null));
    }

    public function test_codigo_desconhecido_devolve_null(): void
    {
        $this->assertNull(ClassificacaoIcms::derivar('99', null));
        $this->assertNull(ClassificacaoIcms::derivar(null, '777'));
        $this->assertNull(ClassificacaoIcms::derivar(null, null));
    }

    public function test_normaliza_codigo_com_espaco_e_sem_zero_a_esquerda(): void
    {
        $this->assertSame(ClassificacaoIcms::NORMAL, ClassificacaoIcms::derivar(' 0 ', null));
        $this->assertSame(ClassificacaoIcms::ST, ClassificacaoIcms::derivar(' 60 ', null));
    }

    public function test_csosn_tem_precedencia_quando_ambos_vem_preenchidos(): void
    {
        $this->assertSame(ClassificacaoIcms::ST, ClassificacaoIcms::derivar('00', '500'));
    }
}
```

Create `backend/tests/Unit/Fiscal/ValidadorCamposFiscaisTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\ValidadorCamposFiscais;
use PHPUnit\Framework\TestCase;

class ValidadorCamposFiscaisTest extends TestCase
{
    public function test_ncm_valido(): void
    {
        $this->assertSame('87083090', ValidadorCamposFiscais::ncm('87083090'));
        $this->assertSame('87083090', ValidadorCamposFiscais::ncm('8708.30.90'));
    }

    public function test_ncm_invalido_devolve_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::ncm('8708309'));    // 7 dígitos
        $this->assertNull(ValidadorCamposFiscais::ncm('870830901'));  // 9 dígitos
        $this->assertNull(ValidadorCamposFiscais::ncm(''));
        $this->assertNull(ValidadorCamposFiscais::ncm(null));
        $this->assertNull(ValidadorCamposFiscais::ncm('ABCDEFGH'));
    }

    public function test_cest_valido(): void
    {
        $this->assertSame('0100100', ValidadorCamposFiscais::cest('0100100'));
        $this->assertSame('0100100', ValidadorCamposFiscais::cest('01.001.00'));
    }

    public function test_cest_invalido_devolve_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::cest('010010'));   // 6 dígitos
        $this->assertNull(ValidadorCamposFiscais::cest('01001000')); // 8 dígitos
        $this->assertNull(ValidadorCamposFiscais::cest(null));
    }

    public function test_origem_valida(): void
    {
        $this->assertSame(0, ValidadorCamposFiscais::origem('0'));
        $this->assertSame(8, ValidadorCamposFiscais::origem('8'));
        $this->assertSame(3, ValidadorCamposFiscais::origem(3));
    }

    public function test_origem_invalida_devolve_null(): void
    {
        $this->assertNull(ValidadorCamposFiscais::origem('9'));
        $this->assertNull(ValidadorCamposFiscais::origem('-1'));
        $this->assertNull(ValidadorCamposFiscais::origem('x'));
        $this->assertNull(ValidadorCamposFiscais::origem(null));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter="ClassificacaoIcmsTest|ValidadorCamposFiscaisTest"`
Expected: FAIL — `Class "App\Services\Fiscal\ClassificacaoIcms" not found`

- [ ] **Step 3: Implement `ClassificacaoIcms`**

Create `backend/app/Services/Fiscal/ClassificacaoIcms.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Deriva a situação tributária de ICMS a partir do CST ou CSOSN declarado
 * no XML do fornecedor.
 *
 * Base legal:
 * - CST:   Tabela B do Anexo do Convênio SINIEF s/nº de 15/12/1970.
 * - CSOSN: Ajuste SINIEF 03/2010, Anexo Único, Tabela B.
 *
 * A derivação tem TRÊS estados de propósito. O Ajuste SINIEF 39/2023 criou
 * os CST 12/13/52/72/74 (todos de ST) e o Ajuste SINIEF 20/2024 revogou a
 * criação — a tabela é instável. Um `else` que assumisse NORMAL
 * classificaria silenciosamente peça de ST como tributação normal, que é o
 * erro caro. Código não reconhecido devolve null e vira pendência.
 */
final class ClassificacaoIcms
{
    public const ST     = 'ST';
    public const NORMAL = 'NORMAL';

    private const CST_ST     = ['10', '30', '60', '70'];
    private const CST_NORMAL = ['00', '20', '40', '41', '50', '51', '90'];

    private const CSOSN_ST     = ['201', '202', '203', '500'];
    private const CSOSN_NORMAL = ['101', '102', '103', '300', '400', '900'];

    /** @return self::ST|self::NORMAL|null  null = código desconhecido */
    public static function derivar(?string $cst, ?string $csosn): ?string
    {
        $csosnLimpo = self::normalizar($csosn, 3);
        if ($csosnLimpo !== null) {
            if (in_array($csosnLimpo, self::CSOSN_ST, true))     return self::ST;
            if (in_array($csosnLimpo, self::CSOSN_NORMAL, true)) return self::NORMAL;
            return null;
        }

        $cstLimpo = self::normalizar($cst, 2);
        if ($cstLimpo !== null) {
            if (in_array($cstLimpo, self::CST_ST, true))     return self::ST;
            if (in_array($cstLimpo, self::CST_NORMAL, true)) return self::NORMAL;
        }

        return null;
    }

    /** Remove não-dígitos e reaplica o zero à esquerda que o XML às vezes omite. */
    private static function normalizar(?string $valor, int $tamanho): ?string
    {
        if ($valor === null) return null;
        $digitos = preg_replace('/\D/', '', $valor) ?? '';
        if ($digitos === '') return null;
        return str_pad($digitos, $tamanho, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 4: Implement `ValidadorCamposFiscais`**

Create `backend/app/Services/Fiscal/ValidadorCamposFiscais.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Normaliza e valida o formato dos campos fiscais.
 *
 * Devolve null para qualquer valor malformado, NUNCA o valor cru. Guardar
 * lixo num campo fiscal é pior que deixá-lo vazio: o vazio aparece na tela
 * de pendências, o lixo passa por preenchido.
 */
final class ValidadorCamposFiscais
{
    /** NCM/SH tem 8 dígitos. */
    public static function ncm(?string $valor): ?string
    {
        return self::apenasDigitos($valor, 8);
    }

    /** CEST tem 7 dígitos (Convênio ICMS 142/2018). */
    public static function cest(?string $valor): ?string
    {
        return self::apenasDigitos($valor, 7);
    }

    /** Origem da mercadoria: 0 a 8 (Tabela A do Anexo do Convênio SINIEF s/nº 1970). */
    public static function origem(int|string|null $valor): ?int
    {
        if ($valor === null || $valor === '') return null;
        if (!is_numeric($valor))              return null;

        $inteiro = (int) $valor;

        return ($inteiro >= 0 && $inteiro <= 8) ? $inteiro : null;
    }

    private static function apenasDigitos(?string $valor, int $tamanho): ?string
    {
        if ($valor === null) return null;
        $digitos = preg_replace('/\D/', '', $valor) ?? '';

        return strlen($digitos) === $tamanho ? $digitos : null;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter="ClassificacaoIcmsTest|ValidadorCamposFiscaisTest"`
Expected: PASS — todos os testes verdes.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Fiscal/ClassificacaoIcms.php \
        backend/app/Services/Fiscal/ValidadorCamposFiscais.php \
        backend/tests/Unit/Fiscal/ClassificacaoIcmsTest.php \
        backend/tests/Unit/Fiscal/ValidadorCamposFiscaisTest.php
git commit -m "feat(fiscal): regras puras de classificacao ICMS e validacao de campos fiscais"
```

---

### Task 2: Parser de XML extrai os campos fiscais

**Files:**
- Modify: `backend/app/Services/NotaEntradaXmlParser.php:41-71`
- Test: `backend/tests/Unit/NotaEntradaXmlParserTest.php` (arquivo já existe — adicionar casos)

**Interfaces:**
- Consumes: `ClassificacaoIcms::derivar()`, `ValidadorCamposFiscais::{ncm,cest,origem}()` (Task 1).
- Produces: cada item de `parse()['itens']` passa a conter, além das chaves atuais (`codigo_barras`, `descricao`, `quantidade`, `valor_unitario`):
  ```
  'ncm'             => ?string   // 8 dígitos ou null
  'cfop'            => ?string   // 4 dígitos ou null (auditoria; NÃO vai pro produto)
  'cest'            => ?string   // 7 dígitos ou null
  'origem'          => ?int      // 0..8 ou null
  'cst_csosn'       => ?string   // código cru normalizado, para auditoria
  'unidade'         => ?string   // uCom
  'tributacao_icms' => ?string   // 'ST' | 'NORMAL' | null
  ```

- [ ] **Step 1: Write the failing tests**

Adicione ao final de `backend/tests/Unit/NotaEntradaXmlParserTest.php`, **antes** do `}` que fecha a classe:

```php
    private function xmlComDadosFiscais(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
      <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-07-01T09:15:32-03:00</dhEmi></ide>
      <emit><CNPJ>12345678000199</CNPJ><xNome>Auto Pecas LTDA</xNome></emit>
      <det nItem="1">
        <prod>
          <cEAN>7891234567890</cEAN><xProd>FILTRO DE OLEO</xProd>
          <NCM>84212300</NCM><CFOP>5405</CFOP><uCom>PC</uCom>
          <qCom>10.0000</qCom><vUnCom>15.5000</vUnCom>
        </prod>
        <imposto><ICMS><ICMS60><orig>0</orig><CST>60</CST></ICMS60></ICMS></imposto>
      </det>
      <det nItem="2">
        <prod>
          <cEAN>7899999999999</cEAN><xProd>PASTILHA DE FREIO</xProd>
          <NCM>8708.30.90</NCM><CFOP>6404</CFOP><CEST>0100100</CEST><uCom>PAR</uCom>
          <qCom>4.0000</qCom><vUnCom>42.0000</vUnCom>
        </prod>
        <imposto><ICMS><ICMSSN500><orig>1</orig><CSOSN>500</CSOSN></ICMSSN500></ICMS></imposto>
      </det>
      <det nItem="3">
        <prod>
          <cEAN>7888888888888</cEAN><xProd>OLEO MOTOR 5W30</xProd>
          <NCM>27101932</NCM><CFOP>5102</CFOP><uCom>L</uCom>
          <qCom>20.0000</qCom><vUnCom>28.0000</vUnCom>
        </prod>
        <imposto><ICMS><ICMS00><orig>0</orig><CST>00</CST></ICMS00></ICMS></imposto>
      </det>
      <det nItem="4">
        <prod>
          <cEAN>7877777777777</cEAN><xProd>ITEM COM CST REVOGADO</xProd>
          <NCM>1234567</NCM><CFOP>5102</CFOP><uCom>UN</uCom>
          <qCom>1.0000</qCom><vUnCom>10.0000</vUnCom>
        </prod>
        <imposto><ICMS><ICMS12><orig>99</orig><CST>12</CST></ICMS12></ICMS></imposto>
      </det>
      <total><ICMSTot><vNF>1000.00</vNF></ICMSTot></total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }

    public function test_extrai_ncm_cfop_e_unidade(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('84212300', $itens[0]['ncm']);
        $this->assertSame('5405', $itens[0]['cfop']);
        $this->assertSame('PC', $itens[0]['unidade']);
    }

    public function test_ncm_com_pontuacao_e_normalizado(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('87083090', $itens[1]['ncm']);
        $this->assertSame('0100100', $itens[1]['cest']);
    }

    public function test_deriva_st_de_cst_60(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('ST', $itens[0]['tributacao_icms']);
        $this->assertSame(0, $itens[0]['origem']);
        $this->assertSame('60', $itens[0]['cst_csosn']);
    }

    public function test_deriva_st_de_csosn_500(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('ST', $itens[1]['tributacao_icms']);
        $this->assertSame(1, $itens[1]['origem']);
        $this->assertSame('500', $itens[1]['cst_csosn']);
    }

    public function test_deriva_normal_de_cst_00(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertSame('NORMAL', $itens[2]['tributacao_icms']);
    }

    public function test_cst_revogado_e_dados_invalidos_viram_null(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlComDadosFiscais())['itens'];

        $this->assertNull($itens[3]['tributacao_icms'], 'CST 12 não pode ser assumido como NORMAL');
        $this->assertNull($itens[3]['ncm'],    'NCM de 7 dígitos não pode ser gravado');
        $this->assertNull($itens[3]['origem'], 'origem 99 não pode ser gravada');
    }

    public function test_item_sem_bloco_de_imposto_nao_quebra(): void
    {
        $itens = (new NotaEntradaXmlParser())->parse($this->xmlValido())['itens'];

        $this->assertNull($itens[0]['ncm']);
        $this->assertNull($itens[0]['tributacao_icms']);
        $this->assertSame('FILTRO DE OLEO XPTO', $itens[0]['descricao']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=NotaEntradaXmlParserTest`
Expected: FAIL — `Undefined array key "ncm"`. Os cinco testes antigos continuam passando.

- [ ] **Step 3: Implement the parser changes**

Em `backend/app/Services/NotaEntradaXmlParser.php`, adicione os imports após `namespace App\Services;`:

```php
use App\Services\Fiscal\ClassificacaoIcms;
use App\Services\Fiscal\ValidadorCamposFiscais;
```

Substitua o bloco `foreach ($infNFe->det as $det) { ... }` (linhas 42-58) por:

```php
        foreach ($infNFe->det as $det) {
            $prod = $det->prod;
            $ean  = (string) ($prod->cEAN ?? '');
            if ($ean === '' || $ean === 'SEM GTIN') {
                $ean = (string) ($prod->cEANTrib ?? '');
            }
            if ($ean === '' || $ean === 'SEM GTIN') {
                $ean = null;
            }

            $icms = $this->extrairIcms($det);

            $itens[] = [
                'codigo_barras'   => $ean,
                'descricao'       => (string) ($prod->xProd ?? ''),
                'quantidade'      => (float) ($prod->qCom ?? 0),
                'valor_unitario'  => (float) ($prod->vUnCom ?? 0),
                'ncm'             => ValidadorCamposFiscais::ncm(isset($prod->NCM) ? (string) $prod->NCM : null),
                'cfop'            => $this->digitosOuNull(isset($prod->CFOP) ? (string) $prod->CFOP : null, 4),
                'cest'            => ValidadorCamposFiscais::cest(isset($prod->CEST) ? (string) $prod->CEST : null),
                'unidade'         => ((string) ($prod->uCom ?? '')) ?: null,
                'origem'          => ValidadorCamposFiscais::origem($icms['orig']),
                'cst_csosn'       => $icms['cst'] ?? $icms['csosn'],
                'tributacao_icms' => ClassificacaoIcms::derivar($icms['cst'], $icms['csosn']),
            ];
        }
```

Adicione os dois métodos privados antes do `}` que fecha a classe:

```php
    /**
     * O nó de ICMS tem nome variável (ICMS00, ICMS60, ICMSSN500, ...), então
     * é preciso iterar os filhos em vez de acessar por nome fixo — acessar
     * por nome fixo é o erro clássico neste parse.
     *
     * @return array{orig: ?string, cst: ?string, csosn: ?string}
     */
    private function extrairIcms(\SimpleXMLElement $det): array
    {
        $vazio = ['orig' => null, 'cst' => null, 'csosn' => null];

        if (!isset($det->imposto->ICMS)) {
            return $vazio;
        }

        foreach ($det->imposto->ICMS->children() as $grupo) {
            return [
                'orig'  => isset($grupo->orig)  ? (string) $grupo->orig  : null,
                'cst'   => isset($grupo->CST)   ? (string) $grupo->CST   : null,
                'csosn' => isset($grupo->CSOSN) ? (string) $grupo->CSOSN : null,
            ];
        }

        return $vazio;
    }

    private function digitosOuNull(?string $valor, int $tamanho): ?string
    {
        if ($valor === null) return null;
        $digitos = preg_replace('/\D/', '', $valor) ?? '';

        return strlen($digitos) === $tamanho ? $digitos : null;
    }
```

Atualize o PHPDoc de `parse()` para refletir as chaves novas em `itens`:

```php
     *   itens: list<array{codigo_barras: ?string, descricao: string, quantidade: float,
     *                     valor_unitario: float, ncm: ?string, cfop: ?string, cest: ?string,
     *                     unidade: ?string, origem: ?int, cst_csosn: ?string,
     *                     tributacao_icms: ?string}>
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php artisan test --filter=NotaEntradaXmlParserTest`
Expected: PASS — 12 testes (5 antigos + 7 novos).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/NotaEntradaXmlParser.php \
        backend/tests/Unit/NotaEntradaXmlParserTest.php
git commit -m "feat(fiscal): parser de NF-e de entrada extrai NCM, CFOP, CEST, origem e CST/CSOSN"
```

---

### Task 3: Schema e models

**Files:**
- Create: `backend/database/migrations/2026_07_25_000001_add_campos_fiscais_to_produtos_table.php`
- Create: `backend/database/migrations/2026_07_25_000002_add_campos_fiscais_to_notas_entrada_itens_table.php`
- Create: `backend/database/migrations/2026_07_25_000003_create_produto_fiscal_divergencias_table.php`
- Create: `backend/database/migrations/2026_07_25_000004_create_categoria_padrao_fiscal_table.php`
- Create: `backend/app/Models/ProdutoFiscalDivergencia.php`
- Create: `backend/app/Models/CategoriaPadraoFiscal.php`
- Modify: `backend/app/Models/Produto.php:23-33` (fillable + casts)
- Modify: `backend/app/Models/NotaEntradaItem.php` (fillable)

**Interfaces:**
- Consumes: nada.
- Produces:
  - Colunas em `produtos`: `ncm`, `cest`, `origem`, `tributacao_icms`, `fiscal_fonte`, `fiscal_revisado_em`
  - Colunas em `notas_entrada_itens`: `ncm_xml`, `cfop_xml`, `cest_xml`, `origem_xml`, `cst_csosn_xml`, `unidade_xml`
  - `App\Models\ProdutoFiscalDivergencia` (tabela `produto_fiscal_divergencias`)
  - `App\Models\CategoriaPadraoFiscal` (tabela `categoria_padrao_fiscal`), com `CategoriaPadraoFiscal::CATEGORIAS` (array das 7 categorias padrão)

- [ ] **Step 1: Write the migrations**

Create `backend/database/migrations/2026_07_25_000001_add_campos_fiscais_to_produtos_table.php`:

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
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable(); // NORMAL | ST
            $table->string('fiscal_fonte', 10)->nullable();    // MANUAL | XML | PADRAO
            $table->timestampTz('fiscal_revisado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn([
                'ncm', 'cest', 'origem', 'tributacao_icms', 'fiscal_fonte', 'fiscal_revisado_em',
            ]);
        });
    }
};
```

Create `backend/database/migrations/2026_07_25_000002_add_campos_fiscais_to_notas_entrada_itens_table.php`:

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
        Schema::table('notas_entrada_itens', function (Blueprint $table) {
            $table->string('ncm_xml', 8)->nullable();
            $table->string('cfop_xml', 4)->nullable();
            $table->string('cest_xml', 7)->nullable();
            $table->smallInteger('origem_xml')->nullable();
            $table->string('cst_csosn_xml', 4)->nullable();
            $table->string('unidade_xml', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notas_entrada_itens', function (Blueprint $table) {
            $table->dropColumn([
                'ncm_xml', 'cfop_xml', 'cest_xml', 'origem_xml', 'cst_csosn_xml', 'unidade_xml',
            ]);
        });
    }
};
```

Create `backend/database/migrations/2026_07_25_000003_create_produto_fiscal_divergencias_table.php`:

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
        Schema::create('produto_fiscal_divergencias', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->uuid('produto_id');
            $table->uuid('nota_entrada_id')->nullable();
            $table->string('campo', 20);           // ncm | cest | origem | tributacao_icms
            $table->string('valor_atual', 20)->nullable();
            $table->string('valor_xml', 20)->nullable();
            $table->timestampTz('criado_em')->useCurrent();
            $table->timestampTz('resolvido_em')->nullable();
            $table->string('resolucao', 12)->nullable(); // MANTEVE | ACEITOU_XML

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
            $table->foreign('nota_entrada_id')->references('id')->on('notas_entrada')->onDelete('set null');

            $table->index(['oficina_id', 'resolvido_em']);
            $table->index('produto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_fiscal_divergencias');
    }
};
```

Create `backend/database/migrations/2026_07_25_000004_create_categoria_padrao_fiscal_table.php`:

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
        Schema::create('categoria_padrao_fiscal', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->string('categoria', 40);
            $table->string('ncm', 8)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable();
            $table->timestampTz('criado_em')->useCurrent();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->unique(['oficina_id', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categoria_padrao_fiscal');
    }
};
```

- [ ] **Step 2: Write the models**

Create `backend/app/Models/ProdutoFiscalDivergencia.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProdutoFiscalDivergencia extends Model
{
    use HasTenantScope;

    protected $table = 'produto_fiscal_divergencias';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'oficina_id', 'produto_id', 'nota_entrada_id',
        'campo', 'valor_atual', 'valor_xml', 'resolvido_em', 'resolucao',
    ];

    protected $casts = [
        'criado_em'    => 'datetime',
        'resolvido_em' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function notaEntrada(): BelongsTo
    {
        return $this->belongsTo(NotaEntrada::class, 'nota_entrada_id');
    }
}
```

Create `backend/app/Models/CategoriaPadraoFiscal.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Tenancy\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CategoriaPadraoFiscal extends Model
{
    use HasTenantScope;

    /**
     * Categorias padrão do sistema. DEVE bater com a lista do select de
     * categoria em frontend/components/forms/ProdutoForm.tsx — se divergir,
     * uma categoria fica sem padrão fiscal possível.
     */
    public const CATEGORIAS = [
        'Filtros', 'Óleo/Fluidos', 'Freios', 'Suspensão', 'Elétrica', 'Motor', 'Outros',
    ];

    protected $table = 'categoria_padrao_fiscal';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'oficina_id', 'categoria', 'ncm', 'origem', 'tributacao_icms',
    ];

    protected $casts = [
        'criado_em' => 'datetime',
        'origem'    => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
```

- [ ] **Step 3: Update `Produto` and `NotaEntradaItem` fillable**

Em `backend/app/Models/Produto.php`, substitua o array `$fillable` (linhas 23-26) por:

```php
    protected $fillable = [
        'nome', 'sku', 'codigo_barras', 'categoria', 'unidade',
        'qty_atual', 'qty_minima', 'preco_custo', 'preco_venda', 'ativo', 'oficina_id',
        'ncm', 'cest', 'origem', 'tributacao_icms', 'fiscal_fonte', 'fiscal_revisado_em',
    ];
```

E o array `$casts` (linhas 28-33) por:

```php
    protected $casts = [
        'ativo'              => 'boolean',
        'criado_em'          => 'datetime',
        'preco_custo'        => 'float',
        'preco_venda'        => 'float',
        'origem'             => 'integer',
        'fiscal_revisado_em' => 'datetime',
    ];
```

Em `backend/app/Models/NotaEntradaItem.php`, acrescente ao `$fillable` existente:

```php
        'ncm_xml', 'cfop_xml', 'cest_xml', 'origem_xml', 'cst_csosn_xml', 'unidade_xml',
```

- [ ] **Step 4: Verify the migrations are syntactically valid**

Run:
```bash
cd backend && for f in database/migrations/2026_07_25_*.php app/Models/ProdutoFiscalDivergencia.php app/Models/CategoriaPadraoFiscal.php app/Models/Produto.php app/Models/NotaEntradaItem.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` para cada arquivo.

**Nota:** `php artisan migrate` **não roda localmente** — não há Postgres nesta máquina. As migrations rodam sozinhas no deploy (`docker-entrypoint.sh` executa `migrate --force`).

- [ ] **Step 5: Run the existing unit suite to confirm no regression**

Run: `cd backend && php artisan test --testsuite=Unit`
Expected: PASS — nenhuma regressão nos testes existentes.

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_07_25_*.php \
        backend/app/Models/ProdutoFiscalDivergencia.php \
        backend/app/Models/CategoriaPadraoFiscal.php \
        backend/app/Models/Produto.php \
        backend/app/Models/NotaEntradaItem.php
git commit -m "feat(fiscal): schema de campos fiscais em produtos, divergencias e padroes por categoria"
```

---

### Task 4: Política de conflito e serviço de aplicação

**Files:**
- Create: `backend/app/Services/Fiscal/PoliticaConflitoFiscal.php`
- Create: `backend/app/Services/Fiscal/ProdutoFiscalService.php`
- Test: `backend/tests/Unit/Fiscal/PoliticaConflitoFiscalTest.php`

**Interfaces:**
- Consumes: `Produto`, `ProdutoFiscalDivergencia`, `CategoriaPadraoFiscal` (Task 3); `ValidadorCamposFiscais` (Task 1).
- Produces:
  - `PoliticaConflitoFiscal::{PREENCHER,NADA,DIVERGENCIA}` (strings homônimas)
  - `PoliticaConflitoFiscal::decidir(mixed $atual, mixed $doXml): string`
  - `ProdutoFiscalService::CAMPOS` = `['ncm', 'cest', 'origem', 'tributacao_icms']`
  - `ProdutoFiscalService::aplicarDoXml(Produto $p, array $fiscalXml, ?string $notaEntradaId): void`
  - `ProdutoFiscalService::aplicarPadraoCategoria(Produto $p): void`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Fiscal/PoliticaConflitoFiscalTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\PoliticaConflitoFiscal;
use PHPUnit\Framework\TestCase;

class PoliticaConflitoFiscalTest extends TestCase
{
    public function test_campo_vazio_e_preenchido_com_o_valor_do_xml(): void
    {
        $this->assertSame(PoliticaConflitoFiscal::PREENCHER, PoliticaConflitoFiscal::decidir(null, '87083090'));
        $this->assertSame(PoliticaConflitoFiscal::PREENCHER, PoliticaConflitoFiscal::decidir('', '87083090'));
    }

    public function test_valores_iguais_nao_fazem_nada(): void
    {
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir('87083090', '87083090'));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir(0, '0'));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir('ST', 'ST'));
    }

    public function test_valores_diferentes_geram_divergencia(): void
    {
        $this->assertSame(PoliticaConflitoFiscal::DIVERGENCIA, PoliticaConflitoFiscal::decidir('87083090', '84212300'));
        $this->assertSame(PoliticaConflitoFiscal::DIVERGENCIA, PoliticaConflitoFiscal::decidir('NORMAL', 'ST'));
        $this->assertSame(PoliticaConflitoFiscal::DIVERGENCIA, PoliticaConflitoFiscal::decidir(0, 1));
    }

    public function test_xml_sem_valor_nunca_apaga_o_cadastro(): void
    {
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir('87083090', null));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir('87083090', ''));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir(null, null));
    }

    public function test_origem_zero_e_valor_valido_e_nao_conta_como_vazio(): void
    {
        // 0 é origem válida (mercadoria nacional). Se caísse em empty(), o
        // produto com origem 0 seria tratado como sem origem para sempre.
        $this->assertSame(PoliticaConflitoFiscal::DIVERGENCIA, PoliticaConflitoFiscal::decidir(0, 2));
        $this->assertSame(PoliticaConflitoFiscal::NADA, PoliticaConflitoFiscal::decidir(0, null));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test --filter=PoliticaConflitoFiscalTest`
Expected: FAIL — `Class "App\Services\Fiscal\PoliticaConflitoFiscal" not found`

- [ ] **Step 3: Implement `PoliticaConflitoFiscal`**

Create `backend/app/Services/Fiscal/PoliticaConflitoFiscal.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Decide o que fazer quando o XML do fornecedor traz um valor fiscal para
 * um campo do produto.
 *
 * Divergência NUNCA sobrescreve: protege correção feita de propósito, sem
 * esconder que existe conflito. Fornecedores erram classificação com
 * frequência — e às vezes quem está certo é o fornecedor, por isso o
 * conflito vira registro para decisão humana em vez de ser descartado.
 */
final class PoliticaConflitoFiscal
{
    public const PREENCHER   = 'PREENCHER';
    public const NADA        = 'NADA';
    public const DIVERGENCIA = 'DIVERGENCIA';

    public static function decidir(mixed $atual, mixed $doXml): string
    {
        if (self::vazio($doXml)) return self::NADA;
        if (self::vazio($atual)) return self::PREENCHER;

        return ((string) $atual === (string) $doXml) ? self::NADA : self::DIVERGENCIA;
    }

    /**
     * Cuidado: `empty()` trataria 0 como vazio, e 0 é origem VÁLIDA
     * (mercadoria nacional) — o produto ficaria eternamente "sem origem".
     */
    private static function vazio(mixed $valor): bool
    {
        return $valor === null || $valor === '';
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php artisan test --filter=PoliticaConflitoFiscalTest`
Expected: PASS — 5 testes verdes.

- [ ] **Step 5: Implement `ProdutoFiscalService`**

Create `backend/app/Services/Fiscal/ProdutoFiscalService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\CategoriaPadraoFiscal;
use App\Models\Produto;
use App\Models\ProdutoFiscalDivergencia;
use App\Tenancy\TenancyContext;

class ProdutoFiscalService
{
    /** Campos do produto que a importação de XML pode preencher. CFOP não entra: é da operação. */
    public const CAMPOS = ['ncm', 'cest', 'origem', 'tributacao_icms'];

    /** Mapeia campo do produto → chave correspondente no item do parser. */
    private const ORIGEM_XML = [
        'ncm'             => 'ncm',
        'cest'            => 'cest',
        'origem'          => 'origem',
        'tributacao_icms' => 'tributacao_icms',
    ];

    /**
     * Aplica os dados fiscais do XML ao produto.
     *
     * Nunca lança: a importação existe para dar entrada em estoque e não
     * pode ser derrubada por dado fiscal ausente ou estranho.
     *
     * @param array<string, mixed> $fiscalXml item devolvido por NotaEntradaXmlParser
     */
    public function aplicarDoXml(Produto $produto, array $fiscalXml, ?string $notaEntradaId): void
    {
        $paraGravar = [];

        foreach (self::CAMPOS as $campo) {
            $doXml = $fiscalXml[self::ORIGEM_XML[$campo]] ?? null;
            $atual = $produto->{$campo};

            switch (PoliticaConflitoFiscal::decidir($atual, $doXml)) {
                case PoliticaConflitoFiscal::PREENCHER:
                    $paraGravar[$campo] = $doXml;
                    break;

                case PoliticaConflitoFiscal::DIVERGENCIA:
                    $this->registrarDivergencia($produto, $campo, $atual, $doXml, $notaEntradaId);
                    break;

                case PoliticaConflitoFiscal::NADA:
                    break;
            }
        }

        if ($paraGravar !== []) {
            $paraGravar['fiscal_fonte'] = 'XML';
            $produto->update($paraGravar);
        }
    }

    /**
     * Preenche os campos ainda vazios do produto com o padrão da categoria.
     * Marca a fonte como PADRAO — é um chute assistido, não um dado
     * conferido, e a tela de pendências precisa saber a diferença.
     */
    public function aplicarPadraoCategoria(Produto $produto): void
    {
        $padrao = CategoriaPadraoFiscal::where('categoria', $produto->categoria)->first();
        if (!$padrao) {
            return;
        }

        $paraGravar = [];
        foreach (['ncm', 'origem', 'tributacao_icms'] as $campo) {
            if ($produto->{$campo} === null && $padrao->{$campo} !== null) {
                $paraGravar[$campo] = $padrao->{$campo};
            }
        }

        if ($paraGravar !== []) {
            $paraGravar['fiscal_fonte'] = 'PADRAO';
            $produto->update($paraGravar);
        }
    }

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
}
```

- [ ] **Step 6: Verify syntax and run the unit suite**

Run:
```bash
cd backend && php -l app/Services/Fiscal/PoliticaConflitoFiscal.php \
  && php -l app/Services/Fiscal/ProdutoFiscalService.php \
  && php artisan test --testsuite=Unit
```
Expected: `No syntax errors detected` e suíte Unit inteira verde.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/Fiscal/PoliticaConflitoFiscal.php \
        backend/app/Services/Fiscal/ProdutoFiscalService.php \
        backend/tests/Unit/Fiscal/PoliticaConflitoFiscalTest.php
git commit -m "feat(fiscal): politica de conflito e servico de aplicacao de dados fiscais ao produto"
```

---

### Task 5: Importação de XML aplica os dados fiscais

**Files:**
- Modify: `backend/app/Http/Controllers/EntradaNfController.php:40-78` (`parse`), `:100-186` (`store`)

**Interfaces:**
- Consumes: `ProdutoFiscalService::aplicarDoXml()` (Task 4); chaves fiscais do item do parser (Task 2); colunas `*_xml` de `notas_entrada_itens` (Task 3).
- Produces: cada item de `POST /entradas-nf/parse` passa a devolver `ncm`, `cfop`, `cest`, `origem`, `cst_csosn`, `tributacao_icms` e `fiscal_pendente` (bool). `POST /entradas-nf` passa a aceitar essas chaves por item.

- [ ] **Step 1: Adicionar os dados fiscais à resposta de `parse()`**

Em `backend/app/Http/Controllers/EntradaNfController.php`, dentro do `array_map` de `parse()`, o `return` do ramo **com produto encontrado** (linhas 46-59) passa a ser:

```php
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
                ];
            }
```

E o `return` do ramo **sem produto** (linhas 64-77):

```php
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
            ];
```

Note que a unidade do produto novo passa a vir do XML (`uCom`) em vez de ser sempre `'Un'`.

- [ ] **Step 2: Aceitar os campos fiscais na validação de `store()`**

Acrescente ao array de regras em `store()` (depois de `'itens.*.qty_minima'`):

```php
            'itens.*.ncm'             => ['nullable', 'string', 'max:8'],
            'itens.*.cfop'            => ['nullable', 'string', 'max:4'],
            'itens.*.cest'            => ['nullable', 'string', 'max:7'],
            'itens.*.origem'          => ['nullable', 'integer', 'min:0', 'max:8'],
            'itens.*.cst_csosn'       => ['nullable', 'string', 'max:4'],
            'itens.*.tributacao_icms' => ['nullable', 'string', 'in:NORMAL,ST'],
```

- [ ] **Step 3: Injetar o serviço e aplicar os dados no `store()`**

Adicione o import no topo do arquivo:

```php
use App\Services\Fiscal\ProdutoFiscalService;
```

Troque a assinatura de `store()`:

```php
    public function store(
        Request $request,
        EstoqueService $estoqueService,
        PlanLimitService $planLimit,
        ProdutoFiscalService $fiscalService,
    ): JsonResponse
```

Inclua `$fiscalService` no `use (...)` da closure da transação:

```php
        $nota = DB::transaction(function () use ($validated, $estoqueService, $planLimit, $atualizarCusto, $usuarioId, $fiscalService) {
```

Dentro do `foreach ($validated['itens'] as $item)`, o ramo de **produto novo** passa a gravar os campos fiscais direto (produto recém-criado nunca tem conflito):

```php
                } else {
                    $planLimit->verificarLimiteProdutos();
                    $produto = Produto::create([
                        'nome'            => $item['nome'],
                        'sku'             => strtoupper(Str::random(8)),
                        'codigo_barras'   => $item['codigo_barras'] ?? null,
                        'categoria'       => $item['categoria'],
                        'unidade'         => $item['unidade'] ?? 'Un',
                        'qty_atual'       => 0,
                        'qty_minima'      => $item['qty_minima'] ?? 5,
                        'preco_custo'     => $item['valor_unitario'],
                        'preco_venda'     => $item['preco_venda'] ?? $item['valor_unitario'],
                        'ncm'             => $item['ncm'] ?? null,
                        'cest'            => $item['cest'] ?? null,
                        'origem'          => $item['origem'] ?? null,
                        'tributacao_icms' => $item['tributacao_icms'] ?? null,
                        'fiscal_fonte'    => isset($item['ncm']) ? 'XML' : null,
                    ]);
                    $produtoCriado = true;
                }
```

**A aplicação da política de conflito acontece FORA da transação.** Motivo: a
restrição global diz que a importação nunca pode falhar inteira por causa de
dado fiscal, e no PostgreSQL um `try/catch` dentro de transação não salva nada
— o primeiro erro aborta a transação e todo comando seguinte falha até o
rollback. Envolver a gravação fiscal em `try/catch` dentro do `DB::transaction`
daria falsa sensação de segurança e derrubaria a entrada de estoque junto.

Declare o acumulador **antes** do `DB::transaction(...)`:

```php
        $pendenteDeAplicacaoFiscal = [];
```

Inclua-o no `use (...)` da closure **por referência**:

```php
        $nota = DB::transaction(function () use ($validated, $estoqueService, $planLimit, $atualizarCusto, $usuarioId, &$pendenteDeAplicacaoFiscal) {
```

(`$fiscalService` sai do `use` — ele não é mais usado dentro da transação.)

Ainda dentro do `foreach`, logo **antes** da chamada a
`$estoqueService->registrarEntradaItem(...)`, apenas registre a intenção:

```php
                $pendenteDeAplicacaoFiscal[] = [
                    'produto_id' => $produto->id,
                    'item'       => $item,
                    'novo'       => $produtoCriado,
                ];
```

E **depois** que `DB::transaction(...)` retornar — antes do `return` da
resposta — aplique de fato:

```php
        foreach ($pendenteDeAplicacaoFiscal as $pendente) {
            try {
                $produto = Produto::find($pendente['produto_id']);
                if (!$produto) {
                    continue;
                }

                if ($pendente['novo']) {
                    $fiscalService->aplicarPadraoCategoria($produto);
                } else {
                    $fiscalService->aplicarDoXml($produto, $pendente['item'], $nota->id);
                }
            } catch (\Throwable $e) {
                // A entrada de estoque já está commitada e é o que importa.
                // Dado fiscal que falhou vira pendência na tela, não erro pro usuário.
                \Illuminate\Support\Facades\Log::warning(
                    'Falha ao aplicar dados fiscais na entrada de NF: ' . $e->getMessage(),
                    ['produto_id' => $pendente['produto_id'], 'nota_entrada_id' => $nota->id],
                );
            }
        }
```

Por fim, a criação do `NotaEntradaItem` passa a arquivar o valor bruto do fornecedor:

```php
                NotaEntradaItem::create([
                    'nota_entrada_id'   => $nota->id,
                    'produto_id'        => $produto->id,
                    'codigo_barras_xml' => $item['codigo_barras'] ?? null,
                    'descricao_xml'     => $item['nome'] ?? $produto->nome,
                    'quantidade'        => $item['quantidade'],
                    'valor_unitario'    => $item['valor_unitario'],
                    'produto_criado'    => $produtoCriado,
                    'ncm_xml'           => $item['ncm'] ?? null,
                    'cfop_xml'          => $item['cfop'] ?? null,
                    'cest_xml'          => $item['cest'] ?? null,
                    'origem_xml'        => $item['origem'] ?? null,
                    'cst_csosn_xml'     => $item['cst_csosn'] ?? null,
                    'unidade_xml'       => $item['unidade'] ?? null,
                ]);
```

- [ ] **Step 4: Verify syntax and run the unit suite**

Run: `cd backend && php -l app/Http/Controllers/EntradaNfController.php && php artisan test --testsuite=Unit`
Expected: `No syntax errors detected` e suíte Unit verde.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/EntradaNfController.php
git commit -m "feat(fiscal): importacao de NF-e aplica dados fiscais ao produto e arquiva o valor do fornecedor"
```

---

### Task 6: Campos fiscais no CRUD de produto

**Files:**
- Modify: `backend/app/Http/Controllers/ProdutoController.php` (`store`, `update`)
- Modify: `backend/app/Http/Resources/ProdutoResource.php`

**Interfaces:**
- Consumes: colunas de `produtos` (Task 3); `ProdutoFiscalService::aplicarPadraoCategoria()` (Task 4).
- Produces: `ProdutoResource` passa a expor `ncm`, `cest`, `origem`, `tributacao_icms`, `fiscal_fonte`, `fiscal_revisado_em` e `fiscal_pendente` (bool). `POST`/`PUT /produtos` aceitam os quatro campos editáveis.

- [ ] **Step 1: Expor os campos no Resource**

Em `backend/app/Http/Resources/ProdutoResource.php`, insira antes de `'criado_em'`:

```php
            'ncm'                => $this->ncm,
            'cest'               => $this->cest,
            'origem'             => $this->origem,
            'tributacao_icms'    => $this->tributacao_icms,
            'fiscal_fonte'       => $this->fiscal_fonte,
            'fiscal_revisado_em' => $this->fiscal_revisado_em?->format('d/m/Y H:i'),
            'fiscal_pendente'    => $this->ncm === null || $this->fiscal_fonte === 'PADRAO',
```

- [ ] **Step 2: Aceitar os campos em `store` e `update`**

`store()` e `update()` precisam exatamente das mesmas regras fiscais e do mesmo
carimbo de revisão. **Extraia os dois blocos para métodos privados** em vez de
duplicá-los — duplicação verbatim de bloco lógico é defeito de revisão.

Em `backend/app/Http/Controllers/ProdutoController.php`, adicione o import:

```php
use App\Services\Fiscal\ProdutoFiscalService;
```

Adicione os dois métodos privados antes do `}` que fecha a classe:

```php
    /** @return array<string, list<string>> Regras de validação dos campos fiscais editáveis. */
    private function regrasFiscais(): array
    {
        return [
            'ncm'             => ['nullable', 'string', 'size:8'],
            'cest'            => ['nullable', 'string', 'size:7'],
            'origem'          => ['nullable', 'integer', 'min:0', 'max:8'],
            'tributacao_icms' => ['nullable', 'string', 'in:NORMAL,ST'],
        ];
    }

    /**
     * Carimba a revisão manual quando o usuário enviou algum campo fiscal.
     * É o carimbo que tira o produto da tela de pendências.
     *
     * @param array<string, mixed> $validated
     */
    private function carimbarRevisaoFiscal(Produto $produto, array $validated): void
    {
        $camposFiscais = array_keys($this->regrasFiscais());

        if (array_intersect_key($validated, array_flip($camposFiscais)) === []) {
            return;
        }

        $produto->update([
            'fiscal_fonte'       => 'MANUAL',
            'fiscal_revisado_em' => now(),
        ]);
    }
```

Em `store()` **e** em `update()`, mescle as regras no array passado a
`$request->validate(...)`:

```php
        $validated = $request->validate(array_merge([
            // ... regras existentes do método, inalteradas ...
        ], $this->regrasFiscais()));
```

Em **ambos** os métodos, depois de criar/atualizar o produto e antes de
devolver a resposta:

```php
        $this->carimbarRevisaoFiscal($produto, $validated);
```

E em `store()` apenas, logo após a criação do produto (antes do carimbo),
aplique o padrão da categoria — só preenche o que ficou vazio:

```php
        app(ProdutoFiscalService::class)->aplicarPadraoCategoria($produto);
```

- [ ] **Step 3: Verify syntax**

Run: `cd backend && php -l app/Http/Controllers/ProdutoController.php && php -l app/Http/Resources/ProdutoResource.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/ProdutoController.php \
        backend/app/Http/Resources/ProdutoResource.php
git commit -m "feat(fiscal): campos fiscais no CRUD de produto e no resource"
```

---

### Task 7: Endpoints de padrões por categoria

**Files:**
- Create: `backend/app/Http/Controllers/CategoriaPadraoFiscalController.php`
- Modify: `backend/routes/api.php:226-243` (grupos de Produtos)

**Interfaces:**
- Consumes: `CategoriaPadraoFiscal` + `CategoriaPadraoFiscal::CATEGORIAS` (Task 3).
- Produces:
  - `GET /categorias-fiscais` → `{ "data": [ { "categoria", "ncm", "origem", "tributacao_icms" } ] }`, sempre com as 7 categorias, valores `null` quando nunca configuradas.
  - `PUT /categorias-fiscais` → recebe `{ "categorias": [ { "categoria", "ncm", "origem", "tributacao_icms" } ] }`.

**Decisão de implementação:** as 7 linhas não são semeadas por migration. O `GET` faz merge da constante com o que existe no banco, e o `PUT` faz `updateOrCreate`. Efeito idêntico ao "semeado com valores em branco" do spec, sem precisar tocar na criação de oficina nem migrar oficinas existentes.

- [ ] **Step 1: Write the controller**

Create `backend/app/Http/Controllers/CategoriaPadraoFiscalController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CategoriaPadraoFiscal;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoriaPadraoFiscalController extends Controller
{
    public function index(): JsonResponse
    {
        $salvas = CategoriaPadraoFiscal::get()->keyBy('categoria');

        $data = array_map(static function (string $categoria) use ($salvas): array {
            $linha = $salvas->get($categoria);

            return [
                'categoria'       => $categoria,
                'ncm'             => $linha->ncm ?? null,
                'origem'          => $linha->origem ?? null,
                'tributacao_icms' => $linha->tributacao_icms ?? null,
            ];
        }, CategoriaPadraoFiscal::CATEGORIAS);

        return response()->json(['data' => $data]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categorias'                   => ['required', 'array', 'min:1'],
            'categorias.*.categoria'       => ['required', 'string', 'max:40'],
            'categorias.*.ncm'             => ['nullable', 'string', 'size:8'],
            'categorias.*.origem'          => ['nullable', 'integer', 'min:0', 'max:8'],
            'categorias.*.tributacao_icms' => ['nullable', 'string', 'in:NORMAL,ST'],
        ]);

        foreach ($validated['categorias'] as $linha) {
            CategoriaPadraoFiscal::updateOrCreate(
                [
                    'oficina_id' => TenancyContext::get(),
                    'categoria'  => $linha['categoria'],
                ],
                [
                    'ncm'             => $linha['ncm'] ?? null,
                    'origem'          => $linha['origem'] ?? null,
                    'tributacao_icms' => $linha['tributacao_icms'] ?? null,
                ],
            );
        }

        return $this->index();
    }
}
```

- [ ] **Step 2: Register the routes**

Em `backend/routes/api.php`, adicione o import junto aos outros controllers:

```php
use App\Http\Controllers\CategoriaPadraoFiscalController;
```

No grupo de **leitura** de Produtos (`['tenant', 'auth:sanctum']`, linha ~227), adicione:

```php
    Route::get('categorias-fiscais', [CategoriaPadraoFiscalController::class, 'index']);
```

No grupo de **escrita** (`['tenant', 'auth:sanctum', 'role:ADMIN,ATENDENTE']`, linha ~234), adicione:

```php
    Route::put('categorias-fiscais', [CategoriaPadraoFiscalController::class, 'update']);
```

- [ ] **Step 3: Verify syntax and route registration**

Run:
```bash
cd backend && php -l app/Http/Controllers/CategoriaPadraoFiscalController.php \
  && php artisan route:list --path=categorias-fiscais
```
Expected: sem erro de sintaxe, e duas rotas listadas (`GET` e `PUT api/categorias-fiscais`).

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/CategoriaPadraoFiscalController.php \
        backend/routes/api.php
git commit -m "feat(fiscal): endpoints de padrao fiscal por categoria"
```

---

### Task 8: Endpoints de pendências e resolução de divergência

**Files:**
- Create: `backend/app/Http/Controllers/ProdutoFiscalController.php`
- Modify: `backend/routes/api.php` (grupos de Produtos)

**Interfaces:**
- Consumes: `Produto`, `ProdutoFiscalDivergencia` (Task 3); `ProdutoResource` com `fiscal_pendente` (Task 6).
- Produces:
  - `GET /produtos/pendencias-fiscais` → `{ "data": [...produtos...], "divergencias": [...], "total": int }`
  - `POST /produtos/divergencias/{id}/resolver` — body `{ "resolucao": "MANTEVE" | "ACEITOU_XML" }`

- [ ] **Step 1: Write the controller**

Create `backend/app/Http/Controllers/ProdutoFiscalController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Produto;
use App\Models\ProdutoFiscalDivergencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProdutoFiscalController extends Controller
{
    /**
     * Produtos ativos que precisam de atenção fiscal: sem NCM, com valor
     * herdado do padrão da categoria (chute assistido), ou com divergência
     * aberta contra o XML de um fornecedor.
     *
     * NÃO inclui "fiscal_revisado_em is null": dado vindo do XML do
     * fornecedor é confiável e não exige conferência humana. Incluir essa
     * condição manteria todo produto preenchido por importação na lista
     * para sempre, esvaziando o sentido da tela.
     */
    public function pendencias(): JsonResponse
    {
        $comDivergencia = ProdutoFiscalDivergencia::whereNull('resolvido_em')
            ->pluck('produto_id')
            ->unique()
            ->all();

        $produtos = Produto::where('ativo', true)
            ->where(function ($q) use ($comDivergencia) {
                $q->whereNull('ncm')
                  ->orWhere('fiscal_fonte', 'PADRAO')
                  ->orWhereIn('id', $comDivergencia);
            })
            ->orderBy('nome')
            ->get();

        $divergencias = ProdutoFiscalDivergencia::with('produto:id,nome')
            ->whereNull('resolvido_em')
            ->orderByDesc('criado_em')
            ->get()
            ->map(fn (ProdutoFiscalDivergencia $d) => [
                'id'           => $d->id,
                'produto_id'   => $d->produto_id,
                'produto_nome' => $d->produto?->nome,
                'campo'        => $d->campo,
                'valor_atual'  => $d->valor_atual,
                'valor_xml'    => $d->valor_xml,
                'criado_em'    => $d->criado_em?->format('d/m/Y H:i'),
            ]);

        return response()->json([
            'data'         => ProdutoResource::collection($produtos)->resolve(),
            'divergencias' => $divergencias,
            'total'        => $produtos->count(),
        ]);
    }

    public function resolverDivergencia(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'resolucao' => ['required', 'string', 'in:MANTEVE,ACEITOU_XML'],
        ]);

        DB::transaction(function () use ($id, $validated) {
            $divergencia = ProdutoFiscalDivergencia::whereNull('resolvido_em')
                ->lockForUpdate()
                ->findOrFail($id);

            if ($validated['resolucao'] === 'ACEITOU_XML') {
                $divergencia->produto?->update([
                    $divergencia->campo  => $divergencia->valor_xml,
                    'fiscal_fonte'       => 'XML',
                    'fiscal_revisado_em' => now(),
                ]);
            } else {
                $divergencia->produto?->update(['fiscal_revisado_em' => now()]);
            }

            $divergencia->update([
                'resolvido_em' => now(),
                'resolucao'    => $validated['resolucao'],
            ]);
        });

        return response()->json(['message' => 'Divergência resolvida.']);
    }
}
```

- [ ] **Step 2: Register the routes**

Em `backend/routes/api.php`, adicione o import:

```php
use App\Http\Controllers\ProdutoFiscalController;
```

No grupo de **leitura** de Produtos, adicione **antes** da rota `produtos/{produto}` (senão `pendencias-fiscais` seria capturado como um `{produto}`):

```php
    Route::get('produtos/pendencias-fiscais', [ProdutoFiscalController::class, 'pendencias']);
```

No grupo de **escrita**:

```php
    Route::post('produtos/divergencias/{id}/resolver', [ProdutoFiscalController::class, 'resolverDivergencia']);
```

- [ ] **Step 3: Verify syntax and route order**

Run:
```bash
cd backend && php -l app/Http/Controllers/ProdutoFiscalController.php \
  && php artisan route:list --path=produtos
```
Expected: sem erro de sintaxe; `produtos/pendencias-fiscais` aparece **acima** de `produtos/{produto}` na listagem.

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/ProdutoFiscalController.php \
        backend/routes/api.php
git commit -m "feat(fiscal): endpoints de pendencias fiscais e resolucao de divergencia"
```

---

### Task 9: Bloco de dados fiscais no formulário de produto

**Files:**
- Modify: `frontend/components/forms/ProdutoForm.tsx`

**Interfaces:**
- Consumes: `ProdutoResource` com campos fiscais (Task 6); `PUT/POST /produtos` aceitando-os (Task 6).
- Produces: nada consumido por tasks posteriores.

- [ ] **Step 1: Estender o schema Zod**

Em `frontend/components/forms/ProdutoForm.tsx`, dentro do objeto passado a `z.object({...})`, acrescente:

```ts
  ncm: z.string().regex(/^\d{8}$/, 'NCM deve ter 8 dígitos').optional().or(z.literal('')),
  cest: z.string().regex(/^\d{7}$/, 'CEST deve ter 7 dígitos').optional().or(z.literal('')),
  origem: z.coerce.number().int().min(0).max(8).optional(),
  tributacao_icms: z.enum(['NORMAL', 'ST']).optional().or(z.literal('')),
```

- [ ] **Step 2: Adicionar o bloco de campos**

Adicione, depois do campo "Preço de venda" e antes do fechamento do formulário:

```tsx
        <div style={{ gridColumn: '1 / -1', borderTop: '1px solid var(--border)', paddingTop: 16, marginTop: 8 }}>
          <h3 style={{ fontFamily: 'Barlow Condensed', fontWeight: 700, fontSize: 15, color: 'var(--text)', margin: '0 0 4px' }}>
            Dados fiscais
          </h3>
          <p style={{ fontSize: 12, color: 'var(--muted)', margin: '0 0 12px' }}>
            Necessários para emitir NF-e desta peça. Ao importar a nota do fornecedor, são preenchidos automaticamente.
          </p>
        </div>

        <div>
          <label style={lStyle}>NCM (8 dígitos)</label>
          <input {...register('ncm')} placeholder="87083090" maxLength={8} style={iStyle} />
          {errors.ncm && <span style={eStyle}>{errors.ncm.message}</span>}
          {produto?.fiscal_fonte && (
            <span style={{ fontSize: 11, color: 'var(--muted)' }}>
              {produto.fiscal_fonte === 'XML' && 'Veio da NF-e do fornecedor'}
              {produto.fiscal_fonte === 'PADRAO' && 'Padrão da categoria — revise'}
              {produto.fiscal_fonte === 'MANUAL' && `Preenchido manualmente${produto.fiscal_revisado_em ? ` em ${produto.fiscal_revisado_em}` : ''}`}
            </span>
          )}
        </div>

        <div>
          <label style={lStyle}>CEST (7 dígitos)</label>
          <input {...register('cest')} placeholder="0100100" maxLength={7} style={iStyle} />
          {errors.cest && <span style={eStyle}>{errors.cest.message}</span>}
        </div>

        <div>
          <label style={lStyle}>Origem da mercadoria</label>
          <select {...register('origem')} style={iStyle}>
            <option value="">Selecione</option>
            <option value="0">0 — Nacional</option>
            <option value="1">1 — Estrangeira, importação direta</option>
            <option value="2">2 — Estrangeira, adquirida no mercado interno</option>
            <option value="3">3 — Nacional, conteúdo de importação &gt; 40% e ≤ 70%</option>
            <option value="4">4 — Nacional, processos produtivos básicos</option>
            <option value="5">5 — Nacional, conteúdo de importação ≤ 40%</option>
            <option value="6">6 — Estrangeira, importação direta sem similar nacional</option>
            <option value="7">7 — Estrangeira, mercado interno sem similar nacional</option>
            <option value="8">8 — Nacional, conteúdo de importação &gt; 70%</option>
          </select>
        </div>

        <div>
          <label style={lStyle}>Tributação do ICMS</label>
          <select {...register('tributacao_icms')} style={iStyle}>
            <option value="">Selecione</option>
            <option value="NORMAL">Normal</option>
            <option value="ST">Substituição tributária</option>
          </select>
        </div>
```

`eStyle` pode não existir no arquivo. Se não existir, declare-o junto de `iStyle`/`lStyle`:

```ts
const eStyle: React.CSSProperties = { fontSize: 11, color: 'var(--danger)' }
```

- [ ] **Step 3: Estender a interface de props**

No `interface ProdutoFormProps`, o objeto `produto` passa a incluir:

```ts
  ncm?: string | null
  cest?: string | null
  origem?: number | null
  tributacao_icms?: string | null
  fiscal_fonte?: string | null
  fiscal_revisado_em?: string | null
```

- [ ] **Step 4: Verify the build**

Run: `cd frontend && npx tsc --noEmit`
Expected: sem erros.

- [ ] **Step 5: Commit**

```bash
git add frontend/components/forms/ProdutoForm.tsx
git commit -m "feat(fiscal): bloco de dados fiscais no formulario de produto"
```

---

### Task 10: Tela de pendências fiscais

**Files:**
- Create: `frontend/app/(dashboard)/produtos/pendencias-fiscais/page.tsx`
- Modify: `frontend/app/(dashboard)/produtos/page.tsx` (link para a nova tela, com contagem)

**Interfaces:**
- Consumes: `GET /produtos/pendencias-fiscais` e `POST /produtos/divergencias/{id}/resolver` (Task 8).
- Produces: nada.

- [ ] **Step 1: Criar a página**

Create `frontend/app/(dashboard)/produtos/pendencias-fiscais/page.tsx`:

```tsx
'use client'

import { useEffect, useState } from 'react'
import Link from 'next/link'
import api from '@/lib/api'

interface ProdutoPendente {
  id: string
  nome: string
  sku: string
  categoria: string
  ncm: string | null
  fiscal_fonte: string | null
  fiscal_revisado_em: string | null
}

interface Divergencia {
  id: string
  produto_id: string
  produto_nome: string | null
  campo: string
  valor_atual: string | null
  valor_xml: string | null
  criado_em: string | null
}

export default function PendenciasFiscaisPage() {
  const [produtos, setProdutos] = useState<ProdutoPendente[]>([])
  const [divergencias, setDivergencias] = useState<Divergencia[]>([])
  const [carregando, setCarregando] = useState(true)
  const [resolvendo, setResolvendo] = useState<string | null>(null)

  async function carregar() {
    setCarregando(true)
    try {
      const { data } = await api.get('/produtos/pendencias-fiscais')
      setProdutos(data.data ?? [])
      setDivergencias(data.divergencias ?? [])
    } finally {
      setCarregando(false)
    }
  }

  useEffect(() => { carregar() }, [])

  async function resolver(id: string, resolucao: 'MANTEVE' | 'ACEITOU_XML') {
    setResolvendo(id)
    try {
      await api.post(`/produtos/divergencias/${id}/resolver`, { resolucao })
      await carregar()
    } finally {
      setResolvendo(null)
    }
  }

  function situacao(p: ProdutoPendente): { texto: string; cor: string } {
    if (!p.ncm) return { texto: 'Sem NCM', cor: 'var(--danger)' }
    if (p.fiscal_fonte === 'PADRAO') return { texto: 'Padrão da categoria', cor: 'var(--accent)' }
    return { texto: 'Não revisado', cor: 'var(--accent)' }
  }

  if (carregando) {
    return <div style={{ padding: 24, color: 'var(--muted)' }}>Carregando pendências…</div>
  }

  return (
    <div style={{ padding: 24 }}>
      <p style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 20 }}>
        Produtos sem dado fiscal completo não podem ser incluídos em NF-e. Ao importar a nota do
        fornecedor por XML, esses campos são preenchidos automaticamente.
      </p>

      {divergencias.length > 0 && (
        <section style={{ marginBottom: 32 }}>
          <h2 style={{ fontFamily: 'Barlow Condensed', fontWeight: 700, fontSize: 18, marginBottom: 12 }}>
            Divergências com o fornecedor ({divergencias.length})
          </h2>
          <table style={{ width: '100%', borderCollapse: 'collapse', background: 'var(--card)' }}>
            <thead>
              <tr style={{ borderBottom: '1px solid var(--border)', textAlign: 'left' }}>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Produto</th>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Campo</th>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Cadastro</th>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Fornecedor</th>
                <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Ação</th>
              </tr>
            </thead>
            <tbody>
              {divergencias.map((d) => (
                <tr key={d.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td style={{ padding: 10 }}>{d.produto_nome ?? '—'}</td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12 }}>{d.campo}</td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12 }}>{d.valor_atual ?? '—'}</td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12, color: 'var(--accent)' }}>
                    {d.valor_xml ?? '—'}
                  </td>
                  <td style={{ padding: 10, display: 'flex', gap: 8 }}>
                    <button
                      onClick={() => resolver(d.id, 'MANTEVE')}
                      disabled={resolvendo === d.id}
                      style={{ padding: '4px 10px', fontSize: 12, background: 'transparent', color: 'var(--text)', border: '1px solid var(--border)', borderRadius: 4, cursor: 'pointer' }}
                    >
                      Manter cadastro
                    </button>
                    <button
                      onClick={() => resolver(d.id, 'ACEITOU_XML')}
                      disabled={resolvendo === d.id}
                      style={{ padding: '4px 10px', fontSize: 12, background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 4, cursor: 'pointer' }}
                    >
                      Aceitar fornecedor
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      <h2 style={{ fontFamily: 'Barlow Condensed', fontWeight: 700, fontSize: 18, marginBottom: 12 }}>
        Produtos pendentes ({produtos.length})
      </h2>

      {produtos.length === 0 ? (
        <p style={{ color: 'var(--muted)' }}>Nenhuma pendência fiscal. Todos os produtos ativos estão prontos para NF-e.</p>
      ) : (
        <table style={{ width: '100%', borderCollapse: 'collapse', background: 'var(--card)' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)', textAlign: 'left' }}>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Produto</th>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>SKU</th>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Categoria</th>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>NCM</th>
              <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Situação</th>
            </tr>
          </thead>
          <tbody>
            {produtos.map((p) => {
              const s = situacao(p)
              return (
                <tr
                  key={p.id}
                  style={{
                    borderBottom: '1px solid var(--border)',
                    background: !p.ncm ? 'rgba(229,57,53,.06)' : undefined,
                  }}
                >
                  <td style={{ padding: 10 }}>
                    <Link href={`/produtos/${p.id}`} style={{ color: 'var(--text)' }}>{p.nome}</Link>
                  </td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12 }}>{p.sku}</td>
                  <td style={{ padding: 10 }}>{p.categoria}</td>
                  <td style={{ padding: 10, fontFamily: 'JetBrains Mono', fontSize: 12 }}>{p.ncm ?? '—'}</td>
                  <td style={{ padding: 10 }}>
                    <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 11, border: `1px solid ${s.cor}`, color: s.cor }}>
                      {s.texto}
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
    </div>
  )
}
```

- [ ] **Step 2: Linkar a partir da listagem de produtos**

Em `frontend/app/(dashboard)/produtos/page.tsx`, junto dos botões de ação do topo, adicione:

```tsx
<Link
  href="/produtos/pendencias-fiscais"
  style={{ padding: '8px 14px', fontSize: 13, color: 'var(--text)', border: '1px solid var(--border)', borderRadius: 6, textDecoration: 'none' }}
>
  Pendências fiscais
</Link>
```

Garanta que `import Link from 'next/link'` está presente no arquivo.

**Não adicione badge na sidebar** — "Produtos" já carrega o badge de alerta de estoque, e um segundo contador no mesmo item transforma o número num borrão sem significado.

- [ ] **Step 3: Verify the build**

Run: `cd frontend && npx tsc --noEmit && npm run build`
Expected: sem erros de tipo e build de produção concluído.

- [ ] **Step 4: Commit**

```bash
git add "frontend/app/(dashboard)/produtos/pendencias-fiscais/page.tsx" \
        "frontend/app/(dashboard)/produtos/page.tsx"
git commit -m "feat(fiscal): tela de pendencias fiscais com resolucao de divergencias"
```

---

### Task 11: Tela de padrões fiscais por categoria

**Files:**
- Create: `frontend/app/(dashboard)/configuracoes/categorias-fiscais/page.tsx`
- Modify: `frontend/app/(dashboard)/configuracoes/page.tsx` (link para a nova tela)

**Interfaces:**
- Consumes: `GET /categorias-fiscais` e `PUT /categorias-fiscais` (Task 7).
- Produces: nada.

- [ ] **Step 1: Criar a página**

Create `frontend/app/(dashboard)/configuracoes/categorias-fiscais/page.tsx`:

```tsx
'use client'

import { useEffect, useState } from 'react'
import api from '@/lib/api'

interface LinhaCategoria {
  categoria: string
  ncm: string | null
  origem: number | null
  tributacao_icms: string | null
}

export default function CategoriasFiscaisPage() {
  const [linhas, setLinhas] = useState<LinhaCategoria[]>([])
  const [carregando, setCarregando] = useState(true)
  const [salvando, setSalvando] = useState(false)
  const [mensagem, setMensagem] = useState<string | null>(null)

  useEffect(() => {
    api.get('/categorias-fiscais')
      .then(({ data }) => setLinhas(data.data ?? []))
      .finally(() => setCarregando(false))
  }, [])

  function alterar(indice: number, campo: keyof LinhaCategoria, valor: string) {
    setLinhas((atual) =>
      atual.map((linha, i) =>
        i === indice
          ? { ...linha, [campo]: campo === 'origem' ? (valor === '' ? null : Number(valor)) : (valor === '' ? null : valor) }
          : linha,
      ),
    )
  }

  async function salvar() {
    setSalvando(true)
    setMensagem(null)
    try {
      const { data } = await api.put('/categorias-fiscais', { categorias: linhas })
      setLinhas(data.data ?? [])
      setMensagem('Padrões salvos.')
    } catch {
      setMensagem('Não foi possível salvar. Confira NCM (8 dígitos) e origem (0 a 8).')
    } finally {
      setSalvando(false)
    }
  }

  if (carregando) {
    return <div style={{ padding: 24, color: 'var(--muted)' }}>Carregando…</div>
  }

  const input: React.CSSProperties = {
    width: '100%', padding: '6px 8px', background: 'var(--bg)',
    border: '1px solid var(--border)', borderRadius: 4, color: 'var(--text)', fontSize: 13,
  }

  return (
    <div style={{ padding: 24, maxWidth: 900 }}>
      <p style={{ color: 'var(--muted)', fontSize: 13, marginBottom: 20 }}>
        Valores usados como ponto de partida para produtos cadastrados manualmente, sem nota de
        entrada. Produtos importados por XML recebem o dado do próprio fornecedor e ignoram estes
        padrões. Deixe em branco o que você não souber — um NCM errado é pior que um NCM ausente.
      </p>

      <table style={{ width: '100%', borderCollapse: 'collapse', background: 'var(--card)' }}>
        <thead>
          <tr style={{ borderBottom: '1px solid var(--border)', textAlign: 'left' }}>
            <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Categoria</th>
            <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>NCM</th>
            <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Origem</th>
            <th style={{ padding: 10, fontSize: 12, color: 'var(--muted)' }}>Tributação ICMS</th>
          </tr>
        </thead>
        <tbody>
          {linhas.map((linha, i) => (
            <tr key={linha.categoria} style={{ borderBottom: '1px solid var(--border)' }}>
              <td style={{ padding: 10 }}>{linha.categoria}</td>
              <td style={{ padding: 10 }}>
                <input
                  value={linha.ncm ?? ''}
                  onChange={(e) => alterar(i, 'ncm', e.target.value)}
                  placeholder="8 dígitos"
                  maxLength={8}
                  style={input}
                />
              </td>
              <td style={{ padding: 10 }}>
                <select value={linha.origem ?? ''} onChange={(e) => alterar(i, 'origem', e.target.value)} style={input}>
                  <option value="">—</option>
                  {[0, 1, 2, 3, 4, 5, 6, 7, 8].map((n) => (
                    <option key={n} value={n}>{n}</option>
                  ))}
                </select>
              </td>
              <td style={{ padding: 10 }}>
                <select
                  value={linha.tributacao_icms ?? ''}
                  onChange={(e) => alterar(i, 'tributacao_icms', e.target.value)}
                  style={input}
                >
                  <option value="">—</option>
                  <option value="NORMAL">Normal</option>
                  <option value="ST">Substituição tributária</option>
                </select>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <div style={{ marginTop: 16, display: 'flex', alignItems: 'center', gap: 12 }}>
        <button
          onClick={salvar}
          disabled={salvando}
          style={{ padding: '9px 18px', background: 'var(--accent)', color: '#000', border: 'none', borderRadius: 6, fontFamily: 'Barlow Condensed', fontWeight: 800, fontSize: 15, cursor: salvando ? 'default' : 'pointer' }}
        >
          {salvando ? 'Salvando…' : 'Salvar padrões'}
        </button>
        {mensagem && <span style={{ fontSize: 13, color: 'var(--muted)' }}>{mensagem}</span>}
      </div>
    </div>
  )
}
```

- [ ] **Step 2: Linkar a partir de Configurações**

Em `frontend/app/(dashboard)/configuracoes/page.tsx`, adicione uma seção linkando a nova tela:

```tsx
<div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 8, padding: 16, marginTop: 16 }}>
  <h3 style={{ fontFamily: 'Barlow Condensed', fontWeight: 700, fontSize: 16, margin: '0 0 4px' }}>
    Padrões fiscais por categoria
  </h3>
  <p style={{ fontSize: 13, color: 'var(--muted)', margin: '0 0 12px' }}>
    NCM, origem e tributação usados como ponto de partida para produtos cadastrados manualmente.
  </p>
  <Link
    href="/configuracoes/categorias-fiscais"
    style={{ padding: '8px 14px', fontSize: 13, color: 'var(--text)', border: '1px solid var(--border)', borderRadius: 6, textDecoration: 'none' }}
  >
    Configurar
  </Link>
</div>
```

Garanta que `import Link from 'next/link'` está presente no arquivo.

- [ ] **Step 3: Verify the build**

Run: `cd frontend && npx tsc --noEmit && npm run build`
Expected: sem erros.

- [ ] **Step 4: Commit**

```bash
git add "frontend/app/(dashboard)/configuracoes/categorias-fiscais/page.tsx" \
        "frontend/app/(dashboard)/configuracoes/page.tsx"
git commit -m "feat(fiscal): tela de padroes fiscais por categoria"
```

---

### Task 12: Importação de XML exibe os dados fiscais captados

**Files:**
- Modify: `frontend/app/(dashboard)/produtos/entrada-nf/page.tsx`

**Interfaces:**
- Consumes: `POST /entradas-nf/parse` devolvendo os campos fiscais e `fiscal_pendente` (Task 5); `POST /entradas-nf` aceitando-os (Task 5).
- Produces: nada.

- [ ] **Step 1: Estender o tipo do item**

Localize a interface do item com:

```bash
grep -n "matched" "frontend/app/(dashboard)/produtos/entrada-nf/page.tsx"
```

Ela é a que declara `codigo_barras`, `descricao_xml`, `quantidade`, `valor_unitario` e `matched`. Acrescente a ela:

```ts
  ncm: string | null
  cfop: string | null
  cest: string | null
  origem: number | null
  cst_csosn: string | null
  tributacao_icms: string | null
  fiscal_pendente: boolean
```

- [ ] **Step 2: Exibir a coluna fiscal na tabela de conferência**

Adicione um cabeçalho `Fiscal` à tabela de itens e, na linha de cada item, a célula:

```tsx
<td style={{ padding: 8, fontSize: 12 }}>
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
```

- [ ] **Step 3: Enviar os campos fiscais no submit**

No corpo montado para `POST /entradas-nf`, cada item passa a incluir:

```ts
  ncm: item.ncm,
  cfop: item.cfop,
  cest: item.cest,
  origem: item.origem,
  cst_csosn: item.cst_csosn,
  tributacao_icms: item.tributacao_icms,
```

- [ ] **Step 4: Verify the build**

Run: `cd frontend && npx tsc --noEmit && npm run build`
Expected: sem erros.

- [ ] **Step 5: Commit**

```bash
git add "frontend/app/(dashboard)/produtos/entrada-nf/page.tsx"
git commit -m "feat(fiscal): tela de importacao de XML exibe os dados fiscais captados"
```

---

## Verificação final da etapa

Antes de considerar a etapa concluída:

- [ ] `cd backend && php artisan test --testsuite=Unit` — suíte inteira verde, incluindo os testes novos das Tasks 1, 2 e 4.
- [ ] `cd frontend && npx tsc --noEmit && npm run build` — limpos.
- [ ] **Migrations não foram executadas localmente** (não há Postgres nesta máquina). Elas rodam no deploy via `docker-entrypoint.sh`. Após o deploy, confirmar com `php artisan migrate:status` dentro do container que as quatro migrations `2026_07_25_*` constam como `Ran`.
- [ ] Validação manual após deploy: importar um XML real de fornecedor e conferir que (a) produto novo nasce com NCM preenchido, (b) produto já existente com NCM diferente **não** é sobrescrito e gera divergência, (c) a tela de pendências lista o que falta.

## Limites conhecidos desta etapa

- Nenhuma emissão de NF-e acontece aqui — é a etapa B.
- O bloqueio de emissão para item sem NCM é comportamento da etapa B; aqui o vermelho apenas sinaliza.
- Derivação de CFOP de saída não existe nesta etapa (depende da UF do destinatário) — etapa B.
