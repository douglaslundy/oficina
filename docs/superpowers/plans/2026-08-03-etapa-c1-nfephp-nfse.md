# Etapa C1 — Motor NFePHP: NFS-e via nfse-nacional/nfse-php — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao MecânicaPro um terceiro motor fiscal (NFePHP) capaz de emitir NFS-e pelo padrão nacional (ADN), gratuito e opcional por oficina, começando pela NFS-e (mais simples, sem contingência) — a NF-e via `sped-nfe` (com EPEC) é um plano separado, `2026-08-XX-etapa-c2-nfephp-nfe-epec.md`, feito depois deste.

**Architecture:** Novo `NfePhpProvider` implementa `FiscalProvider` sem mudar a interface — `registrarEmissor()`/`enviarCertificado()` viram validação local de prontidão (nunca chamam nada externo), `emitir()` ramifica por `$nota->modelo` (`NFE` ainda rejeitado com erro claro nesta etapa — só `NFSE` é real). `MotorNfse` monta o DPS e fala com `nfse-nacional/nfse-php`. Certificado nunca é persistido em disco de forma duradoura — `CertificadoStore` decifra do banco em memória; a única exceção é um arquivo temporário efêmero exigido pela própria biblioteca (`NfseContext` recebe um *path*, não bytes — achado desta sessão, diferente do `sped-nfe`), apagado logo após o uso.

**Tech Stack:** Laravel 11 / PHP 8.3, `nfse-nacional/nfse-php` (composer), PostgreSQL 16, PHPUnit.

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo ou modificado.
- Interface `App\Services\Fiscal\Contracts\FiscalProvider` **não muda de assinatura**.
- `NotaFiscalData` só recebe alterações **aditivas** — nada que já existe (usado por `FocusNfeProvider`/`SpedyProvider`) pode mudar de nome/tipo.
- **Certificado `.pfx` nunca fica em disco de forma persistente.** Onde a biblioteca exigir um arquivo (só a `nfse-php`, não o `sped-nfe`), o arquivo é temporário, com permissão restrita, e apagado no `finally` do mesmo método que o criou — nunca sobrevive além de uma única chamada.
- Nunca cair num default/fallback silencioso em decisão fiscal — combinação não coberta lança exceção ou loga warning, nunca um valor chutado (mesmo padrão de `CfopSaidaResolver`/`TributacaoIcmsSaidaResolver` da Etapa B).
- Sem Postgres/Docker disponível localmente — Feature tests devem ser **escritos** mas não podem ser **executados** aqui; só `tests/Unit` (lógica pura) rodam localmente. Nunca rodar testes contra o banco de produção.
- `php -l` em todo arquivo PHP tocado antes de considerar uma task pronta.
- **Fora de escopo desta etapa (decisão confirmada em sessão de brainstorming, 2026-08-03):** `EmissaoOrquestrador` de OS mista, emissão em fila (Horizon), NF-e via NFePHP/EPEC (fica pro plano C2), `DanfseRenderer`/DomPDF (usamos `downloadDanfse()` da própria biblioteca em vez de construir template próprio).

---

### Task 1: Dependência composer + `CrtResolver`

**Files:**
- Modify: `backend/composer.json`
- Create: `backend/app/Services/Fiscal/CrtResolver.php`
- Test: `backend/tests/Unit/Fiscal/CrtResolverTest.php`

**Interfaces:**
- Produces: `App\Services\Fiscal\CrtResolver::resolver(string $regimeTributario): int` — retorna `1` (Simples Nacional), `2` (Simples excesso de sublimite — não aplicável neste projeto, não existe opção de UI pra isso, então nunca é retornado por este método) ou `3` (Regime Normal — Lucro Presumido/Real). Lança `\InvalidArgumentException` se `$regimeTributario` for uma string vazia.

- [ ] **Step 1: Adicionar a dependência**

Run: `cd backend && composer require nfse-nacional/nfse-php`

Confirme que `composer.json` ganhou a linha em `require` e que `composer.lock` foi atualizado. Não há como rodar isso nesta sessão sem acesso à internet do ambiente do implementador — se o comando falhar por rede, reporte BLOCKED com o erro exato, não pule a dependência.

- [ ] **Step 2: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\CrtResolver;
use PHPUnit\Framework\TestCase;

class CrtResolverTest extends TestCase
{
    public function test_simples_nacional_e_crt_1(): void
    {
        $this->assertSame(1, CrtResolver::resolver('Simples Nacional'));
    }

    public function test_lucro_presumido_e_crt_3(): void
    {
        $this->assertSame(3, CrtResolver::resolver('Lucro Presumido'));
    }

    public function test_lucro_real_e_crt_3(): void
    {
        $this->assertSame(3, CrtResolver::resolver('Lucro Real'));
    }

    public function test_regime_vazio_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CrtResolver::resolver('');
    }
}
```

- [ ] **Step 2b: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=CrtResolverTest`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

/**
 * Deriva o Código de Regime Tributário (CRT, usado no leiaute da NF-e/NFS-e)
 * a partir do texto livre já gravado em Configuracao.regime_tributario —
 * mesmo padrão de mapeamento por string que TributacaoIcmsSaidaResolver já
 * usa. Não é um campo novo de banco: é calculado na hora de montar o
 * payload fiscal.
 */
