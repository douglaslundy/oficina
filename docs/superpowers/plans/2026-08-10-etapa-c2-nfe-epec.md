# Etapa C2 — NF-e via NFePHP (sped-nfe) + Contingência EPEC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Emitir NF-e modelo 55 via `nfephp-org/sped-nfe` (SEFAZ-MG), com contingência EPEC quando a transmissão normal falha, reconciliação automática dentro do prazo de 7 dias, DANFE via DomPDF, e numeração própria — tudo estendendo a infraestrutura que a Etapa C1 (NFS-e) já construiu neste mesmo worktree.

**Architecture:** `NfePhpProvider::emitir()`/`consultar()`/`cancelar()` passam a despachar por `$nota->modelo` entre o `MotorNfse` já existente e um novo `MotorNfe`. `MotorNfe` monta o XML com a classe `Make` do `sped-nfe`, tenta a transmissão normal via `Tools::sefazEnviaLote()`, e cai para `Tools::sefazEPEC()` só quando a comunicação falha (nunca por decisão antecipada). Reusa sem alteração `CertificadoStore` (mas via `Certificate::readPfx()` direto, sem o wrapper de arquivo temporário que o NFS-e precisava), `CrtResolver` e `EmissaoResultado::erro()`.

**Tech Stack:** Laravel 11 (PHP 8.2+, `declare(strict_types=1)`), `nfephp-org/sped-nfe` (novo), DomPDF, Next.js 14 (TypeScript).

## Global Constraints

- `declare(strict_types=1)` em todo arquivo PHP novo ou modificado.
- CFOP/CST-CSOSN/origem/CRT nunca caem num default silencioso — combinação não coberta lança `\InvalidArgumentException` (mesmo padrão já usado em `CfopSaidaResolver`/`TributacaoIcmsSaidaResolver`/`CrtResolver`).
- Nunca "chutar" status AUTORIZADA para uma resposta que não reconhecemos — fallback é sempre `ERRO` (mesma regra que `MotorNfse::mapearResultadoConsulta()` já segue).
- Numeração de NF-e (`serie_nfe`/`proximo_numero_nfe`) é isolada de `proximo_numero_nf` (Spedy/Focus) e `serie_dps`/`proximo_numero_dps` (NFS-e nacional) — três contadores independentes.
- IBS/CBS só entra no XML quando `CRT === 3` — Simples Nacional (CRT=1, regime real da oficina) nunca recebe o bloco nesta v1.
- Antes de qualquer retentativa de NF-e (manual ou pela reconciliação agendada), sempre consultar `sefazConsultaChave` primeiro — nunca retransmitir às cegas.
- Emissão continua síncrona dentro da própria requisição — sem fila/Horizon nesta etapa.
- PDF nunca é armazenado — sempre renderizado sob demanda a partir do XML salvo.
- Feature tests (`RefreshDatabase`) não rodam localmente neste projeto (sem Postgres local) — só `php artisan test --testsuite=Unit` roda nesta sessão. Feature tests são escritos e lintados com `php -l`, ficam para rodar em CI/staging.
- **As assinaturas dos métodos de `Tools` usadas neste plano (`sefazEnviaLote`, `sefazConsultaRecibo`, `sefazConsultaChave`, `sefazInutiliza`, `sefazCancela`, `sefazEPEC`) foram confirmadas lendo o código-fonte real do `sped-nfe` nesta sessão de brainstorming — não são suposição.** Já os detalhes de montagem do XML via `Make` (nomes exatos de tags/métodos) e o formato de parsing da resposta SOAP **não foram verificados contra o pacote instalado** — cada task que monta ou interpreta XML instrui explicitamente o implementador a verificar contra `vendor/nfephp-org/sped-nfe/` antes de finalizar, no mesmo padrão que `MotorNfse` já usou com sucesso nesta sessão anterior deste mesmo worktree (ver comentários em `MotorNfse.php` citando "confirmado lendo vendor/...").
- Trabalhar direto no branch/worktree `worktree-etapa-c1-nfephp-nfse` (decisão do usuário) — nunca na `main`.

---

## Contexto de arquivos existentes (não modificar comportamento, só estender)

- `backend/app/Services/Fiscal/Providers/NfePhpProvider.php` — hoje despacha incondicionalmente pra `MotorNfse`, rejeita `modelo === 'NFE'`.
- `backend/app/Services/Fiscal/NfePhp/MotorNfse.php` — padrão de referência pra `MotorNfe` (estrutura de `emitir()`/`consultar()`/`cancelar()`, tratamento de erro, `EmissaoResultado::erro()` vs rejeição).
- `backend/app/Services/Fiscal/NfePhp/CertificadoStore.php` — `obter(Configuracao): array{pfx, senha}` (bytes crus) e `comoArquivoTemporario()` (só usado pela NFS-e, que exige path de arquivo). `MotorNfe` usa só `obter()`.
- `backend/app/Services/Fiscal/CrtResolver.php` — `resolver(string $regimeTributario): int` (1 ou 3).
- `backend/app/Services/NfeService.php` — `proximoNumeroNf()`/`proximoNumeroDps()` (padrão a replicar para `proximoNumeroNfe()`), `montarNotaData()` (já popula `NotaFiscalData::itens[]` quando `modelo === 'NF-e'`).
- `backend/app/Http/Controllers/NotaFiscalController.php` — `emitir()`/`cancelar()`/`pdf()`.
- `backend/app/Models/{Configuracao,NotaFiscal}.php`, `backend/routes/api.php`, `backend/routes/console.php`.
- `backend/app/Models/AlertaConfig.php` — `TIPOS_PRE_DEFINIDOS()`.
- `frontend/components/ui/StatusPill.tsx`.

---

### Task 1: Numeração própria da NF-e via NFePHP

**Files:**
- Create: `backend/database/migrations/2026_08_10_000003_add_nfe_numbering_to_configuracoes_table.php`
- Modify: `backend/app/Models/Configuracao.php`
- Modify: `backend/app/Services/NfeService.php`
- Test: `backend/tests/Feature/NfeServiceTest.php` (se existir; senão criar `backend/tests/Feature/NfeServiceEtapaC2Test.php`)

**Interfaces:**
- Produces: `NfeService::proximoNumeroNfe(): int` — consumida pela Task 4.

- [ ] **Step 1: Criar a migration**

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração própria da NF-e emitida via NFePHP/sped-nfe — separada de
 * proximo_numero_nf (Spedy/Focus) e proximo_numero_dps (NFS-e nacional,
 * Etapa C1). Mesmo raciocínio da migration 2026_08_04_000001: reaproveitar
 * o contador de outro sistema fiscal colidiria numerações de documentos
 * legalmente distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->string('serie_nfe', 3)->default('1');
            $table->integer('proximo_numero_nfe')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn(['serie_nfe', 'proximo_numero_nfe']);
        });
    }
};
```

- [ ] **Step 2: Lint a migration**

Run: `cd backend && php -l database/migrations/2026_08_10_000003_add_nfe_numbering_to_configuracoes_table.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Atualizar `Configuracao::$fillable`**

Adicionar `'serie_nfe'` e `'proximo_numero_nfe'` ao array `$fillable` existente (mesmo lugar onde `'serie_dps'`/`'proximo_numero_dps'` já estão).

- [ ] **Step 4: Adicionar `proximoNumeroNfe()` a `NfeService`**

Adicionar logo após `proximoNumeroDps()`:

```php
    /**
     * Numeração própria da NF-e via NFePHP/sped-nfe — contador separado de
     * proximo_numero_nf (Spedy/Focus) e proximo_numero_dps (NFS-e nacional).
     * Ver migration 2026_08_10_000003.
     */
    public function proximoNumeroNfe(): int
    {
        return DB::transaction(function () {
            $config = Configuracao::lockForUpdate()->first();
            if (!$config) throw new \Exception('Configurações da empresa não encontradas.');
            $numero = $config->proximo_numero_nfe;
            $config->increment('proximo_numero_nfe');
            return $numero;
        });
    }
```

- [ ] **Step 5: Escrever e rodar o teste**

Adicionar a `backend/tests/Feature/NfeServiceTest.php` (ou criar o arquivo se não existir, seguindo o padrão de setUp já usado por `proximoNumeroNf()`/`proximoNumeroDps()` nesse arquivo):

```php
    public function test_proximo_numero_nfe_incrementa_independente_dos_outros_contadores(): void
    {
        $primeiroNfe = $this->service->proximoNumeroNfe();
        $primeiroNf  = $this->service->proximoNumeroNf();
        $segundoNfe  = $this->service->proximoNumeroNfe();

        $this->assertSame(1, $primeiroNfe);
        $this->assertSame(1, $primeiroNf);
        $this->assertSame(2, $segundoNfe);
        $this->assertSame(1, Configuracao::first()->proximo_numero_nf);
        $this->assertSame(3, Configuracao::first()->proximo_numero_nfe);
    }
```

Run: `cd backend && php -l tests/Feature/NfeServiceTest.php`
(Não executável nesta sessão — Feature test precisa de Postgres, mesma limitação de sempre. `php -l` é a verificação disponível.)

- [ ] **Step 6: Lint tudo que mudou**

Run: `cd backend && php -l app/Models/Configuracao.php && php -l app/Services/NfeService.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_08_10_000003_add_nfe_numbering_to_configuracoes_table.php backend/app/Models/Configuracao.php backend/app/Services/NfeService.php backend/tests/Feature/NfeServiceTest.php
git commit -m "feat(nfe-epec): numeracao propria da NF-e via NFePHP (serie_nfe/proximo_numero_nfe)"
```

---

### Task 2: Schema de contingência + status novo no frontend + alerta pré-definido

**Files:**
- Create: `backend/database/migrations/2026_08_10_000004_add_contingencia_desde_to_notas_fiscais_table.php`
- Modify: `backend/app/Models/NotaFiscal.php`
- Modify: `backend/app/Models/AlertaConfig.php`
- Modify: `frontend/components/ui/StatusPill.tsx`

**Interfaces:**
- Produces: `notas_fiscais.contingencia_desde` (timestamptz nullable) — consumida pelas Tasks 4 e 8. Status `CONTINGENCIA` reconhecido no frontend. Tipo de alerta `NF_CONTINGENCIA_PRAZO` — consumido pela Task 8.

- [ ] **Step 1: Criar a migration**

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
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->timestampTz('contingencia_desde')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->dropColumn('contingencia_desde');
        });
    }
};
```

- [ ] **Step 2: Lint a migration**

Run: `cd backend && php -l database/migrations/2026_08_10_000004_add_contingencia_desde_to_notas_fiscais_table.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Atualizar `NotaFiscal::$fillable`**

