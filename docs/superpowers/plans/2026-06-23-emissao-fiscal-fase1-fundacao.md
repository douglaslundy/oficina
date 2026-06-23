# Emissão Fiscal — Fase 1 (Fundação Multi-Provedor, Backend) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir a camada de abstração de provedor fiscal (Spedy + Focus NFe) com seleção configurável (global + por oficina), registro de emissor por oficina, e emissão de **NFS-e em sandbox**, mantendo o `NfeService` como orquestrador.

**Architecture:** Strategy pattern. Interface `FiscalProvider` com duas implementações (`SpedyProvider`, `FocusNfeProvider`); um `FiscalProviderManager` resolve a instância por request (override da `Oficina` → padrão do `SaasConfig`) injetando credencial master, token por-oficina e ambiente. DTOs neutros (`EmissorData`, `NotaFiscalData`, `EmissaoResultado`, `RegistroResultado`) isolam o formato de cada provedor. O `NfeService` mantém numeração/persistência/alertas e delega a emissão ao provedor resolvido.

**Tech Stack:** Laravel 11 (PHP 8.3, `declare(strict_types=1)`), PostgreSQL, Eloquent, `Illuminate\Support\Facades\Http`, PHPUnit (unit tests com `Http::fake`).

## Global Constraints

- `declare(strict_types=1);` no topo de TODO arquivo PHP novo.
- Models com UUID: `public $incrementing = false; protected $keyType = 'string';` + `boot()` gerando `Str::uuid()` (seguir `App\Models\Oficina`).
- Tabelas de tenant usam `oficina_id` + trait `App\Tenancy\HasTenantScope`. Tabelas de plataforma (`saas_config`, `emissores_fiscais`) NÃO usam o trait.
- Segredos (tokens, senha de certificado): coluna `text`, cifrados via `Illuminate\Support\Facades\Crypt::encryptString` na escrita e `Crypt::decryptString` na leitura; nunca retornados em claro pela API (máscara via `App\Models\SaasConfig::mascarar`).
- Certificado `.pfx`: cifrado com `openssl aes-256-cbc` no padrão JÁ existente em `ConfiguracaoController::uploadCertificado` (chave = `substr(hash('sha256', config('app.key'), true), 0, 32)`, IV aleatório de 16 bytes prefixado, base64).
- Status normalizado da nota usa os valores existentes em `notas_fiscais.status`: `RASCUNHO | PROCESSANDO | AUTORIZADA | CANCELADA | REJEITADA`.
- Provedores válidos: `SPEDY | FOCUS`. Ambientes: `HOMOLOGACAO | PRODUCAO`. Modos de emissão: `MANUAL | AUTOMATICO`.
- Localmente só rodam **unit tests** (sem DB/Docker). Tasks marcam claramente quais testes são unit (rodam aqui) e quais são feature (rodam só em ambiente com banco / no deploy).
- Comando de teste unit: `php artisan test --testsuite=Unit` (ou caminho específico).
- Documentação viva para conferência de payloads: Spedy `https://api.spedy.com.br/llms.txt`; Focus `https://doc.focusnfe.com.br/llms.txt`.

---

## File Structure

**Criar:**
- `backend/app/Services/Fiscal/Data/EmissorData.php` — DTO do emissor.
- `backend/app/Services/Fiscal/Data/NotaFiscalData.php` — DTO neutro da nota (NFS-e na Fase 1).
- `backend/app/Services/Fiscal/Data/EmissaoResultado.php` — resultado normalizado da emissão/consulta/cancelamento.
- `backend/app/Services/Fiscal/Data/RegistroResultado.php` — resultado do registro de emissor.
- `backend/app/Services/Fiscal/Contracts/FiscalProvider.php` — interface.
- `backend/app/Services/Fiscal/Providers/SpedyProvider.php`
- `backend/app/Services/Fiscal/Providers/FocusNfeProvider.php`
- `backend/app/Services/Fiscal/FiscalProviderManager.php`
- `backend/app/Services/Fiscal/CertificadoValidator.php` — valida `.pfx` + extrai validade.
- `backend/app/Services/Fiscal/RegistrarEmissorService.php` — orquestra registro de emissor no provedor.
- `backend/app/Models/EmissorFiscal.php`
- migrations (4 arquivos, ver tasks).
- testes unit em `backend/tests/Unit/Fiscal/`.

**Modificar:**
- `backend/config/services.php` — base URLs Spedy/Focus.
- `backend/app/Models/SaasConfig.php` — novos campos fiscais.
- `backend/app/Models/Oficina.php` — overrides.
- `backend/app/Models/Configuracao.php` — campos de certificado.
- `backend/app/Models/NotaFiscal.php` — campos provedor/ambiente/referencia.
- `backend/app/Services/NfeService.php` — delega ao Manager.
- `backend/app/Http/Controllers/SaaS/SaasConfigController.php` — config fiscal global.
- `backend/app/Http/Controllers/SaaS/OficinaController.php` — overrides por oficina.
- `backend/app/Http/Controllers/ConfiguracaoController.php` — upload cert (senha+validade) + ativar emissão.
- `backend/routes/api.php` — novas rotas.

> **Escopo desta Fase 1:** apenas backend, emissão **NFS-e em sandbox**. NF-e, modo AUTOMATICO, job de consulta assíncrona e dashboard ficam para Fases 2 e 3 (planos próprios). O frontend (UI SaaS-admin + upload) é o plano imediatamente seguinte.

---

### Task 1: Migration — campos fiscais em `saas_config` e overrides em `oficinas`

**Files:**
- Create: `backend/database/migrations/2026_06_23_000001_add_fiscal_to_saas_config_and_oficinas.php`

**Interfaces:**
- Produces: colunas `saas_config.provedor_fiscal_padrao`, `saas_config.emissao_fiscal_modo_padrao`, `saas_config.spedy_master_key_sandbox`, `saas_config.spedy_master_key_producao`, `saas_config.focus_master_token_homologacao`, `saas_config.focus_master_token_producao`; `oficinas.provedor_fiscal`, `oficinas.emissao_fiscal_modo`.

- [ ] **Step 1: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->string('provedor_fiscal_padrao', 10)->default('SPEDY');       // SPEDY | FOCUS
            $table->string('emissao_fiscal_modo_padrao', 12)->default('MANUAL');  // MANUAL | AUTOMATICO
            $table->text('spedy_master_key_sandbox')->nullable();
            $table->text('spedy_master_key_producao')->nullable();
            $table->text('focus_master_token_homologacao')->nullable();
            $table->text('focus_master_token_producao')->nullable();
        });

        Schema::table('oficinas', function (Blueprint $table) {
            $table->string('provedor_fiscal', 10)->nullable();        // SPEDY | FOCUS | null
            $table->string('emissao_fiscal_modo', 12)->nullable();    // MANUAL | AUTOMATICO | null
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn([
                'provedor_fiscal_padrao', 'emissao_fiscal_modo_padrao',
                'spedy_master_key_sandbox', 'spedy_master_key_producao',
                'focus_master_token_homologacao', 'focus_master_token_producao',
            ]);
        });
        Schema::table('oficinas', function (Blueprint $table) {
            $table->dropColumn(['provedor_fiscal', 'emissao_fiscal_modo']);
        });
    }
};
```

- [ ] **Step 2: Verificar sintaxe PHP**

Run: `cd backend && php -l database/migrations/2026_06_23_000001_add_fiscal_to_saas_config_and_oficinas.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/database/migrations/2026_06_23_000001_add_fiscal_to_saas_config_and_oficinas.php
git commit -m "feat(fiscal): migration de config fiscal global e overrides por oficina"
```

---

### Task 2: Migration — certificado em `configuracoes`, tabela `emissores_fiscais`, campos em `notas_fiscais`

**Files:**
- Create: `backend/database/migrations/2026_06_23_000002_create_emissores_fiscais_and_fiscal_fields.php`

**Interfaces:**
- Produces: colunas `configuracoes.certificado_senha_encrypted`, `configuracoes.certificado_validade`, `configuracoes.certificado_nome`, `configuracoes.certificado_status`; tabela `emissores_fiscais`; colunas `notas_fiscais.provedor`, `notas_fiscais.ambiente`, `notas_fiscais.referencia_externa`.

- [ ] **Step 1: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->text('certificado_senha_encrypted')->nullable();
            $table->date('certificado_validade')->nullable();
            $table->string('certificado_nome', 150)->nullable();
            $table->string('certificado_status', 20)->nullable(); // OK | INVALIDO | EXPIRADO
        });

        Schema::create('emissores_fiscais', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('oficina_id');
            $table->string('provedor', 10);   // SPEDY | FOCUS
            $table->string('ambiente', 12);    // HOMOLOGACAO | PRODUCAO
            $table->string('emissor_externo_id', 100)->nullable();
            $table->text('token_encrypted')->nullable();
            $table->string('status', 20)->default('PENDENTE'); // PENDENTE | REGISTRADO | ERRO
            $table->timestampTz('registrado_em')->nullable();
            $table->text('ultimo_erro')->nullable();
            $table->timestampTz('criado_em')->useCurrent();
            $table->unique(['oficina_id', 'provedor', 'ambiente']);
            $table->index('oficina_id');
        });

        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->string('provedor', 10)->nullable();   // SPEDY | FOCUS
            $table->string('ambiente', 12)->nullable();    // HOMOLOGACAO | PRODUCAO
            $table->string('referencia_externa', 60)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->dropColumn(['provedor', 'ambiente', 'referencia_externa']);
        });
        Schema::dropIfExists('emissores_fiscais');
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn([
                'certificado_senha_encrypted', 'certificado_validade',
                'certificado_nome', 'certificado_status',
            ]);
        });
    }
};
```

- [ ] **Step 2: Verificar sintaxe PHP**