final class CrtResolver
{
    public static function resolver(string $regimeTributario): int
    {
        if (trim($regimeTributario) === '') {
            throw new \InvalidArgumentException('Regime tributário não pode ser vazio para derivar o CRT.');
        }

        return str_contains(strtolower($regimeTributario), 'simples') ? 1 : 3;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=CrtResolverTest`
Expected: PASS (4 testes)

- [ ] **Step 5: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/app/Services/Fiscal/CrtResolver.php backend/tests/Unit/Fiscal/CrtResolverTest.php
git commit -m "feat(fiscal): adiciona nfse-nacional/nfse-php e CrtResolver"
```

---

### Task 2: `CertificadoStore`

**Contexto:** o certificado `.pfx` já é armazenado cifrado em `Configuracao.certificado_pfx_encrypted` (AES-256-CBC, esquema próprio — ver `RegistrarEmissorService::decifrarCertificado()`) e a senha em `certificado_senha_encrypted` (`Crypt::encryptString`/`decryptString` padrão do Laravel). Hoje essa decifragem vive num método `private` de `RegistrarEmissorService`, usado uma única vez no registro. O NFePHP precisa do certificado **a cada emissão** — a lógica sai de lá para um serviço só, sem duplicar.

**Achado desta sessão:** `nfse-nacional/nfse-php`'s `NfseContext` recebe `certificatePath` (um caminho de arquivo), não os bytes do certificado — diferente do `sped-nfe`, cujo `Certificate::readPfx()` aceita o binário direto em memória (relevante só no plano C2, mas o `CertificadoStore` já precisa suportar os dois formatos desde já).

**Files:**
- Create: `backend/app/Services/Fiscal/NfePhp/CertificadoStore.php`
- Modify: `backend/app/Services/Fiscal/RegistrarEmissorService.php:115-123` (extrai `decifrarCertificado` pra um método público reaproveitável, sem mudar o comportamento)
- Test: `backend/tests/Unit/Fiscal/NfePhp/CertificadoStoreTest.php`

**Interfaces:**
- Produces: `App\Services\Fiscal\NfePhp\CertificadoStore::obter(Configuracao $cfg): array{pfx: string, senha: string}` — lança `\RuntimeException` se `certificado_pfx_encrypted`/`certificado_senha_encrypted` estiverem ausentes ou não decifrarem.
- Produces: `App\Services\Fiscal\NfePhp\CertificadoStore::comoArquivoTemporario(Configuracao $cfg, callable $callback): mixed` — decifra, escreve num arquivo temporário com permissão `0600`, chama `$callback(string $caminhoDoArquivo)`, e **sempre** apaga o arquivo (`finally`) antes de retornar o resultado do callback — mesmo se o callback lançar exceção.

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\NfePhp\CertificadoStore;
use PHPUnit\Framework\TestCase;

class CertificadoStoreTest extends TestCase
{
    private function gerarPfxDeTeste(string $senha): string
    {
        $configArgs = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $privateKey = openssl_pkey_new($configArgs);
        $csr = openssl_csr_new(['commonName' => 'Teste'], $privateKey, $configArgs);
        $cert = openssl_csr_sign($csr, null, $privateKey, 365, $configArgs);
        openssl_pkcs12_export($cert, $pfxOut, $privateKey, $senha);
        return $pfxOut;
    }

    private function configuracaoComCertificado(string $senha = 'senha123'): Configuracao
    {
        $pfx = $this->gerarPfxDeTeste($senha);
        $key = substr(hash('sha256', config('app.key'), true), 0, 32);
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($pfx, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        $cfg = new Configuracao();
        $cfg->certificado_pfx_encrypted = base64_encode($iv . $enc);
        $cfg->certificado_senha_encrypted = \Illuminate\Support\Facades\Crypt::encryptString($senha);
        return $cfg;
    }

    public function test_obter_decifra_pfx_e_senha(): void
    {
        $cfg = $this->configuracaoComCertificado('minhasenha');
        $store = new CertificadoStore();

        $resultado = $store->obter($cfg);

        $this->assertSame('minhasenha', $resultado['senha']);
        $this->assertNotEmpty($resultado['pfx']);
        // Confirma que o PFX decifrado é válido de verdade (abre com a senha certa).
        $this->assertTrue(openssl_pkcs12_read($resultado['pfx'], $certs, 'minhasenha'));
    }

    public function test_obter_lanca_excecao_sem_certificado(): void
    {
        $cfg = new Configuracao();
        $store = new CertificadoStore();

        $this->expectException(\RuntimeException::class);
        $store->obter($cfg);
    }

    public function test_como_arquivo_temporario_apaga_arquivo_depois(): void
    {
        $cfg = $this->configuracaoComCertificado('minhasenha');
        $store = new CertificadoStore();
        $caminhoCapturado = null;

        $resultado = $store->comoArquivoTemporario($cfg, function (string $caminho) use (&$caminhoCapturado) {
            $caminhoCapturado = $caminho;
            $this->assertFileExists($caminho);
            return 'ok';
        });

        $this->assertSame('ok', $resultado);
        $this->assertNotNull($caminhoCapturado);
        $this->assertFileDoesNotExist($caminhoCapturado);
    }

    public function test_como_arquivo_temporario_apaga_arquivo_mesmo_se_callback_lancar(): void
    {
        $cfg = $this->configuracaoComCertificado('minhasenha');
        $store = new CertificadoStore();
        $caminhoCapturado = null;

        try {
            $store->comoArquivoTemporario($cfg, function (string $caminho) use (&$caminhoCapturado) {
                $caminhoCapturado = $caminho;
                throw new \RuntimeException('falha simulada');
            });
            $this->fail('Deveria ter propagado a exceção.');
        } catch (\RuntimeException $e) {
            $this->assertSame('falha simulada', $e->getMessage());
        }

        $this->assertNotNull($caminhoCapturado);
        $this->assertFileDoesNotExist($caminhoCapturado);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=CertificadoStoreTest`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Extrair a decifragem existente pra um método público**

Em `backend/app/Services/Fiscal/RegistrarEmissorService.php`, troque a assinatura do método privado:

```php
    private function decifrarCertificado(string $stored): string
```
por
```php
    public static function decifrarPfx(string $stored): string
```

E troque a única chamada interna (`$this->decifrarCertificado($cfg->certificado_pfx_encrypted)`) por `self::decifrarPfx($cfg->certificado_pfx_encrypted)`. Comportamento idêntico, só vira reaproveitável.

- [ ] **Step 4: Implementar `CertificadoStore`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\RegistrarEmissorService;
use Illuminate\Support\Facades\Crypt;

/**
 * Decifra o certificado .pfx da oficina sob demanda, em memória. O .pfx
 * nunca é escrito em disco de forma persistente — comoArquivoTemporario()
 * existe só porque a biblioteca nfse-nacional/nfse-php exige um caminho de
 * arquivo (NfseContext::certificatePath), diferente do sped-nfe (que aceita
 * bytes direto via Certificate::readPfx()). O arquivo temporário é apagado
 * no finally, mesmo se o callback lançar exceção.
 */
class CertificadoStore
{
    /** @return array{pfx: string, senha: string} */
    public function obter(Configuracao $cfg): array
    {
        if (empty($cfg->certificado_pfx_encrypted) || empty($cfg->certificado_senha_encrypted)) {
            throw new \RuntimeException('Certificado digital não configurado para esta oficina.');
        }

        $pfx = RegistrarEmissorService::decifrarPfx($cfg->certificado_pfx_encrypted);
        if ($pfx === '') {
            throw new \RuntimeException('Não foi possível decifrar o certificado armazenado.');
        }

        $senha = Crypt::decryptString($cfg->certificado_senha_encrypted);

        return ['pfx' => $pfx, 'senha' => $senha];
    }

    public function comoArquivoTemporario(Configuracao $cfg, callable $callback): mixed
    {
        $dados = $this->obter($cfg);
        $caminho = tempnam(sys_get_temp_dir(), 'nfephp_cert_');
        if ($caminho === false) {
            throw new \RuntimeException('Não foi possível criar arquivo temporário para o certificado.');
        }

        file_put_contents($caminho, $dados['pfx']);
        chmod($caminho, 0600);

        try {
            return $callback($caminho);
        } finally {
            if (file_exists($caminho)) {
                unlink($caminho);
            }
        }
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=CertificadoStoreTest`
Expected: PASS (4 testes). Rode também `php artisan test --filter=CertificadoValidatorTest` pra confirmar que o refactor do Step 3 não quebrou nada (o teste existente não deveria ter sido tocado, mas confirme).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Fiscal/NfePhp/CertificadoStore.php backend/app/Services/Fiscal/RegistrarEmissorService.php backend/tests/Unit/Fiscal/NfePhp/CertificadoStoreTest.php
git commit -m "feat(fiscal): CertificadoStore decifra pfx sob demanda, com variante de arquivo temporario"
```

---

### Task 3: `EmissaoResultado::erro()` — distinguir falha técnica de rejeição da SEFAZ

**Contexto:** hoje `EmissaoResultado` só tem `AUTORIZADA | PROCESSANDO | REJEITADA | CANCELADA`. `REJEITADA` significa "o fisco recebeu e recusou por regra de negócio" (correção é humana, mostra o motivo). O motor NFePHP precisa de um status técnico distinto — `ERRO` — pra quando a falha é nossa/de infraestrutura (certificado ilegível, exceção inesperada da biblioteca, timeout que não virou EPEC) e não uma recusa do fisco. Sem essa distinção, o usuário veria "REJEITADA" com uma mensagem técnica confusa em vez de "algo deu errado tecnicamente, tente de novo ou chame o suporte".

**Files:**
- Modify: `backend/app/Services/Fiscal/Data/EmissaoResultado.php`
- Modify: `backend/app/Http/Controllers/NotaFiscalController.php:73-126` (`emitir()`)
- Test: `backend/tests/Unit/Fiscal/EmissaoResultadoTest.php` (novo)

**Interfaces:**
- Produces: `EmissaoResultado::erro(string $mensagemErro, ?string $ref = null): self` — status `'ERRO'`.
- Consumes/produces: `NotaFiscalController::emitir()` mapeia `$resultado['status'] === 'ERRO'` pra HTTP 500 com a mensagem (distinto do 422 que já existe pra `REJEITADA`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\EmissaoResultado;
use PHPUnit\Framework\TestCase;

class EmissaoResultadoTest extends TestCase
{
    public function test_erro_tem_status_distinto_de_rejeitada(): void
    {
        $r = EmissaoResultado::erro('Falha técnica ao processar certificado.', 'ref-1');

        $this->assertSame('ERRO', $r->status);
        $this->assertSame('Falha técnica ao processar certificado.', $r->mensagemErro);
        $this->assertSame('ref-1', $r->referenciaExterna);
        $this->assertNotSame(EmissaoResultado::rejeitada('x')->status, $r->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=EmissaoResultadoTest`
Expected: FAIL — método `erro()` não existe.

- [ ] **Step 3: Implementar**

Em `backend/app/Services/Fiscal/Data/EmissaoResultado.php`, adicione ao final da classe:

```php
    public static function erro(string $mensagemErro, ?string $ref = null): self
    {
        return new self('ERRO', null, null, null, null, null, $mensagemErro, $ref);
    }
```

Em `backend/app/Http/Controllers/NotaFiscalController.php::emitir()`, o bloco que hoje trata só `REJEITADA`:

```php
            if ($resultado['status'] === 'REJEITADA') {
                return response()->json(['message' => $resultado['mensagem_erro'] ?? 'Nota rejeitada.'], 422);
            }
```

ganha um irmão logo abaixo:

```php
            if ($resultado['status'] === 'ERRO') {
                return response()->json(['message' => $resultado['mensagem_erro'] ?? 'Falha técnica ao emitir a nota. Tente novamente ou contate o suporte.'], 500);
            }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=EmissaoResultadoTest`
Expected: PASS.

Run: `cd backend && php -l app/Services/Fiscal/Data/EmissaoResultado.php app/Http/Controllers/NotaFiscalController.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Data/EmissaoResultado.php backend/app/Http/Controllers/NotaFiscalController.php backend/tests/Unit/Fiscal/EmissaoResultadoTest.php
git commit -m "feat(fiscal): EmissaoResultado::erro() distingue falha tecnica de rejeicao da SEFAZ"
```

---

### Task 4: `FiscalProviderManager` — registra o `NFEPHP`

**Files:**
- Modify: `backend/app/Services/Fiscal/FiscalProviderManager.php`
- Test: `backend/tests/Unit/Fiscal/FiscalProviderManagerTest.php` (novo — o arquivo pode não existir ainda; confira antes de criar)

**Interfaces:**
- Consumes: `App\Services\Fiscal\Providers\NfePhpProvider` (Task 5 — este task só referencia o nome da classe, que ainda não existe; a Task 5 é sequencialmente dependente desta, então rode as duas juntas se preferir, mas o teste deste task pode usar um double simples se `NfePhpProvider` ainda não existir ao implementar isoladamente — prefira implementar a Task 5 primeiro se a ordem ficar mais natural assim; o importante é que ao final das duas, `build('NFEPHP', ...)` retorne uma instância real).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Models\SaasConfig;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\Fiscal\Providers\NfePhpProvider;
use PHPUnit\Framework\TestCase;

class FiscalProviderManagerTest extends TestCase
{
    public function test_resolver_provedor_aceita_nfephp(): void
    {
        $this->assertSame('NFEPHP', FiscalProviderManager::resolverProvedor('NFEPHP', 'SPEDY'));
    }

    public function test_build_nfephp_retorna_nfe_php_provider(): void
    {
        $manager = new FiscalProviderManager();
        $cfg = new SaasConfig();

        $provider = $manager->build('NFEPHP', 'HOMOLOGACAO', $cfg, null, null);

        $this->assertInstanceOf(NfePhpProvider::class, $provider);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=FiscalProviderManagerTest`
Expected: FAIL — `'NFEPHP'` não está na allowlist, e `NfePhpProvider` não existe ainda (essa segunda falha é esperada até a Task 5 rodar — se você estiver implementando as duas tasks em sequência na mesma sessão, isso é normal).

- [ ] **Step 3: Implementar**

Em `backend/app/Services/Fiscal/FiscalProviderManager.php`, troque:

```php
    private const PROVEDORES = ['SPEDY', 'FOCUS'];
```
por
```php
    private const PROVEDORES = ['SPEDY', 'FOCUS', 'NFEPHP'];
```

E em `build()`, adicione um novo ramo antes do bloco `// SPEDY` final:

```php
        if ($provedor === 'NFEPHP') {
            return new \App\Services\Fiscal\Providers\NfePhpProvider($ambiente);
        }
```

Note que, ao contrário de Focus/Spedy, este `build()` **não recebe `baseUrl`/`masterKey`** — não há serviço externo. `NfePhpProvider` resolve o resto via `CertificadoStore` e `Configuracao` diretamente, na hora de emitir.

- [ ] **Step 4: Run test to verify it passes**

Depois de implementar a Task 5 também: Run: `cd backend && php artisan test --filter=FiscalProviderManagerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/FiscalProviderManager.php backend/tests/Unit/Fiscal/FiscalProviderManagerTest.php
git commit -m "feat(fiscal): FiscalProviderManager registra o provedor NFEPHP"
```

---

### Task 5: `NfePhpProvider` — esqueleto + reinterpretação de `registrarEmissor`/`enviarCertificado`

**Contexto:** `FiscalProvider` pressupõe um provedor externo (`registrarEmissor()` cria a empresa lá e devolve id+token; `enviarCertificado()` faz upload do `.pfx`). O NFePHP não tem nada disso — **nós somos o emissor**. Em vez de mudar a interface (quebraria `SpedyProvider`/`FocusNfeProvider` sem ganho), os dois métodos viram **validação local de prontidão**:

- `registrarEmissor()` → valida que `Configuracao` tem CNPJ, IE, IM, CNAE, código IBGE e regime tributário preenchidos. Retorna `RegistroResultado::ok()` sem token real (usa o próprio CNPJ como `emissorExternoId` e uma string marcadora como token, só pra reaproveitar a tabela `emissores_fiscais` que já espera esse par — nenhum dos dois é usado de verdade em lugar nenhum).
- `enviarCertificado()` → abre o `.pfx` com a senha (via `CertificadoValidator::validar()`, já existente), confere que não está expirado. Não envia nada a lugar nenhum.

**Files:**
- Create: `backend/app/Services/Fiscal/Providers/NfePhpProvider.php`
- Test: `backend/tests/Unit/Fiscal/NfePhpProviderTest.php`

**Interfaces:**
- Consumes: `App\Services\Fiscal\CertificadoValidator::validar(string $pfxBinary, string $senha): array{ok, validade, nome, erro}` (já existe). `App\Services\Fiscal\Data\EmissorData` (já existe, campos: `cnpj`, `razaoSocial`, `nomeFantasia`, `inscricaoEstadual`, `inscricaoMunicipal`, `regimeTributario`, `email`, `telefone`, `cep`, `logradouro`, `numero`, `complemento`, `bairro`, `cidade`, `uf`, `codigoIbge`, `cnae`, método `cnpjLimpo()`).
- Produces: `NfePhpProvider` implementa `FiscalProvider` completo. `emitir(NotaFiscalData $nota)`: se `$nota->modelo === 'NFE'`, retorna `EmissaoResultado::rejeitada(...)` (NF-e real é o plano C2, mesmo padrão de guarda que `SpedyProvider` usa hoje pra NF-e não suportada ainda); se `'NFSE'`, delega pra `MotorNfse` (Task 6).

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Providers\NfePhpProvider;
use PHPUnit\Framework\TestCase;

class NfePhpProviderTest extends TestCase
{
    private function emissorCompleto(): EmissorData
    {
        return new EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina Teste Ltda', nomeFantasia: 'Oficina Teste',
            inscricaoEstadual: '123456789', inscricaoMunicipal: '987654321', regimeTributario: 'Simples Nacional',
            email: 'contato@oficina.com', telefone: '11999999999', cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Bela Vista', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '4520-0/01',
        );
    }

    public function test_registrar_emissor_ok_com_dados_completos(): void
    {
        $p = new NfePhpProvider('HOMOLOGACAO');
        $r = $p->registrarEmissor($this->emissorCompleto());

        $this->assertSame('REGISTRADO', $r->status);
    }

    public function test_registrar_emissor_erro_com_cnae_vazio(): void
    {
        $incompleto = new EmissorData(
            cnpj: '12.345.678/0001-99', razaoSocial: 'Oficina Teste Ltda', nomeFantasia: null,
            inscricaoEstadual: '123456789', inscricaoMunicipal: '987654321', regimeTributario: 'Simples Nacional',
            email: 'contato@oficina.com', telefone: null, cep: '01310-100', logradouro: 'Av Paulista',
            numero: '1000', complemento: null, bairro: 'Bela Vista', cidade: 'São Paulo', uf: 'SP',
            codigoIbge: '3550308', cnae: '',
        );

        $p = new NfePhpProvider('HOMOLOGACAO');
        $r = $p->registrarEmissor($incompleto);

        $this->assertSame('ERRO', $r->status);
        $this->assertStringContainsString('CNAE', $r->mensagemErro);
    }

    public function test_emitir_nfe_ainda_nao_suportado(): void
    {
        $nota = new NotaFiscalData(
            tipo: 'NFSE', tomador: ['nome' => 'Cliente', 'cpf_cnpj' => '12345678000199'],
            descricao: 'Venda', valorServicos: 0.0, aliquotaIss: 0.0, issRetido: false,
            codigoServicoFederal: '', codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria', referenciaExterna: 'nf-1', modelo: 'NFE',
        );

        $p = new NfePhpProvider('HOMOLOGACAO');
        $r = $p->emitir($nota);

        $this->assertSame('REJEITADA', $r->status);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=NfePhpProviderTest`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Implementar**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\CertificadoValidator;
use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use App\Services\Fiscal\NfePhp\MotorNfse;

class NfePhpProvider implements FiscalProvider
{
    public function __construct(
        private readonly string $ambiente,
    ) {}

    /**
     * Reinterpretado: NFePHP não registra empresa em lugar nenhum — isso
     * valida que a Configuracao tem os dados mínimos exigidos pelo leiaute
     * da NF-e/NFS-e antes de "ativar" o provedor pra esta oficina.
     */
    public function registrarEmissor(EmissorData $e): RegistroResultado
    {
        $faltando = [];
        if ($e->cnpjLimpo() === '') $faltando[] = 'CNPJ';
        if (empty($e->inscricaoEstadual)) $faltando[] = 'Inscrição Estadual';
        if (empty($e->inscricaoMunicipal)) $faltando[] = 'Inscrição Municipal';
        if (empty($e->cnae)) $faltando[] = 'CNAE';
        if (empty($e->codigoIbge)) $faltando[] = 'código IBGE do município';
        if (empty($e->regimeTributario)) $faltando[] = 'regime tributário';

        if ($faltando !== []) {
            return RegistroResultado::erro('Complete antes de ativar o NFePHP: ' . implode(', ', $faltando) . '.');
        }

        return RegistroResultado::ok($e->cnpjLimpo(), 'local');
    }

    /**
     * Reinterpretado: não envia nada a lugar nenhum. Só confere que o
     * certificado abre com a senha informada e não está vencido.
     */
    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void
    {
        $validacao = (new CertificadoValidator())->validar($pfxBinary, $senha);
        if (!$validacao['ok']) {
            throw new \RuntimeException($validacao['erro'] ?? 'Certificado inválido.');
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        if ($nota->modelo === 'NFE') {
            return EmissaoResultado::rejeitada(
                'Emissão de NF-e pelo motor NFePHP ainda não disponível neste sistema. Use Focus NFe ou aguarde uma etapa futura.',
                $nota->referenciaExterna,
            );
        }

        return app(MotorNfse::class)->emitir($nota, $this->ambiente);
    }

    public function consultar(string $referencia): EmissaoResultado
    {
        return app(MotorNfse::class)->consultar($referencia, $this->ambiente);
    }

    public function cancelar(string $referencia, string $motivo): EmissaoResultado
    {
        return app(MotorNfse::class)->cancelar($referencia, $motivo, $this->ambiente);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Isso vai falhar até a Task 6 (`MotorNfse`) existir — se estiver implementando em sequência, prossiga pra Task 6 antes de rodar este step. Depois de ambas:

Run: `cd backend && php artisan test --filter=NfePhpProviderTest`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/NfePhpProvider.php backend/tests/Unit/Fiscal/NfePhpProviderTest.php
git commit -m "feat(fiscal): NfePhpProvider reinterpreta registrarEmissor/enviarCertificado como validacao local"
```

---

### Task 6: `MotorNfse` — emissão real via `nfse-nacional/nfse-php`

**Contexto (API confirmada nesta sessão):**
- `new \Nfse\Http\NfseContext(ambiente: \Nfse\Enums\TipoAmbiente::Homologacao|Producao, certificatePath: string, certificatePassword: string, codigoMunicipio: ?string)`
- `$nfse = new \Nfse\Nfse($context)`
- `$nfse->contribuinte()->emitir($dps)` retorna `NfseData`
- `$nfse->contribuinte()->consultar($chave)` 
- `$nfse->contribuinte()->downloadDanfse($chave)` — usado na Task 7, não aqui.
- `\Nfse\Dto\Nfse\DpsData` é montado como array aninhado (`infDPS` com `tpAmb`, `dhEmi`, `verAplic`, `serie`, `nDPS`, `dCompet`, `tpEmit`, `cLocEmi`, `prest` (CNPJ), `toma` (CPF/CNPJ + xNome), `serv.locPrest.cLocPrestacao`, `serv.cServ.cTribNac`/`xDescServ`, `valores.vServPrest.vReceb`/`vServ`, `valores.trib.tribMun.tribISSQN`/`tpRetISSQN`/`pAliq`).
- **Cancelamento**: a doc consultada nesta sessão não confirmou o método/payload exato de cancelamento de NFS-e nacional (schema de "evento"). **Não adivinhe o formato do evento de cancelamento** — antes de implementar o Step 3 deste task, confirme contra a doc real do pacote (`vendor/nfse-nacional/nfse-php/README.md` e a pasta `docs/`, se existir, depois de rodar `composer require`) ou contra o sandbox de homologação. Se não conseguir confirmar, implemente `cancelar()` retornando `EmissaoResultado::erro('Cancelamento de NFS-e via NFePHP ainda não implementado — confirmar formato do evento contra a doc real antes de codificar.')` e registre isso como pendência explícita no relatório, em vez de montar um payload de evento chutado.

**Files:**
- Create: `backend/app/Services/Fiscal/NfePhp/MotorNfse.php`
- Test: `backend/tests/Feature/Fiscal/MotorNfseTest.php` (precisa de Postgres — não roda localmente, escreva mesmo assim)

**Interfaces:**
- Consumes: `App\Services\Fiscal\CrtResolver::resolver()` (Task 1), `App\Services\Fiscal\NfePhp\CertificadoStore::comoArquivoTemporario()` (Task 2), `App\Models\Configuracao` (campos `cnpj`, `codigo_ibge`, `ambiente_fiscal`, `aliquota_iss`, `regime_tributario`).
- Produces: `App\Services\Fiscal\NfePhp\MotorNfse::emitir(NotaFiscalData $nota, string $ambiente): EmissaoResultado`, `::consultar(string $referencia, string $ambiente): EmissaoResultado`, `::cancelar(string $referencia, string $motivo, string $ambiente): EmissaoResultado`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\Configuracao;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotorNfseTest extends TestCase
{
    use RefreshDatabase;

    private function configuracaoValida(): Configuracao
    {
        return Configuracao::create([
            'razao_social' => 'Oficina Teste Ltda', 'cnpj' => '12345678000199',
            'codigo_ibge' => '3550308', 'ambiente_fiscal' => 'HOMOLOGACAO',
            'aliquota_iss' => 5.00, 'regime_tributario' => 'Simples Nacional',
            'proximo_numero_nf' => 1, 'estoque_limite_padrao' => 5, 'alertas_email' => false,
            'certificado_pfx_encrypted' => 'placeholder-precisa-de-certificado-real-de-teste',
            'certificado_senha_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('senha'),
        ]);
    }

    private function notaServico(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678909', 'email' => 'c@x.com'],
            descricao: 'Troca de óleo', valorServicos: 150.00, aliquotaIss: 5.0, issRetido: false,
            codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços', referenciaExterna: 'nfse-1',
        );
    }

    public function test_emitir_monta_dps_com_dados_da_configuracao_e_da_nota(): void
    {
        // Este teste precisa de um certificado .pfx de teste válido gerado em
        // memória (mesmo padrão do CertificadoStoreTest, Task 2) em vez do
        // placeholder acima antes de rodar de verdade — ajuste ao implementar,
        // reaproveitando o helper gerarPfxDeTeste() daquele teste (extraia pra
        // um trait compartilhado se usado em mais de um lugar).
        $this->markTestSkipped(
            'Precisa de certificado .pfx de teste válido e de acesso de rede ' .
            'ao sandbox de homologação da NFS-e nacional — não executável localmente ' .
            '(sem Postgres) nem contra a rede real nesta sessão. Implementar com ' .
            'Http::fake() equivalente para a biblioteca, ou mockar NfseContext/Nfse ' .
            'via injeção de dependência se o pacote permitir — confirmar ao implementar.',
        );
    }
}
```

**Nota sobre este step:** ao contrário de Focus/Spedy (que usam `Illuminate\Support\Facades\Http`, facilmente mockável com `Http::fake()`), `nfse-nacional/nfse-php` provavelmente faz suas próprias chamadas HTTP internamente (Guzzle ou cURL direto), não via `Http` facade do Laravel. **Confirme isso ao instalar o pacote** (Task 1): se a biblioteca aceitar um client HTTP injetado (comum em SDKs bem desenhados), use isso pra mockar nos testes; se não aceitar, os testes de integração real desta camada só são possíveis contra o sandbox de homologação de verdade, e os testes unitários ficam limitados a `MotorNfse::montarDps()` (Step 3, extraído como método isolado testável sem rede).

- [ ] **Step 2: Run test to verify it fails / confirmar abordagem de teste**

Run: `cd backend && php artisan test --filter=MotorNfseTest`
Expected: FAIL — classe não existe ainda. Depois de implementar o Step 3, revisite este teste: se a biblioteca permitir injeção de HTTP client, substitua o `markTestSkipped` por um teste real com fake; se não permitir, mantenha o skip com a razão documentada e garanta que `montarDps()` (método isolado) tenha cobertura unitária real no Step 3b abaixo.

- [ ] **Step 3: Implementar `MotorNfse`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\CrtResolver;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\NotaFiscalData;
use Nfse\Dto\Nfse\DpsData;
use Nfse\Enums\TipoAmbiente;
use Nfse\Http\NfseContext;
use Nfse\Nfse;
use Nfse\Support\IdGenerator;

class MotorNfse
{
    public function __construct(
        private readonly CertificadoStore $certificados = new CertificadoStore(),
    ) {}

    public function emitir(NotaFiscalData $nota, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (!$cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $nota->referenciaExterna);
        }

        try {
            return $this->certificados->comoArquivoTemporario($cfg, function (string $caminhoCertificado) use ($nota, $cfg, $ambiente) {
                $context = new NfseContext(
                    ambiente: $ambiente === 'PRODUCAO' ? TipoAmbiente::Producao : TipoAmbiente::Homologacao,
                    certificatePath: $caminhoCertificado,
                    certificatePassword: $this->certificados->obter($cfg)['senha'],
                    codigoMunicipio: $cfg->codigo_ibge,
                );
                $nfse = new Nfse($context);

                $dps = $this->montarDps($nota, $cfg);

                $resultado = $nfse->contribuinte()->emitir($dps);

                return EmissaoResultado::autorizada(
                    chave: $resultado->chaveAcesso ?? null,
                    protocolo: null, // NFS-e nacional não expõe protocolo distinto da chave — documentar como limitação, não inventar valor
                    numero: $resultado->numero ?? null,
                    xml: $resultado->xml ?? null,
                    pdfUrl: null, // PDF vem sob demanda via downloadDanfse(), não é armazenado (ver Task 7)
                    ref: $nota->referenciaExterna,
                );
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'NFePHP/NFS-e: falha ao emitir.',
                ['erro' => $e->getMessage(), 'ref' => $nota->referenciaExterna],
            );
            return EmissaoResultado::erro('Falha técnica ao emitir NFS-e via NFePHP: ' . $e->getMessage(), $nota->referenciaExterna);
        }
    }

    /**
     * Extraído como método isolado (sem I/O) para ser testável sem rede nem
     * certificado real — só monta a estrutura de dados.
     */
    public function montarDps(NotaFiscalData $nota, Configuracao $cfg): DpsData
    {
        $crt = CrtResolver::resolver($cfg->regime_tributario ?? '');
        $docTomador = preg_replace('/\D/', '', $nota->tomador['cpf_cnpj'] ?? '') ?? '';
        $chaveDocTomador = strlen($docTomador) > 11 ? 'CNPJ' : 'CPF';
        $serie = '1';
        $numero = '1'; // TODO confirmar: numeração de NFS-e nacional é por competência/prestador — ver se precisa de contador próprio antes de ir para produção real, não reaproveitar proximo_numero_nf (que é de Spedy/Focus)

        $idDps = IdGenerator::generateDpsId($cfg->cnpj ?? '', (string) $cfg->codigo_ibge, $serie, $numero);

        return new DpsData([
            '@attributes' => ['versao' => '1.00'],
            'infDPS' => [
                '@attributes' => ['Id' => $idDps],
                'tpAmb'    => $cfg->ambiente_fiscal === 'PRODUCAO' ? 1 : 2,
                'dhEmi'    => now()->format('Y-m-d\TH:i:s'),
                'verAplic' => config('app.version', '1.0.0'),
                'serie'    => $serie,
                'nDPS'     => $numero,
                'dCompet'  => now()->format('Y-m-d'),
                'tpEmit'   => 1,
                'cLocEmi'  => (string) $cfg->codigo_ibge,
                'prest'    => ['CNPJ' => preg_replace('/\D/', '', $cfg->cnpj ?? '')],
                'toma'     => [$chaveDocTomador => $docTomador, 'xNome' => $nota->tomador['nome'] ?? ''],
                'serv'     => [
                    'locPrest' => ['cLocPrestacao' => (string) $cfg->codigo_ibge],
                    'cServ'    => ['cTribNac' => $nota->codigoServicoFederal, 'xDescServ' => $nota->descricao],
                ],
                'valores' => [
                    'vServPrest' => ['vReceb' => $nota->valorServicos, 'vServ' => $nota->valorServicos],
                    'trib' => ['tribMun' => [
                        'tribISSQN'  => 1,
                        'tpRetISSQN' => $nota->issRetido ? 1 : 2,
                        'pAliq'      => $nota->aliquotaIss,
                    ]],
                ],
            ],
        ]);
    }

    public function consultar(string $referencia, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (!$cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $referencia);
        }

        try {
            return $this->certificados->comoArquivoTemporario($cfg, function (string $caminhoCertificado) use ($referencia, $cfg, $ambiente) {
                $context = new NfseContext(
                    ambiente: $ambiente === 'PRODUCAO' ? TipoAmbiente::Producao : TipoAmbiente::Homologacao,
                    certificatePath: $caminhoCertificado,
                    certificatePassword: $this->certificados->obter($cfg)['senha'],
                    codigoMunicipio: $cfg->codigo_ibge,
                );
                $resultado = (new Nfse($context))->contribuinte()->consultar($referencia);

                return EmissaoResultado::autorizada(
                    chave: $resultado->chaveAcesso ?? $referencia,
                    protocolo: null,
                    numero: $resultado->numero ?? null,
                    xml: $resultado->xml ?? null,
                    pdfUrl: null,
                    ref: $referencia,
                );
            });
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao consultar NFS-e: ' . $e->getMessage(), $referencia);
        }
    }

    public function cancelar(string $referencia, string $motivo, string $ambiente): EmissaoResultado
    {
        // Formato do evento de cancelamento da NFS-e nacional não confirmado
        // nesta sessão — não adivinhar o payload. Confirmar contra
        // vendor/nfse-nacional/nfse-php ou sandbox antes de implementar de verdade.
        return EmissaoResultado::erro(
            'Cancelamento de NFS-e via NFePHP ainda não implementado — confirmar formato do evento contra a doc real antes de codificar.',
            $referencia,
        );
    }
}
```

- [ ] **Step 3b: Teste unitário isolado de `montarDps()` (sem I/O, roda localmente de verdade)**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfse;
use PHPUnit\Framework\TestCase;

class MotorNfseMontarDpsTest extends TestCase
{
    public function test_monta_dps_com_dados_da_nota_e_configuracao(): void
    {
        $cfg = new Configuracao();
        $cfg->cnpj = '12345678000199';
        $cfg->codigo_ibge = '3131307'; // Ilicínea/MG
        $cfg->ambiente_fiscal = 'HOMOLOGACAO';
        $cfg->regime_tributario = 'Simples Nacional';

        $nota = new NotaFiscalData(
            tipo: 'NFSE',
            tomador: ['nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678909'],
            descricao: 'Troca de óleo', valorServicos: 150.00, aliquotaIss: 5.0, issRetido: false,
            codigoServicoFederal: '14.01', codigoServicoMunicipal: '1401',
            naturezaOperacao: 'Prestação de Serviços', referenciaExterna: 'nfse-1',
        );

        $motor = new MotorNfse();
        $dps = $motor->montarDps($nota, $cfg);

        // DpsData é um array-like; confirme a forma exata de leitura ao
        // implementar (pode ser ArrayAccess, ou expor os dados via método
        // toArray()/jsonSerialize() — checar a classe real após o composer require).
        $this->assertNotNull($dps);
    }
}
```

Este teste é intencionalmente frouxo na asserção final (`assertNotNull`) porque `DpsData`'s API exata de leitura pós-construção não foi confirmada nesta sessão de pesquisa (só a forma de construção, via README). **Antes de considerar este step concluído**, abra `vendor/nfse-nacional/nfse-php/src/Dto/Nfse/DpsData.php` (depois do `composer require` da Task 1) e substitua o `assertNotNull` por asserções reais nos campos montados (ex.: `$this->assertSame('150.00', $dps->infDPS->valores->vServPrest->vServ)` ou o equivalente que a classe realmente expuser).

- [ ] **Step 4: Verificar sintaxe**

Run: `cd backend && php -l app/Services/Fiscal/NfePhp/MotorNfse.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/NfePhp/MotorNfse.php backend/tests/Feature/Fiscal/MotorNfseTest.php backend/tests/Unit/Fiscal/NfePhp/MotorNfseMontarDpsTest.php
git commit -m "feat(fiscal): MotorNfse emite NFS-e via nfse-nacional/nfse-php"
```

---

### Task 7: PDF da DANFSe via `downloadDanfse()` da biblioteca

**Files:**
- Modify: `backend/app/Http/Controllers/NotaFiscalController.php::pdf()`

**Interfaces:**
- Consumes: `App\Services\Fiscal\NfePhp\MotorNfse` (Task 6).

- [ ] **Step 1: Implementar**

O método `pdf()` atual sempre renderiza via `Pdf::loadView('pdf.nota_fiscal', ...)`. Adicione um desvio no início do método: se `$nota->provedor === 'NFEPHP'` e `$nota->modelo === 'NFS-e'` e `$nota->status === 'AUTORIZADA'`, busca o PDF pronto da biblioteca em vez de renderizar o template local:

```php
    public function pdf(string $id): \Illuminate\Http\Response
    {
        $nota = NotaFiscal::with('cliente')->findOrFail($id);

        if ($nota->provedor === 'NFEPHP' && $nota->status === 'AUTORIZADA' && $nota->chave_acesso) {
            try {
                $pdfBinario = app(\App\Services\Fiscal\NfePhp\MotorNfse::class)
                    ->baixarDanfse($nota->chave_acesso, $nota->ambiente ?? 'HOMOLOGACAO');

                return response($pdfBinario, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="NFSe-' . ($nota->numero ?? $nota->id) . '.pdf"',
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Falha ao baixar DANFSe da biblioteca, caindo para erro explícito.', ['erro' => $e->getMessage()]);
                abort(502, 'Não foi possível obter o PDF da NFS-e no momento. Tente novamente em instantes.');
            }
        }

        $empresa = \App\Models\Configuracao::first()?->toArray() ?? [];

        $pdf = Pdf::loadView('pdf.nota_fiscal', compact('nota', 'empresa'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('NF-' . ($nota->numero ?? $nota->id) . '.pdf');
    }
```

Note que isso referencia um novo método `MotorNfse::baixarDanfse()` que ainda não existe — adicione-o:

```php
    public function baixarDanfse(string $chaveAcesso, string $ambiente): string
    {
        $cfg = Configuracao::first();
        if (!$cfg) {
            throw new \RuntimeException('Configurações da empresa não encontradas.');
        }

        return $this->certificados->comoArquivoTemporario($cfg, function (string $caminhoCertificado) use ($chaveAcesso, $cfg, $ambiente) {
            $context = new \Nfse\Http\NfseContext(
                ambiente: $ambiente === 'PRODUCAO' ? \Nfse\Enums\TipoAmbiente::Producao : \Nfse\Enums\TipoAmbiente::Homologacao,
                certificatePath: $caminhoCertificado,
                certificatePassword: $this->certificados->obter($cfg)['senha'],
                codigoMunicipio: $cfg->codigo_ibge,
            );

            return (new \Nfse\Nfse($context))->contribuinte()->downloadDanfse($chaveAcesso);
        });
    }
```

Adicione esse método em `backend/app/Services/Fiscal/NfePhp/MotorNfse.php` (mesmo arquivo da Task 6).

**Confirme ao implementar** se `downloadDanfse()` retorna os bytes crus do PDF (o que o código acima assume) ou algum wrapper/DTO — ajuste `baixarDanfse()` conforme o retorno real da biblioteca depois do `composer require`.

- [ ] **Step 2: Verificar sintaxe**

Run: `cd backend && php -l app/Http/Controllers/NotaFiscalController.php app/Services/Fiscal/NfePhp/MotorNfse.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/app/Http/Controllers/NotaFiscalController.php backend/app/Services/Fiscal/NfePhp/MotorNfse.php
git commit -m "feat(fiscal): PDF da NFS-e via NFePHP usa downloadDanfse() da biblioteca"
```

---

### Task 8: Regressão + registro de pendências

**Files:**
- Nenhum arquivo novo — verificação + `PROGRESSO.md`.

- [ ] **Step 1: Rodar toda a suite Unit localmente**

Run: `cd backend && php artisan test --testsuite=Unit`
Expected: PASS em tudo, incluindo os testes novos desta etapa e todos os pré-existentes (Etapa A/B).

- [ ] **Step 2: Registrar pendências no `PROGRESSO.md`**

Documente explicitamente, como uma nova rodada: (a) formato do evento de cancelamento de NFS-e nacional não confirmado (`MotorNfse::cancelar()` retorna erro claro em vez de chutar); (b) se a biblioteca aceita HTTP client injetado pra testes com fake, ou se ficou limitado a testes contra sandbox real; (c) numeração de NFS-e nacional (`nDPS`) hardcoded como `'1'` em `montarDps()` — precisa de um esquema de contador próprio antes de qualquer uso real, não reaproveitar `proximo_numero_nf`; (d) `downloadDanfse()` nunca testado contra uma chave de acesso real; (e) Feature tests desta etapa nunca rodaram contra Postgres real.

- [ ] **Step 3: Commit**

```bash
git add PROGRESSO.md
git commit -m "docs: registra pendencias da etapa C1 (cancelamento NFS-e, numeracao, testes contra sandbox)"
```

---

## Self-review

- **Cobertura do spec revisado:** interface `FiscalProvider` não mudou (Task 5) ✓. `registrarEmissor`/`enviarCertificado` reinterpretados como validação local (Task 5) ✓. `NotaFiscalData` não sofreu nenhuma alteração nesta etapa — reaproveitado tal como está (confirmado: nenhuma task modifica esse arquivo) ✓. Certificado nunca persiste em disco além de um arquivo efêmero por chamada (Task 2) ✓. Registro do provedor NFEPHP na allowlist (Task 4) ✓. PDF via biblioteca em vez de DomPDF (Task 7, decisão tomada nesta sessão) ✓.
- **Lacunas conhecidas, deixadas explícitas (não são placeholders — são pontos que genuinamente dependem de confirmar a biblioteca real instalada, mesma disciplina já usada com o Task 7/Spedy da Etapa B):** formato exato do evento de cancelamento de NFS-e nacional; se a biblioteca permite mock de HTTP; retorno exato de `downloadDanfse()`; numeração de DPS além de um valor fixo `'1'`. Cada uma tem uma instrução concreta de como resolver ao implementar (onde olhar, o que não fazer), não um "adicionar tratamento apropriado" vago.
- **Consistência de tipos:** `EmissaoResultado::erro()` (Task 3) usado consistentemente em `MotorNfse` (Task 6) e `NfePhpProvider` (Task 5, via `RegistroResultado::erro()`, já existente, não confundir os dois). `CertificadoStore::obter()`/`comoArquivoTemporario()` (Task 2) consumidos identicamente em `MotorNfse::emitir()`, `::consultar()` e `::baixarDanfse()` (Task 7).