Adicionar `'contingencia_desde'` ao array `$fillable` (depois de `'pdf_url'`). Adicionar também ao array `$casts`: `'contingencia_desde' => 'datetime'`.

- [ ] **Step 4: Adicionar o pill `CONTINGENCIA` no frontend**

Em `frontend/components/ui/StatusPill.tsx`, adicionar ao `STATUS_MAP` (logo após a linha de `ERRO`):

```tsx
  CONTINGENCIA:     { label: 'Contingência',   cls: 'pill-accent'  },
```

(Âmbar/`pill-accent` — estado válido mas pendente de finalização, não vermelho de erro nem verde de autorizado, mesmo raciocínio já usado pra `PROCESSANDO`.)

- [ ] **Step 5: Registrar o alerta pré-definido `NF_CONTINGENCIA_PRAZO`**

Em `backend/app/Models/AlertaConfig.php`, dentro de `TIPOS_PRE_DEFINIDOS()`, adicionar (mesmo formato de `NF_AUTORIZADA`):

```php
            'NF_CONTINGENCIA_PRAZO'   => ['nome' => 'NF-e em Contingência — Prazo Próximo', 'template' => '⚠️ *NF-e em Contingência*: a NF #{nf_numero} está em modo de contingência (EPEC) desde {contingencia_desde} e precisa ser retransmitida à SEFAZ em até {dias_restantes} dia(s), ou a contingência da oficina inteira é bloqueada.'],
```

Este alerta nasce com `ativo => false` e `enviar_cliente/enviar_mecanico => false` por padrão (mesmo comportamento de `NF_AUTORIZADA`, que não está na lista `$orcamento`) — só alcança os `destinatarios`/`emails` que o admin cadastrar explicitamente, nunca o cliente. Isso é intencional: é um alerta operacional interno da oficina, não uma comunicação com o cliente final.

- [ ] **Step 6: Lint tudo que mudou**

Run: `cd backend && php -l app/Models/NotaFiscal.php && php -l app/Models/AlertaConfig.php`
Run: `cd frontend && npx tsc --noEmit`
Expected: limpos.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_08_10_000004_add_contingencia_desde_to_notas_fiscais_table.php backend/app/Models/NotaFiscal.php backend/app/Models/AlertaConfig.php frontend/components/ui/StatusPill.tsx
git commit -m "feat(nfe-epec): coluna contingencia_desde, pill CONTINGENCIA e alerta NF_CONTINGENCIA_PRAZO"
```

---

### Task 3: `MotorNfe` — montagem pura do XML (sem I/O)

**Files:**
- Create: `backend/app/Services/Fiscal/NfePhp/MotorNfe.php` (só o método `montarNfe()` nesta task — `emitir()`/`consultar()`/`cancelar()` vêm nas Tasks 4/5)
- Test: `backend/tests/Unit/Fiscal/NfePhp/MotorNfeMontarNfeTest.php`

**Interfaces:**
- Consumes: `CrtResolver::resolver()`, `NotaFiscalData` (já populado com `itens[]` quando `modelo === 'NF-e'`, ver `NfeService::montarNotaData()`).
- Produces: `MotorNfe::montarNfe(NotaFiscalData $nota, Configuracao $cfg, string $ambiente, int $numeroNfe, int $serieNfe): string` (XML da NF-e, ainda não assinado) — consumida pela Task 4.

**IMPORTANTE — verificação obrigatória antes de finalizar esta task:** o pacote `nfephp-org/sped-nfe` ainda não está instalado neste worktree (Task 4 o instala). O código abaixo é uma hipótese de trabalho bem fundamentada (baseada no uso documentado da classe `Make` em tutoriais e na estrutura padrão do leiaute NF-e), **não uma transcrição verificada** — ao contrário das assinaturas de `Tools` (essas sim confirmadas lendo o código-fonte real nesta sessão de brainstorming, ver Global Constraints). Antes de considerar esta task pronta, você **precisa**: instalar o pacote primeiro (rode `cd backend && composer require nfephp-org/sped-nfe` — se `composer require` falhar por incompatibilidade de plataforma, mesma situação já documentada no projeto pra outras dependências, use `--ignore-platform-reqs`), depois ler `vendor/nfephp-org/sped-nfe/src/Make.php` e comparar contra o código abaixo — se algum nome de método/tag divergir, corrija e documente a correção num comentário no código (mesmo padrão que `MotorNfse::montarDps()` já usa extensivamente: "Corrigido: o brief tinha X, o vendor real usa Y").

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\CrtResolver;
use App\Services\Fiscal\Data\NotaFiscalData;
use NFePHP\NFe\Make;

/**
 * Monta (mas não assina nem transmite) o XML de uma NF-e via a classe Make
 * do sped-nfe. Extraído como método isolado, sem I/O, pra ser testável sem
 * rede nem certificado — mesmo padrão de MotorNfse::montarDps().
 *
 * IBS/CBS só entra no XML quando CRT === 3 (Regime Normal) — Simples
 * Nacional (CRT=1) fica sem o bloco, dispensado até 04/01/2027 (NT
 * 2025.002-RTC). Ver spec 2026-08-10-etapa-c2-nfe-epec-design.md.
 */
class MotorNfe
{
    public function montarNfe(
        NotaFiscalData $nota,
        Configuracao $cfg,
        string $ambiente,
        int $numeroNfe,
        int $serieNfe,
    ): string {
        $crt = CrtResolver::resolver($cfg->regime_tributario ?? '');

        $make = new Make();

        $make->taginfNFe((object) [
            'versao' => '4.00',
            'Id'     => null, // calculado pela própria Make ao montar a chave de acesso
            'pk_nItem' => '',
        ]);

        $make->tagide((object) [
            'cUF'      => $this->cUfMg(),
            'natOp'    => $nota->naturezaOperacao,
            'mod'      => 55,
            'serie'    => $serieNfe,
            'nNF'      => $numeroNfe,
            'dhEmi'    => now()->format('c'),
            'tpNF'     => 1, // Saída
            'idDest'   => $this->idDest($cfg->uf ?? '', $nota->tomador['uf'] ?? ''),
            'cMunFG'   => $cfg->codigo_ibge,
            'tpImp'    => 1, // DANFE normal, retrato
            'tpEmis'   => 1, // Normal — sobrescrito para 4 (EPEC) pelo chamador quando cai em contingência
            'tpAmb'    => $ambiente === 'PRODUCAO' ? 1 : 2,
            'finNFe'   => 1, // Normal
            'indFinal' => 1, // Consumidor final (venda B2B via NF-e nesta etapa — mesmo público da Etapa B)
            'indPres'  => 1, // Operação presencial
            'procEmi'  => 0, // Emissão de aplicativo do contribuinte
            'verProc'  => config('app.version', '1.0.0'),
        ]);

        $make->tagemit((object) [
            'xNome' => $cfg->razao_social,
            'xFant' => $cfg->nome_fantasia,
            'IE'    => preg_replace('/\D/', '', $cfg->inscricao_estadual ?? ''),
            'CRT'   => $crt,
        ]);

        $make->tagenderEmit((object) [
            'xLgr'    => $cfg->logradouro ?? $cfg->endereco,
            'nro'     => $cfg->numero ?? 'S/N',
            'xBairro' => $cfg->bairro,
            'cMun'    => $cfg->codigo_ibge,
            'xMun'    => $cfg->cidade,
            'UF'      => $cfg->uf,
            'CEP'     => preg_replace('/\D/', '', $cfg->cep ?? ''),
            'cPais'   => '1058',
            'xPais'   => 'Brasil',
        ]);

        $docTomador = preg_replace('/\D/', '', $nota->tomador['cpf_cnpj'] ?? '') ?? '';
        $make->tagdest((object) array_filter([
            (strlen($docTomador) > 11 ? 'CNPJ' : 'CPF') => $docTomador,
            'xNome'   => $nota->tomador['nome'] ?? '',
            'indIEDest' => 9, // Não contribuinte
        ]));

        $make->tagenderDest((object) [
            'xLgr'    => $nota->tomador['logradouro'] ?? 'S/N',
            'nro'     => $nota->tomador['numero'] ?? 'S/N',
            'xBairro' => $nota->tomador['bairro'] ?? '-',
            'cMun'    => $nota->tomador['codigo_ibge'] ?? $cfg->codigo_ibge,
            'xMun'    => $nota->tomador['cidade'] ?? $cfg->cidade,
            'UF'      => $nota->tomador['uf'] ?? $cfg->uf,
            'CEP'     => preg_replace('/\D/', '', $nota->tomador['cep'] ?? '') ?: null,
        ]);

        foreach ($nota->itens as $i => $item) {
            $nItem = $i + 1;

            $make->tagprod((object) [
                'item'    => $nItem,
                'cProd'   => $item['produto_id'],
                'xProd'   => $item['descricao'],
                'NCM'     => $item['ncm'],
                'CFOP'    => $item['cfop'],
                'uCom'    => 'UN',
                'qCom'    => $item['quantidade'],
                'vUnCom'  => $item['valor_unitario'],
                'vProd'   => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                'uTrib'   => 'UN',
                'qTrib'   => $item['quantidade'],
                'vUnTrib' => $item['valor_unitario'],
                'indTot'  => 1,
            ]);

            $tributacaoSt = $item['tributacao_icms'] === 'ST';
            $make->tagICMS((object) array_filter([
                'item'   => $nItem,
                'orig'   => $item['origem'],
                'CST'    => $crt === 1 ? null : $item['cst_csosn'],
                'CSOSN'  => $crt === 1 ? $item['cst_csosn'] : null,
                'modBC'  => $tributacaoSt ? null : 3,
                'vBC'    => $tributacaoSt ? null : round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                'pICMS'  => $tributacaoSt ? null : 0,
                'vICMS'  => $tributacaoSt ? null : 0,
            ], static fn ($v) => $v !== null));

            // IBS/CBS: só emitido quando CRT === 3 (Regime Normal). Simples
            // Nacional (CRT=1, regime real da oficina) fica sem o bloco —
            // dispensado até 04/01/2027 (NT 2025.002-RTC v1.40). Preencher
            // hoje para CRT=1 seria adivinhar regras que a NT explicitamente
            // diz que ainda não foram publicadas.
            if ($crt === 3) {
                // [decisão] Bloco IBS/CBS deliberadamente NÃO implementado
                // nesta v1, mesmo para CRT=3 — a API real do sped-nfe
                // instalado pra esse grupo (nome do método/estrutura) não
                // foi confirmada nesta sessão de brainstorming (NT
                // 2025.002-RTC é recente, 20/05/2026). Implementá-lo às
                // cegas arriscaria montar um XML que a Make aceita mas a
                // SEFAZ rejeita por schema incorreto — pior que não montar
                // nada. Como o regime real da oficina é Simples Nacional
                // (CRT=1, dispensado do bloco até 04/01/2027), este branch
                // nunca é alcançado na prática hoje; documentado como
                // limitação conhecida da v1 (ver spec, "Riscos conhecidos"),
                // não implementado — item pra revisitar antes de atender
                // qualquer oficina em Regime Normal.
            }
        }

        $vProdTotal = collect($nota->itens)->sum(fn ($i) => round((float) $i['quantidade'] * (float) $i['valor_unitario'], 2));
        $make->tagICMSTot((object) [
            'vBC'    => 0,
            'vICMS'  => 0,
            'vICMSDeson' => 0,
            'vFCP'   => 0,
            'vBCST'  => 0,
            'vST'    => 0,
            'vFCPST' => 0,
            'vFCPSTRet' => 0,
            'vProd'  => $vProdTotal,
            'vFrete' => 0,
            'vSeg'   => 0,
            'vDesc'  => 0,
            'vII'    => 0,
            'vIPI'   => 0,
            'vIPIDevol' => 0,
            'vPIS'   => 0,
            'vCOFINS' => 0,
            'vOutro' => 0,
            'vNF'    => $vProdTotal,
        ]);

        $make->tagtransp((object) ['modFrete' => 9]); // Sem frete

        $make->tagpag((object) []);
        $make->tagdetPag((object) [
            'indPag' => 0,
            'tPag'   => '99', // Outros — forma de pagamento livre não afeta cálculo de imposto
            'vPag'   => $vProdTotal,
        ]);

        $xml = $make->getXML();
        if ($xml === false || $make->getErrors() !== []) {
            throw new \RuntimeException('Falha ao montar XML da NF-e: ' . implode('; ', $make->getErrors()));
        }

        return $xml;
    }

    /**
     * Código UF do IBGE para MG — fixo nesta etapa (só SEFAZ-MG, ver spec).
     * Quando multi-UF existir, isso vira uma tabela/config, não uma constante.
     */
    private function cUfMg(): int
    {
        return 31;
    }

    /**
     * 1 = Operação interna, 2 = Interestadual, 3 = Exterior.
     */
    private function idDest(string $ufOrigem, string $ufDestino): int
    {
        if ($ufDestino === '') return 1;
        return strtoupper($ufOrigem) === strtoupper($ufDestino) ? 1 : 2;
    }
}
```