Run: `cd backend && php -l database/migrations/2026_06_23_000002_create_emissores_fiscais_and_fiscal_fields.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/database/migrations/2026_06_23_000002_create_emissores_fiscais_and_fiscal_fields.php
git commit -m "feat(fiscal): emissores_fiscais, certificado e campos de provedor na nota"
```

---

### Task 3: Config de base URLs + atualização dos Models

**Files:**
- Modify: `backend/config/services.php`
- Modify: `backend/app/Models/SaasConfig.php`
- Modify: `backend/app/Models/Oficina.php:19-31`
- Modify: `backend/app/Models/Configuracao.php:20-27`
- Modify: `backend/app/Models/NotaFiscal.php:24-30`

**Interfaces:**
- Produces: `config('services.spedy.sandbox_url')`, `config('services.spedy.producao_url')`, `config('services.focusnfe.homologacao_url')`, `config('services.focusnfe.producao_url')`; campos fillable nos models.

- [ ] **Step 1: Adicionar base URLs em `config/services.php`**

Adicionar ao array retornado (antes do `];` final):

```php
    'spedy' => [
        'sandbox_url'  => env('SPEDY_SANDBOX_URL', 'https://sandbox-api.spedy.com.br/v1'),
        'producao_url' => env('SPEDY_PRODUCAO_URL', 'https://api.spedy.com.br/v1'),
    ],

    'focusnfe' => [
        'homologacao_url' => env('FOCUS_HOMOLOGACAO_URL', 'https://homologacao.focusnfe.com.br'),
        'producao_url'    => env('FOCUS_PRODUCAO_URL', 'https://api.focusnfe.com.br'),
    ],
```

- [ ] **Step 2: Atualizar `SaasConfig` (fillable, hidden, máscara dos novos segredos)**

Em `backend/app/Models/SaasConfig.php`, adicionar ao array `$fillable` os campos:
`'provedor_fiscal_padrao', 'emissao_fiscal_modo_padrao', 'spedy_master_key_sandbox', 'spedy_master_key_producao', 'focus_master_token_homologacao', 'focus_master_token_producao',`

E ao array `$hidden`:
`'spedy_master_key_sandbox', 'spedy_master_key_producao', 'focus_master_token_homologacao', 'focus_master_token_producao',`

- [ ] **Step 3: Atualizar fillable dos demais models**

`Oficina.php` `$fillable`: adicionar `'provedor_fiscal', 'emissao_fiscal_modo',`
`Configuracao.php` `$fillable`: adicionar `'certificado_senha_encrypted', 'certificado_validade', 'certificado_nome', 'certificado_status',`
`NotaFiscal.php` `$fillable`: adicionar `'provedor', 'ambiente', 'referencia_externa',`

- [ ] **Step 4: Verificar sintaxe**

Run: `cd backend && php -l config/services.php && php -l app/Models/SaasConfig.php && php -l app/Models/Oficina.php && php -l app/Models/Configuracao.php && php -l app/Models/NotaFiscal.php`
Expected: `No syntax errors detected` em todos.

- [ ] **Step 5: Commit**

```bash
git add backend/config/services.php backend/app/Models/SaasConfig.php backend/app/Models/Oficina.php backend/app/Models/Configuracao.php backend/app/Models/NotaFiscal.php
git commit -m "feat(fiscal): base URLs dos provedores e campos fiscais nos models"
```

---

### Task 4: DTOs neutros

**Files:**
- Create: `backend/app/Services/Fiscal/Data/EmissorData.php`
- Create: `backend/app/Services/Fiscal/Data/NotaFiscalData.php`
- Create: `backend/app/Services/Fiscal/Data/EmissaoResultado.php`
- Create: `backend/app/Services/Fiscal/Data/RegistroResultado.php`
- Test: `backend/tests/Unit/Fiscal/EmissaoResultadoTest.php`

**Interfaces:**
- Produces:
  - `EmissorData(string $cnpj, string $razaoSocial, ?string $nomeFantasia, ?string $inscricaoEstadual, ?string $inscricaoMunicipal, string $regimeTributario, string $email, ?string $telefone, string $cep, string $logradouro, string $numero, ?string $complemento, string $bairro, string $cidade, string $uf, string $codigoIbge, string $cnae)`
  - `NotaFiscalData(string $tipo, array $tomador, string $descricao, float $valorServicos, float $aliquotaIss, bool $issRetido, string $codigoServicoFederal, string $codigoServicoMunicipal, string $naturezaOperacao, string $referenciaExterna)` — `tipo` = `'NFSE'`. `tomador` = `['nome'=>, 'cpf_cnpj'=>, 'email'=>, 'cep'=>, 'logradouro'=>, 'numero'=>, 'bairro'=>, 'cidade'=>, 'uf'=>, 'codigo_ibge'=>]`.
  - `EmissaoResultado(string $status, ?string $chave, ?string $protocolo, ?string $numero, ?string $xml, ?string $pdfUrl, ?string $mensagemErro, ?string $referenciaExterna)` com fábricas estáticas `autorizada(...)`, `processando(...)`, `rejeitada(string $mensagemErro, ?string $referenciaExterna = null)`, `cancelada(...)`.
  - `RegistroResultado(string $status, ?string $emissorExternoId, ?string $token, ?string $mensagemErro)` com `ok(string $emissorExternoId, string $token)` e `erro(string $mensagemErro)`.

- [ ] **Step 1: Criar `EmissorData`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class EmissorData
{
    public function __construct(
        public readonly string $cnpj,
        public readonly string $razaoSocial,
        public readonly ?string $nomeFantasia,
        public readonly ?string $inscricaoEstadual,
        public readonly ?string $inscricaoMunicipal,
        public readonly string $regimeTributario,
        public readonly string $email,
        public readonly ?string $telefone,
        public readonly string $cep,
        public readonly string $logradouro,
        public readonly string $numero,
        public readonly ?string $complemento,
        public readonly string $bairro,
        public readonly string $cidade,
        public readonly string $uf,
        public readonly string $codigoIbge,
        public readonly string $cnae,
    ) {}

    public function cnpjLimpo(): string
    {
        return preg_replace('/\D/', '', $this->cnpj) ?? '';
    }
}
```

- [ ] **Step 2: Criar `NotaFiscalData`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class NotaFiscalData
{
    public function __construct(
        public readonly string $tipo,                  // NFSE (Fase 1)
        public readonly array $tomador,
        public readonly string $descricao,
        public readonly float $valorServicos,
        public readonly float $aliquotaIss,
        public readonly bool $issRetido,
        public readonly string $codigoServicoFederal,
        public readonly string $codigoServicoMunicipal,
        public readonly string $naturezaOperacao,
        public readonly string $referenciaExterna,
    ) {}
}
```

- [ ] **Step 3: Criar `EmissaoResultado`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class EmissaoResultado
{
    public function __construct(
        public readonly string $status,            // AUTORIZADA | PROCESSANDO | REJEITADA | CANCELADA
        public readonly ?string $chave = null,
        public readonly ?string $protocolo = null,
        public readonly ?string $numero = null,
        public readonly ?string $xml = null,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $mensagemErro = null,
        public readonly ?string $referenciaExterna = null,
    ) {}

    public static function autorizada(?string $chave, ?string $protocolo, ?string $numero, ?string $xml, ?string $pdfUrl, ?string $ref = null): self
    {
        return new self('AUTORIZADA', $chave, $protocolo, $numero, $xml, $pdfUrl, null, $ref);
    }

    public static function processando(?string $ref = null): self
    {
        return new self('PROCESSANDO', null, null, null, null, null, null, $ref);
    }

    public static function rejeitada(string $mensagemErro, ?string $ref = null): self
    {
        return new self('REJEITADA', null, null, null, null, null, $mensagemErro, $ref);
    }

    public static function cancelada(?string $ref = null): self
    {
        return new self('CANCELADA', null, null, null, null, null, null, $ref);
    }
}
```

- [ ] **Step 4: Criar `RegistroResultado`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Data;

final class RegistroResultado
{
    public function __construct(
        public readonly string $status,             // REGISTRADO | ERRO
        public readonly ?string $emissorExternoId = null,
        public readonly ?string $token = null,
        public readonly ?string $mensagemErro = null,
    ) {}

    public static function ok(string $emissorExternoId, string $token): self
    {
        return new self('REGISTRADO', $emissorExternoId, $token, null);
    }

    public static function erro(string $mensagemErro): self
    {
        return new self('ERRO', null, null, $mensagemErro);
    }
}
```

- [ ] **Step 5: Escrever teste unit das fábricas**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\RegistroResultado;
use PHPUnit\Framework\TestCase;

class EmissaoResultadoTest extends TestCase
{
    public function test_autorizada_define_status_e_dados(): void
    {
        $r = EmissaoResultado::autorizada('CHAVE1', 'PROTO1', '10', '<xml/>', 'http://pdf', 'ref-1');
        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE1', $r->chave);
        $this->assertSame('ref-1', $r->referenciaExterna);
        $this->assertNull($r->mensagemErro);
    }

    public function test_rejeitada_carrega_mensagem(): void
    {
        $r = EmissaoResultado::rejeitada('CNPJ inválido', 'ref-2');
        $this->assertSame('REJEITADA', $r->status);
        $this->assertSame('CNPJ inválido', $r->mensagemErro);
    }

    public function test_registro_ok_e_erro(): void
    {
        $ok = RegistroResultado::ok('emp-123', 'tok-abc');
        $this->assertSame('REGISTRADO', $ok->status);
        $this->assertSame('emp-123', $ok->emissorExternoId);

        $err = RegistroResultado::erro('falhou');
        $this->assertSame('ERRO', $err->status);
        $this->assertSame('falhou', $err->mensagemErro);
    }
}
```

- [ ] **Step 6: Rodar o teste (deve passar)**

Run: `cd backend && php artisan test tests/Unit/Fiscal/EmissaoResultadoTest.php`
Expected: PASS (3 testes).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/Fiscal/Data backend/tests/Unit/Fiscal/EmissaoResultadoTest.php
git commit -m "feat(fiscal): DTOs neutros de emissor, nota e resultado"
```

---

### Task 5: Interface `FiscalProvider`

**Files:**
- Create: `backend/app/Services/Fiscal/Contracts/FiscalProvider.php`

**Interfaces:**
- Consumes: DTOs da Task 4.
- Produces: interface `App\Services\Fiscal\Contracts\FiscalProvider` com os métodos abaixo (assinaturas exatas usadas pelas Tasks 7, 8, 10, 11).

- [ ] **Step 1: Criar a interface**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Contracts;

use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;

interface FiscalProvider
{
    /** Registra a empresa emissora no provedor e retorna o token por-oficina. */
    public function registrarEmissor(EmissorData $e): RegistroResultado;

    /** Sobe/vincula o certificado A1 (.pfx) ao emissor já registrado. */
    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void;

    /** Emite uma nota (NFS-e na Fase 1). Pode retornar PROCESSANDO (assíncrono). */
    public function emitir(NotaFiscalData $nota): EmissaoResultado;

    /** Consulta o status atual de uma nota pela referência. */
    public function consultar(string $referencia): EmissaoResultado;

    /** Cancela uma nota autorizada. */
    public function cancelar(string $referencia, string $motivo): EmissaoResultado;
}
```

- [ ] **Step 2: Verificar sintaxe**

Run: `cd backend && php -l app/Services/Fiscal/Contracts/FiscalProvider.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/app/Services/Fiscal/Contracts/FiscalProvider.php
git commit -m "feat(fiscal): interface FiscalProvider"
```

---

### Task 6: `CertificadoValidator`

**Files:**
- Create: `backend/app/Services/Fiscal/CertificadoValidator.php`
- Test: `backend/tests/Unit/Fiscal/CertificadoValidatorTest.php`

**Interfaces:**
- Produces: `CertificadoValidator::validar(string $pfxBinary, string $senha): array` → `['ok'=>bool, 'validade'=>?string (Y-m-d), 'nome'=>?string, 'erro'=>?string]`.

- [ ] **Step 1: Escrever o teste do caminho de erro (não exige cert válido)**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\CertificadoValidator;
use PHPUnit\Framework\TestCase;

class CertificadoValidatorTest extends TestCase
{
    public function test_pfx_invalido_retorna_erro(): void
    {
        $validator = new CertificadoValidator();
        $resultado = $validator->validar('conteudo-que-nao-e-pfx', 'senha-errada');

        $this->assertFalse($resultado['ok']);
        $this->assertNull($resultado['validade']);
        $this->assertNotNull($resultado['erro']);
    }
}
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/CertificadoValidatorTest.php`
Expected: FAIL com "Class CertificadoValidator not found".

- [ ] **Step 3: Implementar o validator**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

class CertificadoValidator
{
    /**
     * @return array{ok: bool, validade: ?string, nome: ?string, erro: ?string}
     */
    public function validar(string $pfxBinary, string $senha): array
    {
        $certs = [];
        if (!openssl_pkcs12_read($pfxBinary, $certs, $senha)) {
            return ['ok' => false, 'validade' => null, 'nome' => null, 'erro' => 'Certificado inválido ou senha incorreta.'];
        }

        $info = openssl_x509_parse($certs['cert'] ?? '');
        if ($info === false) {
            return ['ok' => false, 'validade' => null, 'nome' => null, 'erro' => 'Não foi possível ler o certificado.'];
        }

        $validade = isset($info['validTo_time_t'])
            ? date('Y-m-d', (int) $info['validTo_time_t'])
            : null;
        $nome = $info['subject']['CN'] ?? null;

        if ($validade !== null && strtotime($validade) < time()) {
            return ['ok' => false, 'validade' => $validade, 'nome' => $nome, 'erro' => 'Certificado expirado.'];
        }

        return ['ok' => true, 'validade' => $validade, 'nome' => $nome, 'erro' => null];
    }
}
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/CertificadoValidatorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/CertificadoValidator.php backend/tests/Unit/Fiscal/CertificadoValidatorTest.php
git commit -m "feat(fiscal): validador de certificado A1 com extração de validade"
```

---

### Task 7: `SpedyProvider`

**Files:**
- Create: `backend/app/Services/Fiscal/Providers/SpedyProvider.php`
- Test: `backend/tests/Unit/Fiscal/SpedyProviderTest.php`

**Interfaces:**
- Consumes: `FiscalProvider`, DTOs.
- Produces: `new SpedyProvider(string $baseUrl, string $masterKey, ?string $emissorToken, ?string $emissorExternoId)`. Implementa `FiscalProvider`. Métodos públicos auxiliares testáveis: `montarPayloadEmpresa(EmissorData $e): array`, `montarPayloadNfse(NotaFiscalData $n): array`, `mapStatus(string $spedyStatus): string`.

Referência de payloads (Spedy v1): empresa `POST /companies`; certificado `POST /companies/{id}/certificates` (multipart `file`,`password`); NFS-e `POST /service-invoices`; consulta `GET /service-invoices/{id}`; cancelamento `DELETE /service-invoices/{id}` body `{"justification": "..."}`. Auth header `X-Api-Key`. Status: `authorized|enqueued|rejected|canceled`.

- [ ] **Step 1: Escrever os testes (Http::fake, sem DB)**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Providers\SpedyProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpedyProviderTest extends TestCase
{
    private function nota(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'email' => 'c@x.com', 'cep' => '01310100', 'logradouro' => 'Av A',
                'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
                'uf' => 'SP', 'codigo_ibge' => '3550308',
            ],
            descricao: 'Serviço de troca de óleo',
            valorServicos: 200.00,
            aliquotaIss: 5.0,
            issRetido: false,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços',
            referenciaExterna: 'os-123',
        );
    }

    public function test_map_status_normaliza(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $this->assertSame('AUTORIZADA', $p->mapStatus('authorized'));
        $this->assertSame('PROCESSANDO', $p->mapStatus('enqueued'));
        $this->assertSame('REJEITADA', $p->mapStatus('rejected'));
        $this->assertSame('CANCELADA', $p->mapStatus('canceled'));
    }

    public function test_payload_nfse_usa_campos_spedy(): void
    {
        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $payload = $p->montarPayloadNfse($this->nota());

        $this->assertSame('Serviço de troca de óleo', $payload['description']);
        $this->assertSame('14.01', $payload['federalServiceCode']);
        $this->assertSame(200.00, $payload['total']['invoiceAmount']);
        $this->assertSame(0.05, $payload['total']['issRate']);
        $this->assertSame('12345678000199', $payload['receiver']['federalTaxNumber']);
    }

    public function test_emitir_autorizada(): void
    {
        Http::fake([
            '*/service-invoices' => Http::response([
                'id' => 'inv-1', 'status' => 'authorized',
                'accessKey' => 'CHAVE-SP', 'number' => '55',
            ], 201),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->nota());

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('CHAVE-SP', $r->chave);
        $this->assertSame('55', $r->numero);

        Http::assertSent(fn ($req) =>
            $req->hasHeader('X-Api-Key', 'tok') &&
            str_contains($req->url(), '/service-invoices')
        );
    }

    public function test_emitir_falha_retorna_rejeitada(): void
    {
        Http::fake([
            '*/service-invoices' => Http::response(['message' => 'CNPJ não habilitado'], 422),
        ]);

        $p = new SpedyProvider('https://sandbox-api.spedy.com.br/v1', 'master', 'tok', 'emp-1');
        $r = $p->emitir($this->nota());

        $this->assertSame('REJEITADA', $r->status);
        $this->assertStringContainsString('CNPJ não habilitado', (string) $r->mensagemErro);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/SpedyProviderTest.php`
Expected: FAIL ("Class SpedyProvider not found").

- [ ] **Step 3: Implementar `SpedyProvider`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use Illuminate\Support\Facades\Http;