- [ ] **Step 1: Instalar a dependência (necessário mesmo pra rodar `php -l`)**

Run: `cd backend && composer require nfephp-org/sped-nfe`
Se falhar por incompatibilidade de plataforma (mesma situação já documentada neste projeto — dev box local pode ter PHP mais antigo que o declarado em dependências indiretas), rode com `--ignore-platform-reqs`. Confirme a versão instalada com `cat vendor/nfephp-org/sped-nfe/composer.json | head -5`.

- [ ] **Step 2: Verificar a API real da classe `Make` contra o vendor**

Leia `vendor/nfephp-org/sped-nfe/src/Make.php` (e, se existir, algum exemplo em `vendor/nfephp-org/sped-nfe/docs/` ou `tests/` do próprio pacote). Compare método a método contra o código acima. Ajuste qualquer nome de método/propriedade que divergir, documentando a correção com um comentário curto (mesmo padrão de `MotorNfse::montarDps()`). Preste atenção especial em:
- Se `tagide()`/`tagemit()`/`tagdest()`/etc. recebem `object` (`(object)[...]`) ou array associativo puro — os dois padrões existem em versões diferentes de bibliotecas NFePHP-like.
- Se `getXML()` lança exceção em erro ou retorna `false`/string vazia (o código acima assume os dois casos, ajuste se necessário).
- O grupo `IBSCBS` (bloco `// TODO verificar contra vendor` no código acima) — procure por "IBSCBS" no código-fonte; se não existir na versão instalada, documente isso como limitação conhecida no relatório da task (não é bloqueante: CRT=1 nunca chega nesse branch).

- [ ] **Step 3: Escrever o teste (TDD — mas já com o pacote instalado, então pode escrever e rodar direto)**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\MotorNfe;
use PHPUnit\Framework\TestCase;

class MotorNfeMontarNfeTest extends TestCase
{
    private function notaVenda(): NotaFiscalData
    {
        return new NotaFiscalData(
            tipo: 'NFSE',
            tomador: [
                'nome' => 'Cliente Teste', 'cpf_cnpj' => '12345678000199',
                'uf' => 'MG', 'cidade' => 'Ilicínea', 'codigo_ibge' => '3132404',
                'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'cep' => '37275000',
            ],
            descricao: 'Venda de peças',
            valorServicos: 0.0,
            aliquotaIss: 0.0,
            issRetido: false,
            codigoServicoFederal: '',
            codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria',
            referenciaExterna: 'nfe-1',
            modelo: 'NFE',
            itens: [[
                'produto_id' => 'prod-1', 'descricao' => 'Filtro de óleo',
                'ncm' => '84212300', 'cfop' => '5102', 'origem' => 0,
                'tributacao_icms' => 'NORMAL', 'cst_csosn' => '102',
                'quantidade' => 2, 'valor_unitario' => 35.50,
            ]],
        );
    }

    public function test_monta_xml_para_simples_nacional_sem_bloco_ibscbs(): void
    {
        $cfg = new Configuracao([
            'razao_social' => 'Oficina Teste', 'regime_tributario' => 'Simples Nacional',
            'uf' => 'MG', 'codigo_ibge' => '3132404', 'cidade' => 'Ilicínea',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '37275000',
            'inscricao_estadual' => '1234567',
        ]);

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($this->notaVenda(), $cfg, 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<CSOSN>102</CSOSN>', $xml);
        $this->assertStringNotContainsString('<CST>', $xml);
        $this->assertStringContainsString('<CRT>1</CRT>', $xml);
    }

    public function test_uf_diferente_gera_iddest_interestadual(): void
    {
        $cfg = new Configuracao([
            'razao_social' => 'Oficina Teste', 'regime_tributario' => 'Simples Nacional',
            'uf' => 'MG', 'codigo_ibge' => '3132404', 'cidade' => 'Ilicínea',
            'logradouro' => 'Av Central', 'numero' => '100', 'bairro' => 'Centro', 'cep' => '37275000',
            'inscricao_estadual' => '1234567',
        ]);
        $nota = $this->notaVenda();
        // tomador em SP em vez de MG
        $notaInterestadual = new NotaFiscalData(
            tipo: $nota->tipo, tomador: array_merge($nota->tomador, ['uf' => 'SP']),
            descricao: $nota->descricao, valorServicos: $nota->valorServicos,
            aliquotaIss: $nota->aliquotaIss, issRetido: $nota->issRetido,
            codigoServicoFederal: $nota->codigoServicoFederal, codigoServicoMunicipal: $nota->codigoServicoMunicipal,
            naturezaOperacao: $nota->naturezaOperacao, referenciaExterna: $nota->referenciaExterna,
            modelo: $nota->modelo, itens: $nota->itens,
        );

        $motor = new MotorNfe();
        $xml = $motor->montarNfe($notaInterestadual, $cfg, 'HOMOLOGACAO', 1, 1);

        $this->assertStringContainsString('<idDest>2</idDest>', $xml);
    }
}
```

Run: `cd backend && php artisan test tests/Unit/Fiscal/NfePhp/MotorNfeMontarNfeTest.php`
Expected: PASS nos dois testes — mas **o conteúdo exato das asserções de string (`<CSOSN>102</CSOSN>` etc.) pode precisar de ajuste** dependendo de como a `Make` real serializa o XML (algumas implementações usam atributos XML em vez de sub-elementos). Ajuste as asserções pro formato real observado, mantendo a intenção do teste (CSOSN presente/CST ausente pra Simples Nacional; idDest correto pra UF diferente).

- [ ] **Step 4: Lint**

Run: `cd backend && php -l app/Services/Fiscal/NfePhp/MotorNfe.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/app/Services/Fiscal/NfePhp/MotorNfe.php backend/tests/Unit/Fiscal/NfePhp/MotorNfeMontarNfeTest.php
git commit -m "feat(nfe-epec): MotorNfe::montarNfe() - montagem do XML da NF-e via sped-nfe, IBS/CBS gated por CRT"
```

---

### Task 4: `MotorNfe::emitir()` — transmissão real + fallback EPEC

**Files:**
- Modify: `backend/app/Services/Fiscal/NfePhp/MotorNfe.php`
- Test: `backend/tests/Unit/Fiscal/NfePhp/MotorNfeEmitirTest.php`

**Interfaces:**
- Consumes: `MotorNfe::montarNfe()` (Task 3), `CertificadoStore::obter()`, `NfeService::proximoNumeroNfe()` (Task 1).
- Produces: `MotorNfe::emitir(NotaFiscalData $nota, string $ambiente): EmissaoResultado` — consumida pela Task 6 (`NfePhpProvider`).

**IMPORTANTE — mesma ressalva da Task 3**: a montagem do array de configuração `$configJson` passado ao construtor de `Tools`, e o parsing da resposta SOAP de `sefazEnviaLote()`/`sefazConsultaRecibo()` (pra extrair `cStat`, `chNFe`, `nProt`) **não foram verificados contra o vendor real**. As assinaturas dos métodos de `Tools` usadas abaixo **foram** confirmadas (ver Global Constraints do plano). Verifique a estrutura de `$configJson` e o parsing de resposta contra `vendor/nfephp-org/sped-nfe/src/Tools.php` e qualquer classe de resposta/`Standardize` que o pacote ofereça, antes de finalizar.

```php
    public function __construct(
        private readonly CertificadoStore $certificados = new CertificadoStore(),
        private readonly NfeService $numeracao = new NfeService(),
    ) {}