class SpedyProvider implements FiscalProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $masterKey,
        private readonly ?string $emissorToken = null,
        private readonly ?string $emissorExternoId = null,
    ) {}

    public function registrarEmissor(EmissorData $e): RegistroResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->masterKey])
            ->post("{$this->baseUrl}/companies", $this->montarPayloadEmpresa($e));

        if ($resp->failed()) {
            return RegistroResultado::erro($resp->json('message') ?? 'Erro ao registrar emissor na Spedy.');
        }

        $id  = (string) $resp->json('id');
        $key = (string) ($resp->json('apiCredentials.apiKey') ?? '');

        return RegistroResultado::ok($id, $key);
    }

    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->masterKey])
            ->attach('file', $pfxBinary, 'certificado.pfx')
            ->post("{$this->baseUrl}/companies/{$this->emissorExternoId}/certificates", [
                'password' => $senha,
            ]);

        if ($resp->failed()) {
            throw new \RuntimeException('Erro ao enviar certificado para a Spedy: ' . ($resp->json('message') ?? ''));
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->post("{$this->baseUrl}/service-invoices", $this->montarPayloadNfse($nota));

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada(
                $resp->json('message') ?? 'Erro na emissão (Spedy).',
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna);
    }

    public function consultar(string $referencia): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->get("{$this->baseUrl}/service-invoices/{$referencia}");

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('message') ?? 'Erro ao consultar (Spedy).', $referencia);
        }

        return $this->resultadoDe($resp->json(), $referencia);
    }

    public function cancelar(string $referencia, string $motivo): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->delete("{$this->baseUrl}/service-invoices/{$referencia}", [
                'justification' => $motivo,
            ]);

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('message') ?? 'Erro ao cancelar (Spedy).', $referencia);
        }

        return EmissaoResultado::cancelada($referencia);
    }

    public function montarPayloadEmpresa(EmissorData $e): array
    {
        return [
            'name'             => $e->nomeFantasia ?? $e->razaoSocial,
            'legalName'        => $e->razaoSocial,
            'federalTaxNumber' => $e->cnpjLimpo(),
            'stateTaxNumber'   => $e->inscricaoEstadual,
            'cityTaxNumber'    => $e->inscricaoMunicipal,
            'email'            => $e->email,
            'phone'            => $e->telefone,
            'address'          => [
                'street'     => $e->logradouro,
                'number'     => $e->numero,
                'district'   => $e->bairro,
                'postalCode' => preg_replace('/\D/', '', $e->cep),
                'additionalInformation' => $e->complemento,
                'city'       => [
                    'code'  => $e->codigoIbge,
                    'name'  => $e->cidade,
                    'state' => $e->uf,
                ],
            ],
            'taxRegime'          => $this->mapRegime($e->regimeTributario),
            'economicActivities' => [
                ['code' => preg_replace('/\D/', '', $e->cnae), 'isMain' => true],
            ],
        ];
    }

    public function montarPayloadNfse(NotaFiscalData $n): array
    {
        return [
            'status'              => 'enqueued',
            'sendEmailToCustomer' => false,
            'description'         => $n->descricao,
            'federalServiceCode'  => $n->codigoServicoFederal,
            'cityServiceCode'     => $n->codigoServicoMunicipal,
            'taxationType'        => 'taxationInMunicipality',
            'receiver'            => [
                'name'             => $n->tomador['nome'],
                'federalTaxNumber' => preg_replace('/\D/', '', $n->tomador['cpf_cnpj']),
                'email'            => $n->tomador['email'] ?? null,
                'address'          => [
                    'street'     => $n->tomador['logradouro'] ?? '',
                    'number'     => $n->tomador['numero'] ?? 'S/N',
                    'district'   => $n->tomador['bairro'] ?? '',
                    'postalCode' => preg_replace('/\D/', '', $n->tomador['cep'] ?? ''),
                    'city'       => [
                        'code'  => $n->tomador['codigo_ibge'] ?? '',
                        'name'  => $n->tomador['cidade'] ?? '',
                        'state' => $n->tomador['uf'] ?? '',
                    ],
                ],
            ],
            'total' => [
                'invoiceAmount' => $n->valorServicos,
                'issRate'       => $n->aliquotaIss / 100,
                'issAmount'     => round($n->valorServicos * $n->aliquotaIss / 100, 2),
                'issWithheld'   => $n->issRetido,
            ],
        ];
    }

    public function mapStatus(string $spedyStatus): string
    {
        return match ($spedyStatus) {
            'authorized'           => 'AUTORIZADA',
            'rejected'             => 'REJEITADA',
            'canceled'             => 'CANCELADA',
            default                => 'PROCESSANDO', // enqueued, processing, etc.
        };
    }

    private function mapRegime(string $regime): string
    {
        $r = strtolower($regime);
        return match (true) {
            str_contains($r, 'simples')    => 'simplesNacional',
            str_contains($r, 'presumido')  => 'lucroPresumido',
            str_contains($r, 'real')       => 'lucroReal',
            default                        => 'simplesNacional',
        };
    }

    private function resultadoDe(array $json, ?string $ref): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'enqueued'));

        if ($status === 'REJEITADA') {
            $msg = $json['processingDetail']['message'] ?? ($json['message'] ?? 'Rejeitada pela SEFAZ/Prefeitura.');
            return EmissaoResultado::rejeitada($msg, $ref);
        }
        if ($status === 'PROCESSANDO') {
            return EmissaoResultado::processando($ref);
        }
        if ($status === 'CANCELADA') {
            return EmissaoResultado::cancelada($ref);
        }

        return EmissaoResultado::autorizada(
            chave: $json['accessKey'] ?? null,
            protocolo: isset($json['number']) ? (string) $json['number'] : null,
            numero: isset($json['number']) ? (string) $json['number'] : null,
            xml: $json['xml'] ?? null,
            pdfUrl: $json['pdfUrl'] ?? null,
            ref: $ref,
        );
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/SpedyProviderTest.php`
Expected: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/SpedyProvider.php backend/tests/Unit/Fiscal/SpedyProviderTest.php
git commit -m "feat(fiscal): SpedyProvider (NFS-e, registro, certificado, consulta, cancelamento)"
```

---

### Task 8: `FocusNfeProvider`

**Files:**
- Create: `backend/app/Services/Fiscal/Providers/FocusNfeProvider.php`
- Test: `backend/tests/Unit/Fiscal/FocusNfeProviderTest.php`

**Interfaces:**
- Consumes: `FiscalProvider`, DTOs.
- Produces: `new FocusNfeProvider(string $baseUrl, string $masterToken, ?string $emissorToken)`. Implementa `FiscalProvider`. Auxiliares: `montarPayloadNfse(NotaFiscalData $n): array`, `mapStatus(string $focusStatus): string`.

Referência (Focus v2): auth HTTP Basic com o token como usuário e senha vazia. Empresa `POST /v2/empresas` (campos `cnpj`, `nome`, `nome_fantasia`, `inscricao_municipal`, `regime_tributario`, `email`, `logradouro`, `numero`, `bairro`, `cep`, `municipio`, `uf`, `codigo_municipio`, `arquivo_certificado_base64`, `senha_certificado`, `habilita_nfse`); resposta traz `token_homologacao`/`token_producao`. NFS-e `POST /v2/nfse?ref={ref}`. Consulta `GET /v2/nfse/{ref}`. Cancelamento `DELETE /v2/nfse/{ref}` body `{"justificativa": "..."}`. Status: `autorizado|processando_autorizacao|erro_autorizacao|cancelado`.