    public function emitir(NotaFiscalData $nota, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $nota->referenciaExterna);
        }

        try {
            $dados       = $this->certificados->obter($cfg);
            $certificate = \NFePHP\Common\Certificate::readPfx($dados['pfx'], $dados['senha']);

            $numeroNfe = $this->numeracao->proximoNumeroNfe();
            $serieNfe  = (int) ($cfg->serie_nfe ?: 1);

            $tools = new \NFePHP\NFe\Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            $xml = $this->montarNfe($nota, $cfg, $ambiente, $numeroNfe, $serieNfe);

            try {
                // indSinc=1: processamento síncrono — resposta já vem com o
                // resultado da autorização, sem precisar de sefazConsultaRecibo()
                // separado. Mesmo nível de "síncrono dentro da requisição" que
                // Spedy/Focus/NFS-e já usam (ver Global Constraints do spec).
                $resp = $tools->sefazEnviaLote([$xml], (string) $numeroNfe, 1);

                return $this->processarRespostaAutorizacao($resp, $nota->referenciaExterna, $xml);
            } catch (\Throwable $eTransmissao) {
                // Falha de COMUNICAÇÃO (timeout/conexão) — nunca decisão
                // antecipada (não consultamos sefazStatus() antes, ver spec).
                // Cai pra EPEC.
                Log::warning(
                    'MotorNfe: falha na transmissão normal, tentando EPEC.',
                    ['erro' => $eTransmissao->getMessage(), 'ref' => $nota->referenciaExterna],
                );

                return $this->tentarEpec($tools, $xml, $nota->referenciaExterna);
            }
        } catch (\Throwable $e) {
            Log::warning('MotorNfe: falha ao emitir.', ['erro' => $e->getMessage(), 'ref' => $nota->referenciaExterna]);
            return EmissaoResultado::erro('Falha técnica ao emitir NF-e via NFePHP: ' . $e->getMessage(), $nota->referenciaExterna);
        }
    }

    /**
     * @return array{tpAmb: int, razaosocial: string, siglaUF: string, cnpj: string, schemes: string, versao: string}
     * VERIFICAR CONTRA O VENDOR: esta é a forma mais comum documentada em
     * exemplos de sped-nfe, mas não foi confirmada nesta sessão contra a
     * versão instalada — o construtor de Tools espera um JSON (string) ou
     * array associativo já decodificado, verificar qual.
     */
    private function configJson(Configuracao $cfg, string $ambiente): string
    {
        return json_encode([
            'atualizacao'  => now()->format('Y-m-d H:i:s'),
            'tpAmb'        => $ambiente === 'PRODUCAO' ? 1 : 2,
            'razaosocial'  => $cfg->razao_social,
            'siglaUF'      => $cfg->uf ?: 'MG',
            'cnpj'         => preg_replace('/\D/', '', $cfg->cnpj ?? ''),
            'schemes'      => 'PL_009_V4',
            'versao'       => '4.00',
        ]) ?: '{}';
    }

    /**
     * Parsing da resposta de sefazEnviaLote() (indSinc=1). VERIFICAR CONTRA
     * O VENDOR: a resposta é uma string XML (assinatura confirmada retorna
     * string) — este método assume que dá pra extrair cStat/chNFe/nProt via
     * SimpleXML direto na resposta; se o pacote oferecer uma classe de
     * resposta estruturada (ex.: algo em NFePHP\Common ou NFePHP\NFe\Common),
     * prefira usá-la em vez de parsear XML cru aqui.
     */
    private function processarRespostaAutorizacao(string $respostaXml, ?string $ref, string $xmlEnviado): EmissaoResultado
    {
        $sxml = @simplexml_load_string($respostaXml);
        if ($sxml === false) {
            return EmissaoResultado::erro('Resposta da SEFAZ não pôde ser interpretada.', $ref);
        }
        $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $cStatLote = (string) ($sxml->xpath('//nfe:cStat')[0] ?? '');
        $protNFe   = $sxml->xpath('//nfe:protNFe') [0] ?? null;

        if ($protNFe !== null) {
            $cStat = (string) ($protNFe->xpath('.//nfe:cStat')[0] ?? '');
            $chNFe = (string) ($protNFe->xpath('.//nfe:chNFe')[0] ?? '');
            $nProt = (string) ($protNFe->xpath('.//nfe:nProt')[0] ?? '');

            // 100 = Autorizado o uso da NF-e (único código de sucesso real).
            if ($cStat === '100') {
                return EmissaoResultado::autorizada(
                    chave: $chNFe,
                    protocolo: $nProt,
                    numero: null, // número já é conhecido (alocado antes de transmitir), controller mantém o valor existente
                    xml: $xmlEnviado,
                    pdfUrl: null,
                    ref: $ref,
                );
            }

            $xMotivo = (string) ($protNFe->xpath('.//nfe:xMotivo')[0] ?? 'Rejeitada pela SEFAZ.');
            return EmissaoResultado::rejeitada("cStat={$cStat}: {$xMotivo}", $ref);
        }

        // Sem protNFe — lote rejeitado antes mesmo de processar a nota
        // individual (erro de schema, duplicidade, etc.).
        return EmissaoResultado::rejeitada("Lote rejeitado (cStat={$cStatLote}).", $ref);
    }

    private function tentarEpec(\NFePHP\NFe\Tools $tools, string $xml, ?string $ref): EmissaoResultado
    {
        try {
            // sped-nfe injeta tpEmis=4/dhCont/xJust no XML internamente ao
            // chamar sefazEPEC() (confirmado no fluxo documentado em
            // docs/Contingency.md do próprio pacote) — não remontamos o XML
            // aqui, passamos o mesmo que já tentamos transmitir.
            $respEpec = $tools->sefazEPEC($xml, config('app.version', '1.0.0'));

            $sxml = @simplexml_load_string($respEpec);
            $sxml?->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
            $cStat = $sxml !== false && $sxml !== null ? (string) ($sxml->xpath('//nfe:cStat')[0] ?? '') : '';

            // EPEC autorizado — códigos possíveis variam por implementação;
            // VERIFICAR CONTRA O VENDOR/DOC OFICIAL o cStat exato de sucesso
            // do evento EPEC (não confirmado nesta sessão além do fluxo
            // geral). Tratamento conservador: só aceita como CONTINGENCIA se
            // reconhecer um cStat de sucesso explícito, senão ERRO (nunca
            // "chuta" sucesso).
            if (in_array($cStat, ['135', '136'], true)) { // 135/136 = códigos usuais de evento vinculado/registrado com sucesso
                return new EmissaoResultado(
                    status: 'CONTINGENCIA',
                    xml: $xml,
                    ref: $ref,
                );
            }

            return EmissaoResultado::erro("EPEC não autorizado (cStat={$cStat}). SEFAZ e EPEC ambos indisponíveis ou rejeitaram.", $ref);
        } catch (\Throwable $e) {
            Log::warning('MotorNfe: falha também no EPEC.', ['erro' => $e->getMessage(), 'ref' => $ref]);
            return EmissaoResultado::erro('SEFAZ indisponível e contingência EPEC também falhou: ' . $e->getMessage(), $ref);
        }
    }
```

**Nota de integração:** `EmissaoResultado` (classe existente, `backend/app/Services/Fiscal/Data/EmissaoResultado.php`) hoje só tem os named constructors `autorizada()`/`processando()`/`rejeitada()`/`cancelada()`/`erro()` — nenhum deles usa o status `'CONTINGENCIA'`. O código acima usa `new EmissaoResultado(status: 'CONTINGENCIA', ...)` diretamente (o construtor é público). Considere adicionar um named constructor `EmissaoResultado::contingencia(string $xml, ?string $ref = null): self` nesta mesma task pra manter o padrão consistente com os outros status — pequeno, mas melhora legibilidade e é onde outros desenvolvedores vão procurar primeiro.

- [ ] **Step 1: Adicionar `EmissaoResultado::contingencia()`**

Em `backend/app/Services/Fiscal/Data/EmissaoResultado.php`, adicionar ao lado de `erro()`:

```php
    public static function contingencia(string $xml, ?string $ref = null): self
    {
        return new self('CONTINGENCIA', null, null, null, $xml, null, null, $ref);
    }
```

E trocar, no código de `tentarEpec()` acima, `new EmissaoResultado(status: 'CONTINGENCIA', xml: $xml, ref: $ref)` por `EmissaoResultado::contingencia($xml, $ref)`.

- [ ] **Step 2: Escrever os testes (RED antes do código acima existir)**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\Data\EmissaoResultado;
use PHPUnit\Framework\TestCase;

class MotorNfeEmitirTest extends TestCase
{
    // Testes de integração real com Tools (que fala com a rede/SEFAZ) não
    // são testáveis sem certificado e credenciais reais — mesma limitação
    // que MotorNfse tem hoje (seus testes cobrem só montarDps() e o
    // mapeamento puro de resultado, nunca emitir() fim-a-fim). Este arquivo
    // cobre só o parsing de resposta (método privado, testado via
    // reflection) e o novo named constructor.

    public function test_emissao_resultado_contingencia(): void
    {
        $r = EmissaoResultado::contingencia('<xml/>', 'ref-1');

        $this->assertSame('CONTINGENCIA', $r->status);
        $this->assertSame('<xml/>', $r->xml);
        $this->assertSame('ref-1', $r->referenciaExterna);
    }

    public function test_processar_resposta_autorizacao_reconhece_cstat_100(): void
    {
        $respostaXml = <<<'XML'
<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe">
  <cStat>103</cStat>
  <protNFe>
    <infProt>
      <cStat>100</cStat>
      <xMotivo>Autorizado o uso da NF-e</xMotivo>
      <chNFe>31260800000000000000550010000000011234567890</chNFe>
      <nProt>135260000000000</nProt>
    </infProt>
  </protNFe>
</retEnviNFe>
XML;

        $motor  = new \App\Services\Fiscal\NfePhp\MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaAutorizacao');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, 'ref-1', '<xml-enviado/>');

        $this->assertSame('AUTORIZADA', $resultado->status);
        $this->assertSame('31260800000000000000550010000000011234567890', $resultado->chave);
        $this->assertSame('135260000000000', $resultado->protocolo);
    }

    public function test_processar_resposta_autorizacao_rejeitada_nao_vira_autorizada(): void
    {
        $respostaXml = <<<'XML'
<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe">
  <cStat>103</cStat>
  <protNFe>
    <infProt>
      <cStat>204</cStat>
      <xMotivo>Rejeição: Duplicidade de NF-e</xMotivo>
    </infProt>
  </protNFe>
</retEnviNFe>
XML;

        $motor  = new \App\Services\Fiscal\NfePhp\MotorNfe();
        $metodo = new \ReflectionMethod($motor, 'processarRespostaAutorizacao');
        $metodo->setAccessible(true);
        $resultado = $metodo->invoke($motor, $respostaXml, 'ref-1', '<xml-enviado/>');

        $this->assertSame('REJEITADA', $resultado->status);
        $this->assertStringContainsString('Duplicidade', $resultado->mensagemErro);
    }
}
```

**Nota:** o XML de exemplo acima (estrutura `retEnviNFe`/`protNFe`/`infProt`) é baseado no leiaute padrão documentado da NF-e (Manual de Orientação do Contribuinte) — verifique se bate com o que `sefazEnviaLote()` realmente devolve na versão instalada (pode ter um namespace ou estrutura de envelope SOAP diferente). Ajuste o fixture do teste e o parsing em `processarRespostaAutorizacao()` juntos, se divergir.

Run: `cd backend && php artisan test tests/Unit/Fiscal/NfePhp/MotorNfeEmitirTest.php`
Expected: RED antes de implementar `emitir()`/`tentarEpec()`/`processarRespostaAutorizacao()`/`configJson()`, GREEN depois.

- [ ] **Step 3: Implementar (código completo já dado acima) e rodar os testes de novo**

Run: `cd backend && php artisan test tests/Unit/Fiscal/NfePhp/MotorNfeEmitirTest.php`
Expected: PASS (ajustando os fixtures/parsing conforme a verificação do Step 2 desta task, se necessário).

- [ ] **Step 4: Lint**

Run: `cd backend && php -l app/Services/Fiscal/NfePhp/MotorNfe.php && php -l app/Services/Fiscal/Data/EmissaoResultado.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/NfePhp/MotorNfe.php backend/app/Services/Fiscal/Data/EmissaoResultado.php backend/tests/Unit/Fiscal/NfePhp/MotorNfeEmitirTest.php
git commit -m "feat(nfe-epec): MotorNfe::emitir() transmite via sped-nfe e cai para EPEC em falha de comunicacao"
```

---

### Task 5: `MotorNfe::consultar()`/`cancelar()` — consulta antes de retentar

**Files:**
- Modify: `backend/app/Services/Fiscal/NfePhp/MotorNfe.php`
- Test: `backend/tests/Unit/Fiscal/NfePhp/MotorNfeConsultarTest.php`

**Interfaces:**
- Produces: `MotorNfe::consultar(string $chave, string $ambiente): EmissaoResultado`, `MotorNfe::cancelar(string $chave, string $motivo, string $protocolo, string $ambiente): EmissaoResultado`, `MotorNfe::retransmitir(NotaFiscal $nota, string $ambiente): EmissaoResultado` — consumidas pela Task 6 (`NfePhpProvider`) e Task 8 (comando de reconciliação).

**Nota de assinatura:** `cancelar()` aqui precisa do `$protocolo` da autorização original (`sefazCancela(string $chave, string $xJust, string $nProt, ...)` — confirmado na Task 3/Global Constraints), diferente da assinatura genérica de `FiscalProvider::cancelar(string $referencia, string $motivo)`. `NfePhpProvider::cancelar()` (Task 6) vai precisar carregar a `NotaFiscal` pra pegar `protocolo` antes de chamar `MotorNfe::cancelar()` — ver essa costura na Task 6.

```php
    public function consultar(string $chave, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $chave);
        }

        try {
            $dados       = $this->certificados->obter($cfg);
            $certificate = \NFePHP\Common\Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new \NFePHP\NFe\Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            $resp = $tools->sefazConsultaChave($chave);
            $sxml = @simplexml_load_string($resp);
            if ($sxml === false) {
                return EmissaoResultado::erro('Resposta da consulta não pôde ser interpretada.', $chave);
            }
            $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
            $cStat = (string) ($sxml->xpath('//nfe:cStat')[0] ?? '');

            // 100 = Autorizado. 101/151 = Cancelado. 217/218 = Não consta (nunca autorizada).
            // VERIFICAR CONTRA A DOC OFICIAL (Manual de Orientação do Contribuinte,
            // Tabela de Status) se estes são exatamente os códigos retornados por
            // sefazConsultaChave() — a consulta por chave usa um conjunto de cStat
            // um pouco diferente do de autorização de lote.
            return match (true) {
                $cStat === '100' => EmissaoResultado::autorizada(
                    chave: $chave, protocolo: (string) ($sxml->xpath('//nfe:nProt')[0] ?? ''),
                    numero: null, xml: null, pdfUrl: null, ref: $chave,
                ),
                in_array($cStat, ['101', '151'], true) => EmissaoResultado::cancelada($chave),
                default => EmissaoResultado::erro("NF-e em status não reconhecido (cStat={$cStat}); não classificamos como autorizada sem confirmação.", $chave),
            };
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao consultar NF-e: ' . $e->getMessage(), $chave);
        }
    }

    public function cancelar(string $chave, string $motivo, string $protocolo, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $chave);
        }

        try {
            $dados       = $this->certificados->obter($cfg);
            $certificate = \NFePHP\Common\Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new \NFePHP\NFe\Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            $resp = $tools->sefazCancela($chave, $motivo, $protocolo);
            $sxml = @simplexml_load_string($resp);
            $sxml?->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
            $cStat = $sxml !== false && $sxml !== null ? (string) ($sxml->xpath('//nfe:cStat')[0] ?? '') : '';

            if (in_array($cStat, ['135', '136', '155'], true)) { // Evento de cancelamento vinculado/registrado
                return EmissaoResultado::cancelada($chave);
            }

            return EmissaoResultado::erro("Cancelamento não confirmado (cStat={$cStat}).", $chave);
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao cancelar NF-e: ' . $e->getMessage(), $chave);
        }
    }

    /**
     * Retransmite uma NF-e em CONTINGENCIA — usada pela reconciliação
     * agendada (Task 8) e por um reenvio manual futuro. [decisão do spec]
     * Consulta sefazConsultaChave() PRIMEIRO: se já está autorizada (a
     * transmissão pode ter chegado apesar do timeout que causou o EPEC),
     * apenas concilia localmente em vez de reenviar às cegas — mesma lição
     * das rodadas 7/8 do fluxo de pagamento (ack perdido não significa que o
     * efeito não aconteceu).
     */
    public function retransmitir(NotaFiscal $nota, string $ambiente): EmissaoResultado
    {
        if (empty($nota->chave_acesso)) {
            return EmissaoResultado::erro('NF-e em contingência sem chave de acesso — não é possível retransmitir.', $nota->referencia_externa);
        }

        $statusAtual = $this->consultar($nota->chave_acesso, $ambiente);
        if ($statusAtual->status === 'AUTORIZADA') {
            return $statusAtual; // já autorizada de verdade — só concilia, não reenvia.
        }

        if (empty($nota->xml_retorno)) {
            return EmissaoResultado::erro('NF-e em contingência sem XML salvo — não é possível retransmitir.', $nota->referencia_externa);
        }

        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $nota->referencia_externa);
        }

        try {
            $dados       = $this->certificados->obter($cfg);
            $certificate = \NFePHP\Common\Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new \NFePHP\NFe\Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            // Reenvia o MESMO xml salvo (com tpEmis=4 já embutido pelo EPEC
            // original) — nunca remontamos o XML aqui. A confirmar em
            // homologação (ver spec Seção C): a retransmissão pós-EPEC
            // mantém tpEmis=4, já que a chave de acesso codifica o tipo de
            // emissão e remontar mudaria a chave já impressa no DANFE.
            $resp = $tools->sefazEnviaLote([$nota->xml_retorno], (string) $nota->numero, 1);

            return $this->processarRespostaAutorizacao($resp, $nota->referencia_externa, $nota->xml_retorno);
        } catch (\Throwable $e) {
            Log::warning('MotorNfe: falha ao retransmitir NF-e em contingência.', ['erro' => $e->getMessage(), 'nota_id' => $nota->id]);
            return EmissaoResultado::erro('Falha ao retransmitir: ' . $e->getMessage(), $nota->referencia_externa);
        }
    }
```

- [ ] **Step 1: Escrever os testes**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Models\NotaFiscal;
use App\Services\Fiscal\NfePhp\MotorNfe;
use PHPUnit\Framework\TestCase;

class MotorNfeConsultarTest extends TestCase
{
    public function test_retransmitir_sem_chave_de_acesso_retorna_erro(): void
    {
        $nota = new NotaFiscal(['referencia_externa' => 'nfe-sem-chave']);
        $motor = new MotorNfe();

        $resultado = $motor->retransmitir($nota, 'HOMOLOGACAO');

        $this->assertSame('ERRO', $resultado->status);
        $this->assertStringContainsString('sem chave de acesso', (string) $resultado->mensagemErro);
    }

    public function test_retransmitir_sem_xml_salvo_retorna_erro_apos_consultar(): void
    {
        // Nota tem chave mas nunca vai conseguir consultar de verdade sem
        // certificado real neste ambiente de teste — o método vai falhar em
        // consultar() primeiro e cair no catch de erro técnico, que também é
        // um resultado seguro (nunca reenvia às cegas). Este teste confirma
        // que o caminho "sem xml_retorno" não é alcançado silenciosamente
        // quando não há certificado configurado (cenário real de CI sem
        // Configuracao completa).
        $nota = new NotaFiscal(['referencia_externa' => 'nfe-2', 'chave_acesso' => str_repeat('1', 44)]);
        $motor = new MotorNfe();

        $resultado = $motor->retransmitir($nota, 'HOMOLOGACAO');

        $this->assertContains($resultado->status, ['ERRO']);
    }
}
```

Run: `cd backend && php artisan test tests/Unit/Fiscal/NfePhp/MotorNfeConsultarTest.php`
Expected: PASS (estes dois testes não dependem de rede/certificado real — cobrem só os guard clauses iniciais de `retransmitir()`).

- [ ] **Step 2: Implementar (código já dado acima)**

- [ ] **Step 3: Rodar os testes de novo**

Run: `cd backend && php artisan test tests/Unit/Fiscal/NfePhp/MotorNfeConsultarTest.php`
Expected: PASS

- [ ] **Step 4: Lint**

Run: `cd backend && php -l app/Services/Fiscal/NfePhp/MotorNfe.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/NfePhp/MotorNfe.php backend/tests/Unit/Fiscal/NfePhp/MotorNfeConsultarTest.php
git commit -m "feat(nfe-epec): MotorNfe::consultar/cancelar/retransmitir - sempre consulta antes de reenviar"
```

---

### Task 6: `NfePhpProvider` — dispatch por modelo

**Files:**
- Modify: `backend/app/Services/Fiscal/Providers/NfePhpProvider.php`
- Modify: `backend/app/Services/Fiscal/Contracts/FiscalProvider.php`
- Test: `backend/tests/Unit/Fiscal/NfePhpProviderTest.php`

**Interfaces:**
- Consumes: `MotorNfe::emitir()/consultar()/cancelar()` (Tasks 4/5).
- Produces: `NfePhpProvider::emitir()` despacha por modelo; `consultar()`/`cancelar()` ganham `$modelo` — mesma extensão de interface que a feature NFC-e já aplicou na `main` (ver Global Constraints).

**IMPORTANTE:** este worktree forkou da `main` antes da feature NFC-e (desenvolvida em paralelo, na `main`, fora deste plano) ter estendido `FiscalProvider::consultar()`/`cancelar()` com um parâmetro `$modelo`. Como este worktree tem só dois providers reais (`NfePhpProvider` e o que a Etapa B já deixou em `FocusNfeProvider`/`SpedyProvider`, que aqui ainda usam a assinatura antiga de 1 parâmetro), esta task precisa tocar nos TRÊS arquivos de provider pra manter a interface e as implementações em sincronia — mesmo problema de compatibilidade de assinatura PHP (LSP) que a feature NFC-e encontrou e resolveu na `main`. Adicionar o parâmetro como **opcional com default**, preservando o comportamento de todo chamador existente.

- [ ] **Step 1: Atualizar a interface `FiscalProvider`**

Trocar:
```php
    public function consultar(string $referencia): EmissaoResultado;
    public function cancelar(string $referencia, string $motivo): EmissaoResultado;