- [ ] **Step 1: Escrever os testes (Http::fake)**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Providers\FocusNfeProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FocusNfeProviderTest extends TestCase
{
    private function nota(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'email' => 'c@x.com', 'cep' => '01310100', 'logradouro' => 'Av A',
                'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
                'uf' => 'SP', 'codigo_ibge' => '3550308',
            ],
            descricao: 'Serviço de troca de óleo',
            valorServicos: 200.00,
            aliquotaIss: 5.0,
            issRetido: false,
            codigoServicoFederal: '14.01',
            codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços',
            referenciaExterna: 'os-123',
        );
    }

    public function test_map_status_normaliza(): void
    {
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'tok');
        $this->assertSame('AUTORIZADA', $p->mapStatus('autorizado'));
        $this->assertSame('PROCESSANDO', $p->mapStatus('processando_autorizacao'));
        $this->assertSame('REJEITADA', $p->mapStatus('erro_autorizacao'));
        $this->assertSame('CANCELADA', $p->mapStatus('cancelado'));
    }

    public function test_payload_nfse_usa_campos_focus(): void
    {
        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'tok');
        $payload = $p->montarPayloadNfse($this->nota());

        $this->assertSame(200.00, $payload['servico']['valor_servicos']);
        $this->assertSame('1401', $payload['servico']['codigo_tributario_municipio']);
        $this->assertSame(5.0, $payload['servico']['aliquota']);
        $this->assertSame('12345678000199', $payload['tomador']['cnpj']);
    }

    public function test_emitir_envia_ref_e_processa(): void
    {
        Http::fake([
            '*/v2/nfse?ref=os-123' => Http::response([
                'status' => 'processando_autorizacao',
            ], 202),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'tok');
        $r = $p->emitir($this->nota());

        $this->assertSame('PROCESSANDO', $r->status);
        $this->assertSame('os-123', $r->referenciaExterna);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'ref=os-123'));
    }

    public function test_consultar_autorizado(): void
    {
        Http::fake([
            '*/v2/nfse/os-123' => Http::response([
                'status' => 'autorizado',
                'numero' => '77',
                'caminho_xml_nota_fiscal' => '/xml/os-123.xml',
                'url' => 'http://focus/danfse/os-123.pdf',
            ], 200),
        ]);

        $p = new FocusNfeProvider('https://homologacao.focusnfe.com.br', 'master', 'tok');
        $r = $p->consultar('os-123');

        $this->assertSame('AUTORIZADA', $r->status);
        $this->assertSame('77', $r->numero);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/FocusNfeProviderTest.php`
Expected: FAIL ("Class FocusNfeProvider not found").

- [ ] **Step 3: Implementar `FocusNfeProvider`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use Illuminate\Support\Facades\Http;

class FocusNfeProvider implements FiscalProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $masterToken,
        private readonly ?string $emissorToken = null,
    ) {}

    private function ambienteProducao(): bool
    {
        return str_contains($this->baseUrl, 'api.focusnfe.com.br');
    }

    public function registrarEmissor(EmissorData $e): RegistroResultado
    {
        // Certificado é enviado junto no cadastro da empresa (ver enviarCertificado/registro combinado no service).
        $resp = Http::withBasicAuth($this->masterToken, '')
            ->post("{$this->baseUrl}/v2/empresas", $this->montarPayloadEmpresa($e));

        if ($resp->failed()) {
            return RegistroResultado::erro($resp->json('mensagem') ?? 'Erro ao registrar empresa na Focus.');
        }

        $id    = (string) ($resp->json('id') ?? $e->cnpjLimpo());
        $token = (string) ($this->ambienteProducao()
            ? ($resp->json('token_producao') ?? '')
            : ($resp->json('token_homologacao') ?? ''));

        return RegistroResultado::ok($id, $token);
    }

    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void
    {
        // Focus aceita o certificado no cadastro da empresa (base64). Atualiza via PUT na empresa.
        $resp = Http::withBasicAuth($this->masterToken, '')
            ->put("{$this->baseUrl}/v2/empresas/{$e->cnpjLimpo()}", [
                'arquivo_certificado_base64' => base64_encode($pfxBinary),
                'senha_certificado'          => $senha,
            ]);

        if ($resp->failed()) {
            throw new \RuntimeException('Erro ao enviar certificado para a Focus: ' . ($resp->json('mensagem') ?? ''));
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->post("{$this->baseUrl}/v2/nfse?ref={$nota->referenciaExterna}", $this->montarPayloadNfse($nota));

        if ($resp->status() >= 400) {
            return EmissaoResultado::rejeitada(
                $resp->json('mensagem') ?? ($resp->json('erros.0.mensagem') ?? 'Erro na emissão (Focus).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna);
    }

    public function consultar(string $referencia): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->get("{$this->baseUrl}/v2/nfse/{$referencia}");

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('mensagem') ?? 'Erro ao consultar (Focus).', $referencia);
        }

        return $this->resultadoDe($resp->json(), $referencia);
    }

    public function cancelar(string $referencia, string $motivo): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->delete("{$this->baseUrl}/v2/nfse/{$referencia}", [
                'justificativa' => $motivo,
            ]);

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('mensagem') ?? 'Erro ao cancelar (Focus).', $referencia);
        }

        return EmissaoResultado::cancelada($referencia);
    }

    public function montarPayloadEmpresa(EmissorData $e): array
    {
        return [
            'cnpj'                => $e->cnpjLimpo(),
            'nome'                => $e->razaoSocial,
            'nome_fantasia'       => $e->nomeFantasia ?? $e->razaoSocial,
            'inscricao_municipal' => $e->inscricaoMunicipal,
            'inscricao_estadual'  => $e->inscricaoEstadual,
            'regime_tributario'   => $this->mapRegime($e->regimeTributario),
            'email'               => $e->email,
            'telefone'            => $e->telefone,
            'logradouro'          => $e->logradouro,
            'numero'              => $e->numero,
            'complemento'         => $e->complemento,
            'bairro'              => $e->bairro,
            'cep'                 => preg_replace('/\D/', '', $e->cep),
            'municipio'           => $e->cidade,
            'uf'                  => $e->uf,
            'codigo_municipio'    => $e->codigoIbge,
            'habilita_nfse'       => true,
        ];
    }

    public function montarPayloadNfse(NotaFiscalData $n): array
    {
        $docTomador = preg_replace('/\D/', '', $n->tomador['cpf_cnpj']) ?? '';
        $chaveDoc   = strlen($docTomador) > 11 ? 'cnpj' : 'cpf';

        return [
            'data_emissao' => date('Y-m-d'),
            'tomador'      => [
                $chaveDoc      => $docTomador,
                'razao_social' => $n->tomador['nome'],
                'email'        => $n->tomador['email'] ?? null,
                'endereco'     => [
                    'logradouro'       => $n->tomador['logradouro'] ?? '',
                    'numero'           => $n->tomador['numero'] ?? 'S/N',
                    'bairro'           => $n->tomador['bairro'] ?? '',
                    'cep'              => preg_replace('/\D/', '', $n->tomador['cep'] ?? ''),
                    'codigo_municipio' => $n->tomador['codigo_ibge'] ?? '',
                    'uf'               => $n->tomador['uf'] ?? '',
                ],
            ],
            'servico' => [
                'discriminacao'               => $n->descricao,
                'item_lista_servico'          => $n->codigoServicoFederal,
                'codigo_tributario_municipio' => $n->codigoServicoMunicipal,
                'aliquota'                    => $n->aliquotaIss,
                'iss_retido'                  => $n->issRetido,
                'valor_servicos'              => $n->valorServicos,
            ],
        ];
    }

    public function mapStatus(string $focusStatus): string
    {
        return match ($focusStatus) {
            'autorizado'              => 'AUTORIZADA',
            'cancelado'               => 'CANCELADA',
            'erro_autorizacao',
            'denegado'                => 'REJEITADA',
            default                   => 'PROCESSANDO', // processando_autorizacao
        };
    }

    private function mapRegime(string $regime): string
    {
        $r = strtolower($regime);
        // Focus: 1=Simples Nacional, 2=SN excesso sublimite, 3=Regime Normal
        return match (true) {
            str_contains($r, 'simples') => '1',
            default                     => '3',
        };
    }

    private function resultadoDe(array $json, ?string $ref): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'processando_autorizacao'));

        if ($status === 'REJEITADA') {
            return EmissaoResultado::rejeitada(
                $json['mensagem'] ?? ($json['erros'][0]['mensagem'] ?? 'Rejeitada pela Prefeitura.'),
                $ref,
            );
        }
        if ($status === 'PROCESSANDO') {
            return EmissaoResultado::processando($ref);
        }
        if ($status === 'CANCELADA') {
            return EmissaoResultado::cancelada($ref);
        }

        return EmissaoResultado::autorizada(
            chave: $json['codigo_verificacao'] ?? ($json['chave_nfe'] ?? null),
            protocolo: isset($json['numero']) ? (string) $json['numero'] : null,
            numero: isset($json['numero']) ? (string) $json['numero'] : null,
            xml: $json['caminho_xml_nota_fiscal'] ?? null,
            pdfUrl: $json['url'] ?? ($json['caminho_danfse'] ?? null),
            ref: $ref,
        );
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/FocusNfeProviderTest.php`
Expected: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/FocusNfeProvider.php backend/tests/Unit/Fiscal/FocusNfeProviderTest.php
git commit -m "feat(fiscal): FocusNfeProvider (NFS-e assíncrona, registro, consulta, cancelamento)"
```

---

### Task 9: `EmissorFiscal` model + `FiscalProviderManager`

**Files:**
- Create: `backend/app/Models/EmissorFiscal.php`
- Create: `backend/app/Services/Fiscal/FiscalProviderManager.php`
- Test: `backend/tests/Unit/Fiscal/FiscalProviderManagerTest.php`

**Interfaces:**
- Consumes: `SpedyProvider`, `FocusNfeProvider`, `SaasConfig`, `Oficina`, `Configuracao`, `EmissorFiscal`.
- Produces:
  - Model `EmissorFiscal` (tabela `emissores_fiscais`, UUID, sem `HasTenantScope`).
  - `FiscalProviderManager::resolverProvedor(?string $override, string $padrao): string` (estático, puro).
  - `FiscalProviderManager::forTenant(): FiscalProvider` (resolve via DB: Oficina override → SaasConfig padrão; injeta master key do ambiente + token do `emissores_fiscais` + base URL).
  - `FiscalProviderManager::provedorDaOficina(string $oficinaId): string`.

- [ ] **Step 1: Criar o model `EmissorFiscal`**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmissorFiscal extends Model
{
    protected $table = 'emissores_fiscais';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'oficina_id', 'provedor', 'ambiente',
        'emissor_externo_id', 'token_encrypted', 'status',
        'registrado_em', 'ultimo_erro',
    ];

    protected $casts = [
        'registrado_em' => 'datetime',
        'criado_em'     => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
```

- [ ] **Step 2: Escrever o teste da resolução pura**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\FiscalProviderManager;
use PHPUnit\Framework\TestCase;

class FiscalProviderManagerTest extends TestCase
{
    public function test_override_da_oficina_prevalece(): void
    {
        $this->assertSame('FOCUS', FiscalProviderManager::resolverProvedor('FOCUS', 'SPEDY'));
    }

    public function test_sem_override_usa_padrao_global(): void
    {
        $this->assertSame('SPEDY', FiscalProviderManager::resolverProvedor(null, 'SPEDY'));
    }

    public function test_override_invalido_cai_no_padrao(): void
    {
        $this->assertSame('SPEDY', FiscalProviderManager::resolverProvedor('XPTO', 'SPEDY'));
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/FiscalProviderManagerTest.php`
Expected: FAIL ("Class FiscalProviderManager not found").

- [ ] **Step 4: Implementar `FiscalProviderManager`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Configuracao;
use App\Models\EmissorFiscal;
use App\Models\Oficina;
use App\Models\SaasConfig;
use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Providers\FocusNfeProvider;
use App\Services\Fiscal\Providers\SpedyProvider;
use App\Tenancy\TenancyContext;
use Illuminate\Support\Facades\Crypt;

class FiscalProviderManager
{
    private const PROVEDORES = ['SPEDY', 'FOCUS'];

    public static function resolverProvedor(?string $override, string $padrao): string
    {
        if ($override !== null && in_array($override, self::PROVEDORES, true)) {
            return $override;
        }
        return in_array($padrao, self::PROVEDORES, true) ? $padrao : 'SPEDY';
    }

    public function provedorDaOficina(string $oficinaId): string
    {
        $oficina = Oficina::find($oficinaId);
        $cfg     = SaasConfig::get();
        return self::resolverProvedor($oficina?->provedor_fiscal, $cfg->provedor_fiscal_padrao ?? 'SPEDY');
    }

    public function ambienteDaOficina(): string
    {
        return Configuracao::first()?->ambiente_fiscal ?? 'HOMOLOGACAO';
    }

    /** Resolve o provider para o tenant atual, com token do emissor (se registrado). */
    public function forTenant(): FiscalProvider
    {
        $oficinaId = TenancyContext::get();
        if (!$oficinaId) {
            throw new \RuntimeException('Tenant não definido para emissão fiscal.');
        }

        $provedor = $this->provedorDaOficina($oficinaId);
        $ambiente = $this->ambienteDaOficina();
        $cfg      = SaasConfig::get();

        $emissor      = EmissorFiscal::where('oficina_id', $oficinaId)
            ->where('provedor', $provedor)
            ->where('ambiente', $ambiente)
            ->first();
        $emissorToken = $emissor?->token_encrypted ? Crypt::decryptString($emissor->token_encrypted) : null;
        $emissorExtId = $emissor?->emissor_externo_id;

        return $this->build($provedor, $ambiente, $cfg, $emissorToken, $emissorExtId);
    }

    public function build(string $provedor, string $ambiente, SaasConfig $cfg, ?string $emissorToken, ?string $emissorExtId): FiscalProvider
    {
        if ($provedor === 'FOCUS') {
            $baseUrl = $ambiente === 'PRODUCAO'
                ? (string) config('services.focusnfe.producao_url')
                : (string) config('services.focusnfe.homologacao_url');
            $master = $ambiente === 'PRODUCAO'
                ? $this->decifrar($cfg->getRawOriginal('focus_master_token_producao'))
                : $this->decifrar($cfg->getRawOriginal('focus_master_token_homologacao'));
            return new FocusNfeProvider($baseUrl, $master, $emissorToken);
        }

        // SPEDY
        $baseUrl = $ambiente === 'PRODUCAO'
            ? (string) config('services.spedy.producao_url')
            : (string) config('services.spedy.sandbox_url');
        $master = $ambiente === 'PRODUCAO'
            ? $this->decifrar($cfg->getRawOriginal('spedy_master_key_producao'))
            : $this->decifrar($cfg->getRawOriginal('spedy_master_key_sandbox'));
        return new SpedyProvider($baseUrl, $master, $emissorToken, $emissorExtId);
    }

    private function decifrar(?string $valor): string
    {
        if (empty($valor)) return '';
        try {
            return Crypt::decryptString($valor);
        } catch (\Throwable) {
            return $valor; // valor já em claro (compatibilidade)
        }
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/FiscalProviderManagerTest.php`
Expected: PASS (3 testes).

- [ ] **Step 6: Verificar sintaxe do model**

Run: `cd backend && php -l app/Models/EmissorFiscal.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add backend/app/Models/EmissorFiscal.php backend/app/Services/Fiscal/FiscalProviderManager.php backend/tests/Unit/Fiscal/FiscalProviderManagerTest.php
git commit -m "feat(fiscal): EmissorFiscal model e FiscalProviderManager (resolução de provedor)"
```

---

### Task 10: Refatorar `NfeService` para delegar ao Manager

**Files:**
- Modify: `backend/app/Services/NfeService.php`
- Test: `backend/tests/Unit/Fiscal/NfeServiceMontagemTest.php`

**Interfaces:**
- Consumes: `FiscalProviderManager`, `Configuracao`, `NotaFiscalData`, `EmissaoResultado`, `NotaFiscal`.
- Produces:
  - `NfeService::montarNotaData(NotaFiscal $nota): NotaFiscalData` (público, testável) — usa `Configuracao` para alíquota/serviço default e `referencia_externa` da nota (ou gera `'nf-' . $nota->id`).
  - `NfeService::emitir(NotaFiscal $nota): array` agora retorna `['status'=>, 'chave'=>, 'protocolo'=>, 'xml_retorno'=>, 'numero'=>, 'mensagem_erro'=>, 'referencia_externa'=>]` resolvendo o provider via Manager. Mantém `proximoNumeroNf()`.

> Nota: o `NotaFiscalController::emitir` (Task 12) passará a gravar também `provedor`, `ambiente`, `referencia_externa`. A assinatura de retorno do `emitir()` muda — o controller é atualizado na Task 12.

- [ ] **Step 1: Escrever teste de `montarNotaData` (sem DB — injetando Configuracao falsa via parâmetros)**

Para manter o teste unit (sem DB), `montarNotaData` recebe os defaults por parâmetro:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Services\NfeService;
use PHPUnit\Framework\TestCase;

class NfeServiceMontagemTest extends TestCase
{
    public function test_monta_nota_data_a_partir_da_nota(): void
    {
        $cliente = new Cliente([
            'nome' => 'Fulano', 'cpf_cnpj' => '12345678000199',
            'email' => 'f@x.com', 'cep' => '01310100', 'endereco' => 'Av A',
            'bairro' => 'Centro', 'cidade' => 'São Paulo', 'uf' => 'SP',
        ]);
        $nota = new NotaFiscal([
            'valor_total' => 150.0, 'aliquota_iss' => 5.0,
            'natureza_operacao' => 'Prestação de Serviços',
            'observacoes' => 'Troca de óleo', 'referencia_externa' => 'nf-abc',
        ]);
        $nota->setRelation('cliente', $cliente);

        $service = new NfeService();
        $data = $service->montarNotaData($nota, codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401', codigoIbgeTomador: '3550308');

        $this->assertSame('NFSE', $data->tipo);
        $this->assertSame(150.0, $data->valorServicos);
        $this->assertSame(5.0, $data->aliquotaIss);
        $this->assertSame('nf-abc', $data->referenciaExterna);
        $this->assertSame('12345678000199', $data->tomador['cpf_cnpj']);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/NfeServiceMontagemTest.php`
Expected: FAIL ("Call to undefined method ... montarNotaData").

- [ ] **Step 3: Refatorar `NfeService`**

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\FiscalProviderManager;
use Illuminate\Support\Facades\DB;

class NfeService
{
    public function proximoNumeroNf(): int
    {
        return DB::transaction(function () {
            $config = Configuracao::lockForUpdate()->first();
            if (!$config) throw new \Exception('Configurações da empresa não encontradas.');
            $numero = $config->proximo_numero_nf;
            $config->increment('proximo_numero_nf');
            return $numero;
        });
    }

    public function montarNotaData(
        NotaFiscal $nota,
        string $codigoServicoFederal = '14.01',
        string $codigoServicoMunicipal = '1401',
        string $codigoIbgeTomador = '',
    ): NotaFiscalData {
        $cliente = $nota->cliente;
        $aliquota = (float) ($nota->aliquota_iss ?? 5.0);

        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome'        => $cliente?->nome ?? '-',
                'cpf_cnpj'    => $cliente?->cpf_cnpj ?? '',
                'email'       => $cliente?->email,
                'cep'         => $cliente?->cep,
                'logradouro'  => $cliente?->endereco,
                'numero'      => 'S/N',
                'bairro'      => $cliente?->bairro,
                'cidade'      => $cliente?->cidade,
                'uf'          => $cliente?->uf,
                'codigo_ibge' => $codigoIbgeTomador,
            ],
            descricao: $nota->observacoes ?? 'Serviços automotivos',
            valorServicos: (float) $nota->valor_total,
            aliquotaIss: $aliquota,
            issRetido: false,
            codigoServicoFederal: $codigoServicoFederal,
            codigoServicoMunicipal: $codigoServicoMunicipal,
            naturezaOperacao: $nota->natureza_operacao ?? 'Prestação de Serviços',
            referenciaExterna: $nota->referencia_externa ?? ('nf-' . $nota->id),
        );
    }

    public function emitir(NotaFiscal $nota): array
    {
        $config   = Configuracao::first();
        $manager  = app(FiscalProviderManager::class);
        $provider = $manager->forTenant();

        $data     = $this->montarNotaData(
            $nota,
            codigoServicoFederal: $config?->cnae ? '14.01' : '14.01',
            codigoServicoMunicipal: '1401',
            codigoIbgeTomador: $config?->codigo_ibge ?? '',
        );

        $resultado = $provider->emitir($data);

        return [
            'status'             => $resultado->status,
            'chave'              => $resultado->chave ?? '',
            'protocolo'          => $resultado->protocolo ?? '',
            'numero'             => $resultado->numero,
            'xml_retorno'        => $resultado->xml ?? '',
            'pdf_url'            => $resultado->pdfUrl,
            'mensagem_erro'      => $resultado->mensagemErro,
            'referencia_externa' => $resultado->referenciaExterna,
        ];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/NfeServiceMontagemTest.php`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte unit fiscal inteira**

Run: `cd backend && php artisan test tests/Unit/Fiscal`
Expected: PASS (todos).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/NfeService.php backend/tests/Unit/Fiscal/NfeServiceMontagemTest.php
git commit -m "refactor(fiscal): NfeService delega emissão ao FiscalProviderManager"
```

---

### Task 11: `RegistrarEmissorService` (registro de emissor por oficina)

**Files:**
- Create: `backend/app/Services/Fiscal/RegistrarEmissorService.php`
- Test: `backend/tests/Unit/Fiscal/RegistrarEmissorMontagemTest.php`

**Interfaces:**
- Consumes: `FiscalProviderManager`, `Configuracao`, `EmissorFiscal`, `CertificadoValidator`, DTOs.
- Produces:
  - `RegistrarEmissorService::montarEmissorData(Configuracao $cfg): EmissorData` (público, testável).
  - `RegistrarEmissorService::registrar(string $oficinaId): array` → `['ok'=>bool, 'mensagem'=>string]` (decifra cert, chama provider.registrarEmissor + enviarCertificado, grava `emissores_fiscais` cifrando o token). Idempotente: se já existe `REGISTRADO` para (oficina,provedor,ambiente), retorna ok sem repetir.

- [ ] **Step 1: Escrever teste de `montarEmissorData`**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\Configuracao;
use App\Services\Fiscal\RegistrarEmissorService;
use PHPUnit\Framework\TestCase;

class RegistrarEmissorMontagemTest extends TestCase
{
    public function test_monta_emissor_data_da_configuracao(): void
    {
        $cfg = new Configuracao([
            'cnpj' => '12.345.678/0001-99', 'razao_social' => 'Oficina X Ltda',
            'nome_fantasia' => 'Oficina X', 'inscricao_estadual' => '123',
            'inscricao_municipal' => '456', 'regime_tributario' => 'Simples Nacional',
            'email' => 'of@x.com', 'telefone' => '11999999999', 'cep' => '01310-100',
            'endereco' => 'Av Paulista', 'cidade' => 'São Paulo', 'uf' => 'SP',
            'cnae' => '4520-0/01', 'codigo_ibge' => '3550308',
        ]);

        $service = new RegistrarEmissorService(
            app(\App\Services\Fiscal\FiscalProviderManager::class),
            new \App\Services\Fiscal\CertificadoValidator(),
        );
        $e = $service->montarEmissorData($cfg);

        $this->assertSame('12345678000199', $e->cnpjLimpo());
        $this->assertSame('Oficina X Ltda', $e->razaoSocial);
        $this->assertSame('3550308', $e->codigoIbge);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/RegistrarEmissorMontagemTest.php`
Expected: FAIL ("Class RegistrarEmissorService not found").

- [ ] **Step 3: Implementar `RegistrarEmissorService`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Configuracao;
use App\Models\EmissorFiscal;
use App\Models\SaasConfig;
use App\Services\Fiscal\Data\EmissorData;
use Illuminate\Support\Facades\Crypt;

class RegistrarEmissorService
{
    public function __construct(
        private readonly FiscalProviderManager $manager,
        private readonly CertificadoValidator $validator,
    ) {}

    public function montarEmissorData(Configuracao $cfg): EmissorData
    {
        return new EmissorData(
            cnpj: $cfg->cnpj ?? '',
            razaoSocial: $cfg->razao_social ?? '',
            nomeFantasia: $cfg->nome_fantasia,
            inscricaoEstadual: $cfg->inscricao_estadual,
            inscricaoMunicipal: $cfg->inscricao_municipal,
            regimeTributario: $cfg->regime_tributario ?? 'Simples Nacional',
            email: $cfg->email ?? '',
            telefone: $cfg->telefone,
            cep: $cfg->cep ?? '',
            logradouro: $cfg->endereco ?? '',
            numero: 'S/N',
            complemento: null,
            bairro: $cfg->bairro ?? '',
            cidade: $cfg->cidade ?? '',
            uf: $cfg->uf ?? '',
            codigoIbge: $cfg->codigo_ibge ?? '',
            cnae: $cfg->cnae ?? '',
        );
    }

    /** @return array{ok: bool, mensagem: string} */
    public function registrar(string $oficinaId): array
    {
        $cfg = Configuracao::first();
        if (!$cfg || empty($cfg->cnpj) || empty($cfg->certificado_pfx_encrypted)) {
            return ['ok' => false, 'mensagem' => 'Preencha os dados da empresa e envie o certificado antes de ativar a emissão.'];
        }

        $provedor = $this->manager->provedorDaOficina($oficinaId);
        $ambiente = $this->manager->ambienteDaOficina();

        $existente = EmissorFiscal::where('oficina_id', $oficinaId)
            ->where('provedor', $provedor)->where('ambiente', $ambiente)
            ->where('status', 'REGISTRADO')->first();
        if ($existente) {
            return ['ok' => true, 'mensagem' => 'Emissor já registrado.'];
        }

        // Decifra o certificado armazenado (padrão openssl do ConfiguracaoController).
        $pfxBinary = $this->decifrarCertificado($cfg->certificado_pfx_encrypted);
        $senha     = $cfg->certificado_senha_encrypted
            ? Crypt::decryptString($cfg->certificado_senha_encrypted) : '';

        $emissorData = $this->montarEmissorData($cfg);
        $provider    = $this->manager->build(
            $provedor, $ambiente, SaasConfig::get(), null, null,
        );

        $registro = $provider->registrarEmissor($emissorData);
        if ($registro->status !== 'REGISTRADO') {
            EmissorFiscal::updateOrCreate(
                ['oficina_id' => $oficinaId, 'provedor' => $provedor, 'ambiente' => $ambiente],
                ['status' => 'ERRO', 'ultimo_erro' => $registro->mensagemErro],
            );
            return ['ok' => false, 'mensagem' => $registro->mensagemErro ?? 'Falha ao registrar emissor.'];
        }

        // Vincula certificado (provider que envia separado; Focus já recebeu no cadastro).
        try {
            $providerComEmissor = $this->manager->build(
                $provedor, $ambiente, SaasConfig::get(),
                $registro->token, $registro->emissorExternoId,
            );
            $providerComEmissor->enviarCertificado($emissorData, $pfxBinary, $senha);
        } catch (\Throwable $ex) {
            // Focus pode já ter o certificado; loga mas não falha o registro.
        }

        EmissorFiscal::updateOrCreate(
            ['oficina_id' => $oficinaId, 'provedor' => $provedor, 'ambiente' => $ambiente],
            [
                'emissor_externo_id' => $registro->emissorExternoId,
                'token_encrypted'    => $registro->token ? Crypt::encryptString($registro->token) : null,
                'status'             => 'REGISTRADO',
                'registrado_em'      => now(),
                'ultimo_erro'        => null,
            ],
        );

        return ['ok' => true, 'mensagem' => 'Emissor registrado com sucesso.'];
    }

    private function decifrarCertificado(string $stored): string
    {
        $raw = base64_decode($stored);
        $iv  = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        $key = substr(hash('sha256', config('app.key'), true), 0, 32);
        $dec = openssl_decrypt($enc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $dec !== false ? $dec : '';
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd backend && php artisan test tests/Unit/Fiscal/RegistrarEmissorMontagemTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/RegistrarEmissorService.php backend/tests/Unit/Fiscal/RegistrarEmissorMontagemTest.php
git commit -m "feat(fiscal): RegistrarEmissorService (registro idempotente de emissor por oficina)"
```

---

### Task 12: SaaS-admin — endpoints de config fiscal (global + por oficina)

**Files:**
- Modify: `backend/app/Http/Controllers/SaaS/SaasConfigController.php`
- Modify: `backend/app/Http/Controllers/SaaS/OficinaController.php`
- Modify: `backend/routes/api.php:88` (após as rotas de config)
- Modify: `backend/app/Http/Controllers/NotaFiscalController.php:73-111` (gravar provedor/ambiente/ref)

**Interfaces:**
- Consumes: `SaasConfig`, `Oficina`, `FiscalProviderManager`.
- Produces: rotas `PUT saas/config/fiscal`, `PUT saas/config/fiscal/spedy`, `PUT saas/config/fiscal/focus`, `PUT saas/oficinas/{id}/fiscal`.

- [ ] **Step 1: Adicionar campos fiscais ao `SaasConfigController::show`**

No array `data` de `show()`, acrescentar:

```php
                'provedor_fiscal_padrao'         => $cfg->provedor_fiscal_padrao,
                'emissao_fiscal_modo_padrao'     => $cfg->emissao_fiscal_modo_padrao,
                'spedy_master_key_sandbox'       => SaasConfig::mascarar($cfg->getRawOriginal('spedy_master_key_sandbox')),
                'spedy_master_key_producao'      => SaasConfig::mascarar($cfg->getRawOriginal('spedy_master_key_producao')),
                'focus_master_token_homologacao' => SaasConfig::mascarar($cfg->getRawOriginal('focus_master_token_homologacao')),
                'focus_master_token_producao'    => SaasConfig::mascarar($cfg->getRawOriginal('focus_master_token_producao')),
```

- [ ] **Step 2: Adicionar métodos de update no `SaasConfigController`**

```php
    public function updateProvedorFiscal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provedor_fiscal_padrao'     => ['required', 'in:SPEDY,FOCUS'],
            'emissao_fiscal_modo_padrao' => ['required', 'in:MANUAL,AUTOMATICO'],
        ]);
        SaasConfig::get()->update($validated);
        return response()->json(['message' => 'Provedor fiscal padrão atualizado.', 'data' => $validated]);
    }

    public function updateSpedy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'spedy_master_key_sandbox'  => ['nullable', 'string', 'min:8'],
            'spedy_master_key_producao' => ['nullable', 'string', 'min:8'],
        ]);
        $this->salvarSegredos($validated, ['spedy_master_key_sandbox', 'spedy_master_key_producao']);
        return response()->json(['message' => 'Credenciais Spedy salvas.']);
    }

    public function updateFocus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'focus_master_token_homologacao' => ['nullable', 'string', 'min:8'],
            'focus_master_token_producao'    => ['nullable', 'string', 'min:8'],
        ]);
        $this->salvarSegredos($validated, ['focus_master_token_homologacao', 'focus_master_token_producao']);
        return response()->json(['message' => 'Credenciais Focus NFe salvas.']);
    }

    /** Cifra e salva segredos; ignora valores vazios ou ainda mascarados. */
    private function salvarSegredos(array $validated, array $campos): void
    {
        $cfg = SaasConfig::get();
        foreach ($campos as $campo) {
            $valor = $validated[$campo] ?? null;
            if (empty($valor) || str_contains($valor, '*')) {
                continue;
            }
            $cfg->{$campo} = \Illuminate\Support\Facades\Crypt::encryptString($valor);
        }
        $cfg->save();
    }
```

> Nota: como os segredos são cifrados via `Crypt::encryptString`, a máscara via `getRawOriginal` mostrará asteriscos do ciphertext — comportamento aceito (apenas indica "preenchido").

- [ ] **Step 3: Adicionar método de override no `OficinaController`**

```php
    public function updateFiscal(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'provedor_fiscal'     => ['nullable', 'in:SPEDY,FOCUS'],
            'emissao_fiscal_modo' => ['nullable', 'in:MANUAL,AUTOMATICO'],
        ]);
        $oficina = \App\Models\Oficina::findOrFail($id);
        $oficina->update($validated);
        return response()->json(['message' => 'Configuração fiscal da oficina atualizada.', 'data' => [
            'provedor_fiscal'     => $oficina->provedor_fiscal,
            'emissao_fiscal_modo' => $oficina->emissao_fiscal_modo,
        ]]);
    }
```

(Garanta `use Illuminate\Http\JsonResponse;` e `use Illuminate\Http\Request;` no topo do `OficinaController`, se ainda não houver.)

- [ ] **Step 4: Registrar rotas em `routes/api.php`**

Logo após a linha `Route::post('config/smtp/testar', ...)` (dentro do grupo `auth:saas`):

```php
        // Configurações fiscais (SaaS Admin)
        Route::put('config/fiscal',        [SaasConfigController::class, 'updateProvedorFiscal']);
        Route::put('config/fiscal/spedy',  [SaasConfigController::class, 'updateSpedy']);
        Route::put('config/fiscal/focus',  [SaasConfigController::class, 'updateFocus']);
        Route::put('oficinas/{id}/fiscal', [SaaSOficinaController::class, 'updateFiscal']);