```
por:
```php
    /** $modelo ('NFSE'|'NFE') decide o motor/endpoint quando o provedor distingue por tipo de documento. */
    public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado;
    public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado;
```

- [ ] **Step 2: Ajuste mecânico (assinatura só) em `FocusNfeProvider`/`SpedyProvider`**

Em `backend/app/Services/Fiscal/Providers/FocusNfeProvider.php` e `SpedyProvider.php`, adicionar `string $modelo = 'NFSE'` às assinaturas de `consultar()`/`cancelar()` — **corpo dos métodos intocado** (mesmo tratamento que a feature NFC-e já aplicou nesses dois arquivos na `main`; aqui é só pra manter a interface compilável, os dois providers continuam só emitindo NFS-e/NF-e via Spedy/Focus, sem NFePHP).

- [ ] **Step 3: Atualizar `NfePhpProvider`**

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
use App\Services\Fiscal\NfePhp\MotorNfe;
use App\Services\Fiscal\NfePhp\MotorNfse;

class NfePhpProvider implements FiscalProvider
{
    public function __construct(
        private readonly string $ambiente,
    ) {}

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

    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void
    {
        $validacao = (new CertificadoValidator())->validar($pfxBinary, $senha);
        if (!$validacao['ok']) {
            throw new \RuntimeException($validacao['erro'] ?? 'Certificado inválido.');
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        return $nota->modelo === 'NFE'
            ? app(MotorNfe::class)->emitir($nota, $this->ambiente)
            : app(MotorNfse::class)->emitir($nota, $this->ambiente);
    }

    public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado
    {
        return $modelo === 'NFE'
            ? app(MotorNfe::class)->consultar($referencia, $this->ambiente)
            : app(MotorNfse::class)->consultar($referencia, $this->ambiente);
    }

    public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado
    {
        if ($modelo === 'NFE') {
            // MotorNfe::cancelar() exige o protocolo original (sefazCancela()
            // não aceita só a chave) — NfePhpProvider não tem acesso à
            // NotaFiscal aqui (só à referência/motivo, mesma limitação da
            // interface genérica). O controller precisa buscar o protocolo
            // e usar uma via alternativa — ver Task 7 pra como isso é
            // resolvido no NotaFiscalController::cancelar().
            throw new \RuntimeException('Cancelamento de NF-e via NfePHP requer o protocolo original — chame MotorNfe::cancelar() diretamente a partir do controller, não via FiscalProvider::cancelar().');
        }

        return app(MotorNfse::class)->cancelar($referencia, $motivo, $this->ambiente);
    }
}
```

- [ ] **Step 2: Escrever os testes**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Providers\NfePhpProvider;
use PHPUnit\Framework\TestCase;

class NfePhpProviderTest extends TestCase
{
    public function test_emitir_com_modelo_nfe_nao_lanca_mais_rejeicao_fixa(): void
    {
        // Antes desta task, emitir(modelo=NFE) sempre retornava REJEITADA
        // com uma mensagem fixa de "ainda não disponível". Este teste
        // documenta que esse comportamento antigo foi removido — não
        // afirma que a emissão real funciona sem certificado/rede (isso é
        // testado em MotorNfeEmitirTest), só que o dispatch chega até lá.
        $nota = new NotaFiscalData(
            tipo: 'NFSE', tomador: ['nome' => 'Cliente', 'cpf_cnpj' => '12345678000199'],
            descricao: 'Venda', valorServicos: 0.0, aliquotaIss: 0.0, issRetido: false,
            codigoServicoFederal: '', codigoServicoMunicipal: '',
            naturezaOperacao: 'Venda de Mercadoria', referenciaExterna: 'nfe-1', modelo: 'NFE',
        );

        $provider = new NfePhpProvider('HOMOLOGACAO');
        $resultado = $provider->emitir($nota);

        // Sem Configuracao/certificado no ambiente de teste, o resultado
        // real vem de MotorNfe::emitir() -> "Configurações da empresa não
        // encontradas" (ERRO) — não mais a REJEITADA fixa antiga.
        $this->assertNotSame('Emissão de NF-e pelo motor NFePHP ainda não disponível neste sistema. Use Focus NFe ou aguarde uma etapa futura.', $resultado->mensagemErro);
    }
}
```

Run: `cd backend && php artisan test tests/Unit/Fiscal/NfePhpProviderTest.php`
Expected: PASS

- [ ] **Step 3: Lint tudo que mudou**

Run: `cd backend && php -l app/Services/Fiscal/Providers/NfePhpProvider.php app/Services/Fiscal/Providers/FocusNfeProvider.php app/Services/Fiscal/Providers/SpedyProvider.php app/Services/Fiscal/Contracts/FiscalProvider.php`
Expected: `No syntax errors detected` em todos. Rode também `cd backend && php artisan test tests/Unit/Fiscal/` (suíte inteira) pra confirmar que a mudança de interface não quebrou `FocusNfeProviderTest`/`SpedyProviderTest`/`MotorNfseTest`/etc. existentes.

- [ ] **Step 4: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/NfePhpProvider.php backend/app/Services/Fiscal/Providers/FocusNfeProvider.php backend/app/Services/Fiscal/Providers/SpedyProvider.php backend/app/Services/Fiscal/Contracts/FiscalProvider.php backend/tests/Unit/Fiscal/NfePhpProviderTest.php
git commit -m "feat(nfe-epec): NfePhpProvider despacha NF-e/NFS-e por modelo; consultar/cancelar ganham \$modelo"
```

---

### Task 7: `DanfeRenderer` + wiring no controller (PDF + cancelamento)

**Files:**
- Create: `backend/app/Services/Fiscal/Pdf/DanfeRenderer.php`
- Create: `backend/resources/views/pdf/danfe.blade.php`
- Modify: `backend/app/Http/Controllers/NotaFiscalController.php`
- Test: `backend/tests/Unit/Fiscal/Pdf/DanfeRendererTest.php`

**Interfaces:**
- Consumes: `NotaFiscal::xml_retorno` (XML já salvo da NF-e autorizada).
- Produces: `DanfeRenderer::render(NotaFiscal $nota): string` (HTML) — consumido por `NotaFiscalController::pdf()`.

- [ ] **Step 1: Criar `DanfeRenderer`**

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

use App\Models\NotaFiscal;

/**
 * Extrai os dados do XML já autorizado (fonte da verdade — nunca dados
 * salvos separadamente que podem desatualizar) e monta o HTML do DANFE
 * pra ser passado ao DomPDF, seguindo o padrão de pdf.nota_fiscal.blade.php
 * já usado no resto do projeto.
 *
 * Escopo desta v1: layout funcional (dados legíveis, chave de acesso,
 * itens, totais), não uma réplica pixel-perfect do Anexo II do MOC. Código
 * de barras Code-128C da chave é o item mais visível ainda faltando —
 * registrado como limitação conhecida (ver spec, "Custo registrado").
 */
class DanfeRenderer
{
    public function dadosParaTemplate(NotaFiscal $nota): array
    {
        $sxml = null;
        if (!empty($nota->xml_retorno)) {
            $sxml = @simplexml_load_string($nota->xml_retorno);
            $sxml?->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
        }

        $itens = [];
        if ($sxml !== null && $sxml !== false) {
            foreach ($sxml->xpath('//nfe:det') as $det) {
                $prod = $det->prod;
                $itens[] = [
                    'descricao' => (string) ($prod->xProd ?? ''),
                    'ncm'       => (string) ($prod->NCM ?? ''),
                    'cfop'      => (string) ($prod->CFOP ?? ''),
                    'quantidade' => (string) ($prod->qCom ?? ''),
                    'valor_unitario' => (string) ($prod->vUnCom ?? ''),
                    'valor_total' => (string) ($prod->vProd ?? ''),
                ];
            }
        }

        // Fallback pros itens locais (notas_fiscais_itens) se o XML não
        // estiver disponível/parseável — nunca deixar o DANFE vazio quando
        // temos os dados de outra fonte confiável.
        if ($itens === [] && $nota->relationLoaded('itens')) {
            $itens = $nota->itens->map(fn ($i) => [
                'descricao' => $i->descricao, 'ncm' => $i->ncm, 'cfop' => $i->cfop,
                'quantidade' => (string) $i->quantidade, 'valor_unitario' => (string) $i->valor_unitario,
                'valor_total' => (string) $i->valor_total,
            ])->all();
        }

        return [
            'nota'  => $nota,
            'itens' => $itens,
        ];
    }
}
```

- [ ] **Step 2: Criar o template**

```blade
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; padding: 24px; }
  .header { border-bottom: 2px solid #f5a623; padding-bottom: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; }
  .logo { font-size: 20px; font-weight: 900; color: #f5a623; }
  .nf-number { font-size: 22px; font-weight: 900; text-align: right; }
  .section { margin-bottom: 20px; }
  .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 10px; padding-bottom: 4px; border-bottom: 1px solid #eee; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #f5f5f5; padding: 6px; text-align: left; font-size: 9px; font-weight: 700; text-transform: uppercase; border-bottom: 1px solid #ddd; }
  td { padding: 6px; font-size: 10px; border-bottom: 1px solid #eee; }
  .chave { font-size: 9px; word-break: break-all; color: #666; margin-top: 8px; padding: 8px; background: #f5f5f5; border-radius: 4px; text-align: center; }
  .contingencia-badge { display: inline-block; padding: 4px 10px; background: #f5a623; color: #000; font-weight: 700; font-size: 10px; border-radius: 4px; margin-bottom: 8px; }
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="logo">MecânicaPro</div>
    <div style="font-size:11px; color:#555;">NF-e emitida via NFePHP</div>
  </div>
  <div style="text-align:right;">
    <div class="nf-number">NF-e Nº {{ $nota->numero ?? 'RASCUNHO' }}</div>
    <div style="font-size:11px; color:#555;">Série: {{ $nota->serie ?? '1' }}</div>
  </div>
</div>

@if($nota->status === 'CONTINGENCIA')
<div class="contingencia-badge">DOCUMENTO EMITIDO EM CONTINGÊNCIA (EPEC) — AGUARDANDO RETRANSMISSÃO</div>
@endif

<div class="section">
  <div class="section-title">Destinatário</div>
  <p>{{ $nota->cliente->nome ?? 'N/A' }} — {{ $nota->cliente->cpf_cnpj ?? '-' }}</p>
</div>

<div class="section">
  <div class="section-title">Itens</div>
  <table>
    <thead>
      <tr><th>Descrição</th><th>NCM</th><th>CFOP</th><th>Qtd</th><th>Vl. Unit.</th><th>Vl. Total</th></tr>
    </thead>
    <tbody>
      @foreach($itens as $item)
      <tr>
        <td>{{ $item['descricao'] }}</td>
        <td>{{ $item['ncm'] }}</td>
        <td>{{ $item['cfop'] }}</td>
        <td>{{ $item['quantidade'] }}</td>
        <td>{{ number_format((float)$item['valor_unitario'], 2, ',', '.') }}</td>
        <td>{{ number_format((float)$item['valor_total'], 2, ',', '.') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>

<div class="section">
  <div class="section-title">Total</div>
  <p style="font-size:16px; font-weight:900;">R$ {{ number_format((float)($nota->valor_total ?? 0), 2, ',', '.') }}</p>
</div>

@if($nota->chave_acesso)
<div class="chave">Chave de Acesso: {{ $nota->chave_acesso }}</div>
@endif

</body>
</html>
```

- [ ] **Step 3: Escrever o teste**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\Pdf;

use App\Models\NotaFiscal;
use App\Services\Fiscal\Pdf\DanfeRenderer;
use PHPUnit\Framework\TestCase;

class DanfeRendererTest extends TestCase
{
    public function test_dados_para_template_sem_xml_retorna_itens_vazios(): void
    {
        $nota = new NotaFiscal(['numero' => 1, 'valor_total' => 100]);
        $nota->setRelation('itens', collect());

        $renderer = new DanfeRenderer();
        $dados = $renderer->dadosParaTemplate($nota);

        $this->assertSame([], $dados['itens']);
        $this->assertSame($nota, $dados['nota']);
    }
}
```

Run: `cd backend && php artisan test tests/Unit/Fiscal/Pdf/DanfeRendererTest.php`
Expected: PASS

- [ ] **Step 4: Wiring no `NotaFiscalController::pdf()`**

Adicionar um novo branch, antes do branch existente de NFS-e via NFePHP (mesmo `if ($nota->provedor === 'NFEPHP' ...)`):

```php
        if ($nota->provedor === 'NFEPHP' && in_array($nota->modelo, ['NF-e', 'NFC-e'], true) && in_array($nota->status, ['AUTORIZADA', 'CONTINGENCIA'], true)) {
            $nota->loadMissing(['cliente', 'itens']);
            $dados = app(\App\Services\Fiscal\Pdf\DanfeRenderer::class)->dadosParaTemplate($nota);
            $pdf = Pdf::loadView('pdf.danfe', $dados)->setPaper('a4', 'portrait');

            return $pdf->download('DANFE-' . ($nota->numero ?? $nota->id) . '.pdf');
        }
```

(Nota: `'NFC-e'` no `in_array` acima é só defensivo/futuro — esta etapa não emite NFC-e via NFePHP, NFC-e já foi resolvida separadamente via Spedy/Focus na `main`. Mantido só pra não confundir se um dia o modelo aparecer aqui; não afeta nada nesta etapa.)

- [ ] **Step 5: Wiring no `NotaFiscalController::cancelar()`**

O `cancelar()` atual só marca `status='CANCELADA'` local, sem chamar provedor nenhum (limitação pré-existente documentada e já registrada como fora de escopo em outra feature). Para NF-e via NFePHP especificamente, cancelar de verdade é uma chamada local barata (não uma API remota) — vale a pena fazer certo aqui em vez de herdar a mesma lacuna:

```php
    public function cancelar(Request $request, string $id): JsonResponse
    {
        $nota = NotaFiscal::findOrFail($id);
        $request->validate(['motivo' => ['required', 'string', 'min:10']]);

        if ($nota->provedor === 'NFEPHP' && $nota->modelo === 'NF-e' && $nota->status === 'AUTORIZADA') {
            if (empty($nota->chave_acesso) || empty($nota->protocolo)) {
                return response()->json(['message' => 'NF-e sem chave de acesso ou protocolo — não é possível cancelar via NFePHP.'], 422);
            }

            $ambiente  = $nota->ambiente ?? 'HOMOLOGACAO';
            $resultado = app(\App\Services\Fiscal\NfePhp\MotorNfe::class)
                ->cancelar($nota->chave_acesso, $request->motivo, $nota->protocolo, $ambiente);

            if ($resultado->status !== 'CANCELADA') {
                return response()->json(['message' => $resultado->mensagemErro ?? 'Falha ao cancelar NF-e.'], 422);
            }
        }

        $nota->update(['status' => 'CANCELADA']);
        return response()->json(['message' => 'NF cancelada com sucesso.']);
    }
```

- [ ] **Step 6: Lint**

Run: `cd backend && php -l app/Services/Fiscal/Pdf/DanfeRenderer.php app/Http/Controllers/NotaFiscalController.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/Fiscal/Pdf/DanfeRenderer.php backend/resources/views/pdf/danfe.blade.php backend/app/Http/Controllers/NotaFiscalController.php backend/tests/Unit/Fiscal/Pdf/DanfeRendererTest.php
git commit -m "feat(nfe-epec): DanfeRenderer + wiring de pdf()/cancelar() para NF-e via NFePHP"
```

---

### Task 8: Comando de reconciliação de contingência (prazo de 7 dias)

**Files:**
- Create: `backend/app/Console/Commands/ReconciliarContingenciaNfe.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Unit/Fiscal/PrazoContingenciaTest.php`

**Interfaces:**
- Consumes: `MotorNfe::retransmitir()` (Task 5), `AlertaDispatchService::dispatch()`, `NF_CONTINGENCIA_PRAZO` (Task 2).

- [ ] **Step 1: Extrair o cálculo do prazo (testável sem I/O)**

Novo arquivo `backend/app/Services/Fiscal/PrazoContingencia.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use Carbon\CarbonInterface;

/**
 * Cálculo puro do prazo de 7 dias da contingência EPEC — extraído do
 * comando agendado pra ser testável sem I/O. Ver spec Seção C: se o XML
 * normal não for transmitido em até 7 dias, a SEFAZ bloqueia novos EPEC.
 */
final class PrazoContingencia
{
    private const PRAZO_DIAS = 7;
    private const ALERTA_DIAS_RESTANTES = 2;

    public static function diasRestantes(CarbonInterface $contingenciaDesde, CarbonInterface $agora): int
    {
        $prazoFinal = $contingenciaDesde->copy()->addDays(self::PRAZO_DIAS);
        return max(0, (int) $agora->diffInDays($prazoFinal, false));
    }

    public static function precisaAlertar(CarbonInterface $contingenciaDesde, CarbonInterface $agora): bool
    {
        $restantes = self::diasRestantes($contingenciaDesde, $agora);
        return $restantes <= self::ALERTA_DIAS_RESTANTES;
    }
}
```

- [ ] **Step 2: Escrever o teste**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\PrazoContingencia;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class PrazoContingenciaTest extends TestCase
{
    public function test_dias_restantes_no_inicio_da_contingencia(): void
    {
        $inicio = Carbon::parse('2026-08-01 10:00:00');
        $agora  = Carbon::parse('2026-08-01 10:00:00');

        $this->assertSame(7, PrazoContingencia::diasRestantes($inicio, $agora));
    }

    public function test_precisa_alertar_a_2_dias_do_prazo(): void
    {
        $inicio = Carbon::parse('2026-08-01 10:00:00');
        $agora  = Carbon::parse('2026-08-06 10:00:00'); // 5 dias depois, 2 restantes

        $this->assertTrue(PrazoContingencia::precisaAlertar($inicio, $agora));
    }

    public function test_nao_precisa_alertar_com_mais_de_2_dias_restantes(): void
    {
        $inicio = Carbon::parse('2026-08-01 10:00:00');
        $agora  = Carbon::parse('2026-08-03 10:00:00'); // 2 dias depois, 5 restantes

        $this->assertFalse(PrazoContingencia::precisaAlertar($inicio, $agora));
    }

    public function test_prazo_estourado_retorna_zero_nao_negativo(): void
    {
        $inicio = Carbon::parse('2026-08-01 10:00:00');
        $agora  = Carbon::parse('2026-08-15 10:00:00'); // muito depois do prazo

        $this->assertSame(0, PrazoContingencia::diasRestantes($inicio, $agora));
    }
}
```

Run: `cd backend && php artisan test tests/Unit/Fiscal/PrazoContingenciaTest.php`
Expected: RED (classe não existe) → implementar → GREEN.

- [ ] **Step 3: Criar o comando**

```php
<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotaFiscal;
use App\Models\Oficina;
use App\Services\AlertaDispatchService;
use App\Services\Fiscal\FiscalProviderManager;
use App\Services\Fiscal\NfePhp\MotorNfe;
use App\Services\Fiscal\PrazoContingencia;
use App\Tenancy\TenancyContext;
use Illuminate\Console\Command;

class ReconciliarContingenciaNfe extends Command
{
    protected $signature   = 'nfe:reconciliar-contingencia';
    protected $description = 'Retransmite NF-e em contingência EPEC e alerta antes do prazo de 7 dias';

    public function __construct(
        private readonly MotorNfe $motor,
        private readonly AlertaDispatchService $alertaDispatch,
        private readonly FiscalProviderManager $providerManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $oficinas = Oficina::whereIn('status', ['ATIVA', 'TRIAL'])->get();
        $totalRetransmitidas = 0;
        $totalAlertadas = 0;

        foreach ($oficinas as $oficina) {
            TenancyContext::set($oficina->id, $oficina->slug);

            $notasEmContingencia = NotaFiscal::where('status', 'CONTINGENCIA')
                ->whereNotNull('contingencia_desde')
                ->get();

            $ambiente = $this->providerManager->ambienteDaOficina();

            foreach ($notasEmContingencia as $nota) {
                $resultado = $this->motor->retransmitir($nota, $ambiente);

                if ($resultado->status === 'AUTORIZADA') {
                    $nota->update([
                        'status'             => 'AUTORIZADA',
                        'chave_acesso'       => $resultado->chave ?: $nota->chave_acesso,
                        'protocolo'          => $resultado->protocolo ?: $nota->protocolo,
                        'xml_retorno'        => $resultado->xml ?: $nota->xml_retorno,
                        'contingencia_desde' => null,
                        'emitido_em'         => now(),
                    ]);
                    $totalRetransmitidas++;
                    continue;
                }

                $diasRestantes = PrazoContingencia::diasRestantes($nota->contingencia_desde, now());
                if (PrazoContingencia::precisaAlertar($nota->contingencia_desde, now())) {
                    $this->alertaDispatch->dispatch('NF_CONTINGENCIA_PRAZO', [
                        'nf_numero'          => $nota->numero,
                        'contingencia_desde' => $nota->contingencia_desde->format('d/m/Y H:i'),
                        'dias_restantes'     => $diasRestantes,
                    ]);
                    $totalAlertadas++;
                }
            }

            TenancyContext::clear();
        }

        $this->info("Contingência reconciliada: {$totalRetransmitidas} retransmitida(s), {$totalAlertadas} alerta(s) disparado(s).");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Registrar no agendador**

Em `backend/routes/console.php`, adicionar (mesmo padrão `->timezone('America/Sao_Paulo')` dos outros 3 comandos já existentes — lição da rodada 13, sem isso o agendador nunca dispara na hora certa):

```php
Schedule::command('nfe:reconciliar-contingencia')->hourly()->timezone('America/Sao_Paulo');
```

- [ ] **Step 5: Lint**

Run: `cd backend && php -l app/Services/Fiscal/PrazoContingencia.php app/Console/Commands/ReconciliarContingenciaNfe.php routes/console.php`
Expected: `No syntax errors detected` em todos.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Fiscal/PrazoContingencia.php backend/app/Console/Commands/ReconciliarContingenciaNfe.php backend/routes/console.php backend/tests/Unit/Fiscal/PrazoContingenciaTest.php
git commit -m "feat(nfe-epec): comando agendado nfe:reconciliar-contingencia (prazo de 7 dias, hourly)"
```

---

### Task 9: Inutilização de numeração — endpoint + botão mínimo

**Files:**
- Modify: `backend/app/Http/Controllers/NotaFiscalController.php`
- Modify: `backend/routes/api.php`
- Modify: `frontend/app/(dashboard)/fiscal/historico/page.tsx` (ou tela equivalente de configuração fiscal, verificar qual já existe pra ações administrativas de NF-e)
- Test: `backend/tests/Unit/Fiscal/NfePhp/MotorNfeInutilizarTest.php`

**Interfaces:**
- Produces: `MotorNfe::inutilizar(int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): EmissaoResultado`, `POST /notas-fiscais/inutilizar-numeracao`.

- [ ] **Step 1: Adicionar `MotorNfe::inutilizar()`**

```php
    /**
     * Inutiliza uma faixa de numeração não usada (queda de processo entre
     * alocar o número e transmitir, ver spec Seção B). Ação administrativa
     * pontual, não parte do fluxo normal de emissão.
     */
    public function inutilizar(int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.');
        }

        try {
            $dados       = $this->certificados->obter($cfg);
            $certificate = \NFePHP\Common\Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new \NFePHP\NFe\Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            $resp = $tools->sefazInutiliza($serie, $numeroInicial, $numeroFinal, $justificativa);
            $sxml = @simplexml_load_string($resp);
            $sxml?->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
            $cStat = $sxml !== false && $sxml !== null ? (string) ($sxml->xpath('//nfe:cStat')[0] ?? '') : '';

            // 102 = Inutilização homologada. VERIFICAR CONTRA A DOC OFICIAL.
            if ($cStat === '102') {
                return EmissaoResultado::cancelada(); // reusa o status "efeito concluído" — inutilização não é bem "cancelada" mas também não é nenhum dos outros status existentes; ver nota abaixo
            }

            return EmissaoResultado::erro("Inutilização não homologada (cStat={$cStat}).");
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao inutilizar numeração: ' . $e->getMessage());
        }
    }
```

**Nota de simplificação [decisão]:** reusar `EmissaoResultado::cancelada()` pra sinalizar sucesso da inutilização é um ligeiro abuso semântico (inutilização não é "nota cancelada"), mas criar um status novo só pra essa ação administrativa pontual (que não persiste como uma `NotaFiscal`, ver Step 2) seria over-engineering — o controller só precisa saber "deu certo" ou "deu erro com mensagem X", que os dois status já cobrem.

- [ ] **Step 2: Endpoint no controller**

Adicionar a `NotaFiscalController`:

```php
    public function inutilizarNumeracao(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'serie'          => ['required', 'integer', 'min:1'],
            'numero_inicial' => ['required', 'integer', 'min:1'],
            'numero_final'   => ['required', 'integer', 'gte:numero_inicial'],
            'justificativa'  => ['required', 'string', 'min:15'],
        ]);

        $ambiente = \App\Models\Configuracao::first()?->ambiente_fiscal ?? 'HOMOLOGACAO';

        $resultado = app(\App\Services\Fiscal\NfePhp\MotorNfe::class)->inutilizar(
            $validated['serie'], $validated['numero_inicial'], $validated['numero_final'],
            $validated['justificativa'], $ambiente,
        );

        if ($resultado->status !== 'CANCELADA') {
            return response()->json(['message' => $resultado->mensagemErro ?? 'Falha ao inutilizar numeração.'], 422);
        }

        return response()->json(['message' => 'Faixa de numeração inutilizada com sucesso.']);
    }
```

- [ ] **Step 3: Rota**

Em `backend/routes/api.php`, logo após a linha de `downloadZip`:

```php
    Route::post('notas-fiscais/inutilizar-numeracao', [NotaFiscalController::class, 'inutilizarNumeracao']);
```

- [ ] **Step 4: Botão mínimo no frontend**

Verificar qual tela já concentra ações administrativas fiscais neste worktree (procurar por uma tela de "Configurações Fiscais" ou similar em `frontend/app/(dashboard)/`, já que este worktree pode ter páginas diferentes da `main` nesse ponto). Adicionar um formulário mínimo (série, número inicial, número final, justificativa, botão "Inutilizar") chamando `POST /notas-fiscais/inutilizar-numeracao` — reusar os componentes de input/toast já padronizados no projeto (mesmo `iStyle`/`lStyle`/`toast()` já usados em `NotaFiscalForm.tsx`). Este é intencionalmente um formulário simples de uso raro (não uma tela nova de destaque), consistente com a decisão do spec ("não é uma classe de domínio grande, só um botão").

- [ ] **Step 5: Escrever o teste**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\NfePhp\MotorNfe;
use PHPUnit\Framework\TestCase;

class MotorNfeInutilizarTest extends TestCase
{
    public function test_inutilizar_sem_configuracao_retorna_erro(): void
    {
        $motor = new MotorNfe();
        $resultado = $motor->inutilizar(1, 10, 12, 'Falha no processo antes da transmissão', 'HOMOLOGACAO');

        $this->assertSame('ERRO', $resultado->status);
    }
}
```

Run: `cd backend && php artisan test tests/Unit/Fiscal/NfePhp/MotorNfeInutilizarTest.php`
Expected: PASS (nenhuma `Configuracao` existe no ambiente de teste local sem banco — mas este teste específico só precisa que `Configuracao::first()` retorne null, que é o comportamento real quando não há registro, não depende de rede).

- [ ] **Step 6: Lint**

Run: `cd backend && php -l app/Services/Fiscal/NfePhp/MotorNfe.php app/Http/Controllers/NotaFiscalController.php routes/api.php`
Run: `cd frontend && npx tsc --noEmit`
Expected: limpos.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/Fiscal/NfePhp/MotorNfe.php backend/app/Http/Controllers/NotaFiscalController.php backend/routes/api.php backend/tests/Unit/Fiscal/NfePhp/MotorNfeInutilizarTest.php
git commit -m "feat(nfe-epec): endpoint de inutilizacao de numeracao (sefazInutiliza) + botao minimo"
```

(O commit do arquivo frontend do Step 4 depende de qual arquivo/tela foi identificado nesse step — incluir no mesmo commit acima.)

---

## Validação manual obrigatória antes de considerar pronto para produção

Nada nas 9 tasks acima roda contra a SEFAZ real, nem contra Postgres. Antes de considerar a Etapa C2 pronta:

1. Rodar toda a suíte Unit localmente (`php artisan test --testsuite=Unit`) e confirmar 0 regressões nos testes de `FocusNfeProviderTest`/`SpedyProviderTest`/`MotorNfseTest`/etc. já existentes.
2. Rodar Feature tests num ambiente com Postgres (CI ou banco dedicado) — nunca contra produção.
3. **Emitir uma NF-e de teste em homologação contra a SEFAZ-MG real** — é aqui que toda incerteza marcada como "VERIFICAR CONTRA O VENDOR" nas Tasks 3/4/5/9 se resolve de verdade. Sem isso, o código pode compilar e os testes unitários passarem sem que uma única NF-e real jamais tenha sido autorizada.
4. Forçar EPEC apontando pra um endpoint inalcançável (ex.: derrubar a rede momentaneamente ou usar uma URL de homologação errada de propósito) e confirmar que a reconciliação agendada fecha o ciclo.
5. Comparar o DANFE gerado visualmente com um DANFE real (mesmo de outra emissão, pra conferir layout/legibilidade).
6. Confirmar que a retransmissão pós-EPEC mantém `tpEmis=4` (item "a confirmar" da spec, Seção C) — só o teste real revela isso.