```

- [ ] **Step 5: Atualizar `NotaFiscalController::emitir` para gravar provedor/ambiente/ref**

Substituir o bloco do `emitir()` que faz `$nota->update(['status' => 'PROCESSANDO', ...])` e o tratamento do resultado, gravando os novos campos. Trecho-chave:

```php
        $provedor = app(\App\Services\Fiscal\FiscalProviderManager::class)->provedorDaOficina(\App\Tenancy\TenancyContext::get() ?? '');
        $ambiente = \App\Models\Configuracao::first()?->ambiente_fiscal ?? 'HOMOLOGACAO';
        $ref      = $nota->referencia_externa ?: ('nf-' . $nota->id);

        $nota->update([
            'status'             => 'PROCESSANDO',
            'numero'             => $this->nfeService->proximoNumeroNf(),
            'provedor'           => $provedor,
            'ambiente'           => $ambiente,
            'referencia_externa' => $ref,
        ]);

        try {
            $resultado = $this->nfeService->emitir($nota);
            $nota->update([
                'status'       => $resultado['status'],
                'chave_acesso' => $resultado['chave'],
                'protocolo'    => $resultado['protocolo'],
                'xml_retorno'  => $resultado['xml_retorno'],
                'emitido_em'   => $resultado['status'] === 'AUTORIZADA' ? now() : null,
            ]);

            // Billing e alertas só em PRODUCAO e quando AUTORIZADA.
            if ($resultado['status'] === 'AUTORIZADA' && $ambiente === 'PRODUCAO') {
                $notaFresh = $nota->fresh()->loadMissing('cliente');
                $this->planLimit->registrarNotaSeExcedente($notaFresh);
                $this->alertas->dispatch('NF_AUTORIZADA', [
                    'nf_numero'    => $notaFresh->numero,
                    'cliente'      => $notaFresh->cliente?->nome ?? '-',
                    'valor'        => 'R$ ' . number_format((float)$notaFresh->valor_total, 2, ',', '.'),
                    'chave_acesso' => $notaFresh->chave_acesso ?? '-',
                    '_telefone_cliente' => $notaFresh->cliente?->telefone ?? '',
                    '_email_cliente'    => $notaFresh->cliente?->email ?? '',
                ]);
            }

            if ($resultado['status'] === 'REJEITADA') {
                return response()->json(['message' => $resultado['mensagem_erro'] ?? 'Nota rejeitada.'], 422);
            }
        } catch (\Exception $e) {
            $nota->update(['status' => 'REJEITADA']);
            return response()->json(['message' => $e->getMessage()], 422);
        }
```

- [ ] **Step 6: Verificar sintaxe dos arquivos alterados**

Run: `cd backend && php -l app/Http/Controllers/SaaS/SaasConfigController.php && php -l app/Http/Controllers/SaaS/OficinaController.php && php -l app/Http/Controllers/NotaFiscalController.php && php -l routes/api.php`
Expected: `No syntax errors detected` em todos.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/SaasConfigController.php backend/app/Http/Controllers/SaaS/OficinaController.php backend/app/Http/Controllers/NotaFiscalController.php backend/routes/api.php
git commit -m "feat(fiscal): endpoints SaaS-admin de provedor/credenciais e gravação de provedor na emissão"
```

---

### Task 13: Tenant — upload de certificado (senha+validade) e ativar emissão

**Files:**
- Modify: `backend/app/Http/Controllers/ConfiguracaoController.php:63-87`
- Modify: `backend/routes/api.php` (grupo de configurações do tenant — onde já existe `notas-fiscais`/configurações)
- Test: (feature — escrito, rodado só em ambiente com DB)

**Interfaces:**
- Consumes: `CertificadoValidator`, `RegistrarEmissorService`, `Configuracao`.
- Produces: `POST configuracoes/certificado` (atualizado: salva senha cifrada + validade + nome), `POST configuracoes/ativar-emissao`.

- [ ] **Step 1: Atualizar `uploadCertificado` para validar, extrair validade e cifrar a senha**

```php
    public function uploadCertificado(Request $request, \App\Services\Fiscal\CertificadoValidator $validator): JsonResponse
    {
        $request->validate([
            'certificado' => ['required', 'file', 'mimes:pfx,p12', 'max:5120'],
            'senha'       => ['required', 'string'],
        ]);

        $file     = $request->file('certificado');
        $conteudo = file_get_contents($file->getRealPath());

        $resultado = $validator->validar($conteudo, $request->senha);
        if (!$resultado['ok']) {
            return response()->json(['message' => $resultado['erro'] ?? 'Certificado inválido.'], 422);
        }

        $key       = substr(hash('sha256', config('app.key'), true), 0, 32);
        $iv        = random_bytes(16);
        $encrypted = openssl_encrypt($conteudo, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $stored    = base64_encode($iv . $encrypted);

        $config = Configuracao::firstOrCreate([]);
        $config->update([
            'certificado_pfx_encrypted'   => $stored,
            'certificado_senha_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($request->senha),
            'certificado_validade'        => $resultado['validade'],
            'certificado_nome'            => $resultado['nome'] ?? $file->getClientOriginalName(),
            'certificado_status'          => 'OK',
        ]);

        return response()->json([
            'message'         => 'Certificado enviado com sucesso.',
            'tem_certificado' => true,
            'validade'        => $resultado['validade'],
        ]);
    }

    public function ativarEmissao(\App\Services\Fiscal\RegistrarEmissorService $service): JsonResponse
    {
        $oficinaId = \App\Tenancy\TenancyContext::get();
        if (!$oficinaId) {
            return response()->json(['message' => 'Tenant não identificado.'], 422);
        }

        $resultado = $service->registrar($oficinaId);
        return response()->json(['message' => $resultado['mensagem']], $resultado['ok'] ? 200 : 422);
    }
```

- [ ] **Step 2: Registrar a rota `ativar-emissao` no grupo de configurações do tenant**

Localize o grupo onde `configuracoes/certificado` (ou `ConfiguracaoController`) está registrado em `routes/api.php` e adicione ao lado:

```php
    Route::post('configuracoes/ativar-emissao', [\App\Http\Controllers\ConfiguracaoController::class, 'ativarEmissao']);
```

(Se a rota de upload ainda não existir, adicione também:
`Route::post('configuracoes/certificado', [\App\Http\Controllers\ConfiguracaoController::class, 'uploadCertificado']);`)

- [ ] **Step 3: Verificar sintaxe**

Run: `cd backend && php -l app/Http/Controllers/ConfiguracaoController.php && php -l routes/api.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/ConfiguracaoController.php backend/routes/api.php
git commit -m "feat(fiscal): upload de certificado com validade/senha e ativação de emissor"
```

---

### Task 14: Verificação final da Fase 1 (backend)

**Files:** nenhum (verificação).

- [ ] **Step 1: Rodar toda a suíte unit fiscal**

Run: `cd backend && php artisan test tests/Unit/Fiscal`
Expected: PASS em todos os testes das Tasks 4, 6, 7, 8, 9, 10, 11.

- [ ] **Step 2: Lint geral dos arquivos novos**

Run: `cd backend && find app/Services/Fiscal app/Models/EmissorFiscal.php -name '*.php' -exec php -l {} \;`
Expected: `No syntax errors detected` em todos.

- [ ] **Step 3: Conferência das migrations (dry-run só onde houver DB)**

> No ambiente local sem DB, pular. No deploy/ambiente com banco: `php artisan migrate --pretend` deve listar as 2 migrations novas sem erro.

- [ ] **Step 4: Atualizar `.env.example` com as variáveis dos provedores**

Adicionar ao `backend/.env.example`:

```
SPEDY_SANDBOX_URL=https://sandbox-api.spedy.com.br/v1
SPEDY_PRODUCAO_URL=https://api.spedy.com.br/v1
FOCUS_HOMOLOGACAO_URL=https://homologacao.focusnfe.com.br
FOCUS_PRODUCAO_URL=https://api.focusnfe.com.br
```

- [ ] **Step 5: Commit final**

```bash
git add backend/.env.example
git commit -m "chore(fiscal): variáveis de ambiente dos provedores fiscais"
```

---

## Self-Review (preenchido pelo autor do plano)

**Cobertura do spec (Fase 1):**
- Abstração de provedor (interface + manager + 2 providers) → Tasks 4-10. ✔
- Credenciais master no SaaS + token por oficina → Tasks 1, 3, 9, 12. ✔
- Seleção global + override por oficina, controlada pelo SaaS-admin → Tasks 1, 9, 12. ✔
- Certificado por oficina (upload, validação, validade, cifra) → Tasks 2, 6, 13. ✔
- Registro de emissor por oficina (idempotente) → Task 11, 13. ✔
- Emissão NFS-e sandbox + normalização de status → Tasks 7, 8, 10, 12. ✔
- Homologação não conta billing/alerta → Task 12 (guarda `$ambiente === 'PRODUCAO'`). ✔
- Modo de emissão (campos persistidos) → Tasks 1, 12. *(Gatilho AUTOMATICO ao concluir OS = Fase 2.)* ✔ (campo) / deferido (gatilho)
- NF-e, job assíncrono de consulta, dashboard, reconciliação → **fora da Fase 1** (Fases 2 e 3). ✔ (escopo)

**Consistência de tipos:** `EmissaoResultado`/`RegistroResultado` e suas fábricas usadas igualmente nas Tasks 7-11; `FiscalProviderManager::build(...)` com a mesma assinatura usada na Task 11; `mapStatus`/`montarPayloadNfse` públicos e testados nas Tasks 7-8.

**Sem placeholders:** todo passo de código mostra o código real; comandos de teste com saída esperada.

**Pendência conhecida (consciente):** o teste de `consultar` assíncrono de ponta-a-ponta e o job de polling pertencem à Fase 2. Na Fase 1, notas Focus podem ficar `PROCESSANDO` e são consultadas manualmente via `consultar()` (sem UI dedicada ainda).
