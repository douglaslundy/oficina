# Estender ConsultaNotaTerceiroProvider pro motor NFePHP — Design + Plano

> **For agentic workers:** TDD, RED→GREEN, commit por task. Rode
> `php vendor/bin/phpunit tests/Unit` depois de cada task. Feature tests
> não rodam nesta máquina (sem Postgres) — escreva-os certos, `php -l`,
> reporte claramente o que não pôde rodar.

**Goal:** hoje só `SpedyProvider`/`FocusNfeProvider` implementam
`ConsultaNotaTerceiroProvider` (consulta de nota fiscal de terceiro por
chave de acesso, usado pela leitura de QR/código de barras e pela
listagem "Notas Recebidas", Rodada 32). Estender `NfePhpProvider` pra
implementar a mesma interface, consultando a SEFAZ **diretamente** via
Distribuição DFe (`NFeDistribuicaoDFe`), usando o certificado A1 da
própria oficina — sem depender de nenhum provedor terceiro.

**Grounding técnico (confirmado nesta sessão lendo os arquivos REAIS do
pacote `nfephp-org/sped-nfe` já instalado em `backend/vendor/` — não é
suposição nem doc externa):**

- `NFePHP\NFe\Tools::sefazDistDFe(int $ultNSU = 0, int $numNSU = 0, ?string $chave = null, string $fonte = 'AN'): string`
  (`vendor/nfephp-org/sped-nfe/src/Tools.php:384`). Passando `$chave`,
  monta `<consChNFe><chNFe>$chave</chNFe></consChNFe>` — consulta por
  chave específica. Retorna o XML de resposta SOAP como string.
- Schema oficial da resposta, lido direto de
  `vendor/nfephp-org/sped-nfe/schemes/PL_010_V1.30/retDistDFeInt_v1.01.xsd`:
  raiz `retDistDFeInt` com `cStat`/`xMotivo` (código/motivo genéricos,
  **sem enumeração documentada no XSD** — não hardcodar um valor
  específico de cStat como sinal de "não encontrado"; usar a AUSÊNCIA de
  `loteDistDFeInt/docZip` como sinal, que é estruturalmente robusto
  independente do código exato) e, quando há resultado,
  `loteDistDFeInt > docZip[]` (até 50 por resposta) — cada `docZip` é
  **gzip + base64** (`xs:base64Binary`, comentário do XSD confirma "estará
  compactado no padrão gZip") com atributo `schema` dizendo o que tem
  dentro: `resNFe_v1.xx.xsd` (resumo, sem itens), `procNFe_v3.10.xsd`/
  `procNFe_v4.00.xsd` (XML completo da NF-e autorizada, COM itens — mesmo
  formato `nfeProc` que `NotaEntradaXmlParser` já sabe parsear),
  `resEvento_1.00.xsd`/`procEventoNFe_v1.00.xsd` (eventos, ignorar pra
  este caso de uso).
- `NFePHP\NFe\Tools::sefazManifesta(string $chave, int $tpEvento, ...)`
  (`Tools.php:677`) com `Tools::EVT_CIENCIA = 210210` (`Tools.php:36`) —
  mesmo padrão "ciência da operação" já usado pra Spedy/Focus.
- `MotorNfe` já sabe carregar o certificado e montar um `Tools`:
  `$dados = $this->certificados->obter($cfg); $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']); $tools = new Tools($this->configJson($cfg, $ambiente), $certificate);`
  (padrão repetido em `emitir()`/`consultar()`/`cancelar()` — reaproveitar
  tal como está).
- **Não confirmado nesta sessão** (exigiria certificado + rede reais): se
  uma consulta `consChNFe` feita pelo próprio CNPJ destinatário devolve o
  `procNFe` completo direto, ou se depende de manifestação prévia (como
  Spedy/Focus). Domínio conhecido: a Manifestação do Destinatário
  historicamente libera acesso completo pra **outros interessados**
  (contador, distribuidor), não necessariamente pro próprio destinatário
  usando o próprio certificado — mas isso **não foi verificado contra a
  SEFAZ real**. O código trata os dois cenários com segurança: se só vier
  `resNFe` (sem `procNFe`), manifesta "ciência" e devolve
  `AGUARDANDO_MANIFESTACAO`, exatamente como já acontece pra Spedy/Focus —
  nunca assume, sempre degrada pro caminho seguro.

## Global Constraints

- `declare(strict_types=1)`.
- Nunca hardcodar um valor específico de `cStat` como único sinal de
  sucesso/falha — usar presença/ausência de `docZip` como sinal primário
  (estruturalmente confirmado no XSD), `cStat`/`xMotivo` só pra log.
- Reaproveitar `NotaEntradaXmlParser::parse()` pra transformar o `procNFe`
  decodificado no mesmo array shape que `SpedyProvider`/`FocusNfeProvider`
  já produzem — nenhuma lógica de parse nova.
- `listarNotasRecebidas()` pro NFePHP é **best-effort nesta v1**: usa
  `sefazDistDFe(0, 0, null)` (varre a partir do NSU 0), sem paginação por
  `maxNSU`/checkpoint persistido — documentar isso explicitamente como
  limitação conhecida (pode não trazer tudo se houver mais de 50
  documentos, o limite por resposta do próprio schema). Não implementar
  paginação completa nesta rodada — YAGNI até alguém precisar de verdade
  (NFePHP não está registrado em nenhuma oficina em produção ainda).

---

### Task 1: `MotorNfe::consultarNotaRecebida()` — consulta por chave

**Files:**
- Modify: `backend/app/Services/Fiscal/NfePhp/MotorNfe.php`
- Test: `backend/tests/Unit/Fiscal/NfePhp/MotorNfeConsultarNotaRecebidaMappingTest.php`

Igual ao padrão já usado em `MotorNfse::mapearResultadoConsulta()`: extrair
a interpretação da resposta como método **puro** (recebe a string XML já
obtida, sem tocar rede/certificado), testável isoladamente.

```php
// No topo do arquivo, acrescentar:
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\NotaEntradaXmlParser;

// Novo método público:
public function consultarNotaRecebida(string $chaveAcesso, string $ambiente): ConsultaNotaTerceiroResultado
{
    $cfg = Configuracao::first();
    if (!$cfg) {
        return ConsultaNotaTerceiroResultado::erro('Configurações da empresa não encontradas.');
    }

    try {
        $dados       = $this->certificados->obter($cfg);
        $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
        $tools       = new Tools($this->configJson($cfg, $ambiente), $certificate);

        $respostaXml = $tools->sefazDistDFe(0, 0, $chaveAcesso);

        return $this->interpretarRespostaDistDFe($respostaXml, $chaveAcesso, $tools);
    } catch (\Throwable $e) {
        return ConsultaNotaTerceiroResultado::erro('Falha ao consultar NF-e de terceiro: ' . $e->getMessage());
    }
}

/**
 * Interpreta a resposta de sefazDistDFe() — schema oficial confirmado em
 * schemes/PL_010_V1.30/retDistDFeInt_v1.01.xsd. cStat/xMotivo não têm
 * enumeração documentada nesse XSD, então NUNCA usamos um valor numérico
 * específico como sinal — a ausência de docZip já basta pra "não
 * encontrado", estruturalmente. $tools é usado só se for preciso
 * manifestar (ciência) quando só vier resNFe (resumo, sem itens).
 */
private function interpretarRespostaDistDFe(string $xml, string $chaveAcesso, Tools $tools): ConsultaNotaTerceiroResultado
{
    libxml_use_internal_errors(true);
    $sxml = simplexml_load_string($xml);
    libxml_clear_errors();

    if ($sxml === false) {
        return ConsultaNotaTerceiroResultado::erro('Resposta inválida da SEFAZ ao consultar Distribuição DFe.');
    }

    $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
    $docZips = $sxml->xpath('//nfe:loteDistDFeInt/nfe:docZip');

    if (empty($docZips)) {
        $cStat   = $sxml->xpath('//nfe:cStat')[0] ?? null;
        $xMotivo = $sxml->xpath('//nfe:xMotivo')[0] ?? null;
        Log::info('NFePHP/DistDFe: nenhum docZip na resposta.', [
            'chave' => $chaveAcesso, 'cStat' => (string) $cStat, 'xMotivo' => (string) $xMotivo,
        ]);
        return ConsultaNotaTerceiroResultado::naoEncontrada();
    }

    foreach ($docZips as $docZip) {
        $schema = (string) $docZip['schema'];
        if (!str_starts_with($schema, 'procNFe')) {
            continue;
        }

        $conteudoGzip = base64_decode((string) $docZip, true);
        $xmlCompleto  = $conteudoGzip !== false ? @gzdecode($conteudoGzip) : false;

        if ($xmlCompleto === false) {
            Log::warning('NFePHP/DistDFe: falha ao decodificar docZip (base64/gzip).', ['chave' => $chaveAcesso]);
            continue;
        }

        return ConsultaNotaTerceiroResultado::completa((new NotaEntradaXmlParser())->parse($xmlCompleto));
    }

    // Só veio resNFe (resumo, sem itens) — manifesta "ciência da operação"
    // (mesmo padrão de Spedy/Focus) e devolve aguardando, nunca inventa
    // itens a partir do resumo.
    try {
        $tools->sefazManifesta($chaveAcesso, Tools::EVT_CIENCIA);
    } catch (\Throwable $e) {
        Log::warning('NFePHP/DistDFe: falha ao manifestar ciência.', ['chave' => $chaveAcesso, 'erro' => $e->getMessage()]);
    }

    return ConsultaNotaTerceiroResultado::aguardandoManifestacao();
}
```

- [ ] **Step 1: Write the failing tests** — teste só `interpretarRespostaDistDFe()`
  (pura o suficiente: só precisa de um `Tools` real pra manifestar, que também
  pode ser mockado já que só é chamado no branch resNFe-sem-procNFe). Monte
  strings XML de resposta reais seguindo o schema confirmado acima:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Fiscal\NfePhp;

use App\Services\Fiscal\NfePhp\MotorNfe;
use NFePHP\NFe\Tools;
use Tests\TestCase;

class MotorNfeConsultarNotaRecebidaMappingTest extends TestCase
{
    private function invocar(string $xml, string $chave, Tools $tools)
    {
        $motor = new MotorNfe();
        $m = new \ReflectionMethod(MotorNfe::class, 'interpretarRespostaDistDFe');
        $m->setAccessible(true);
        return $m->invoke($motor, $xml, $chave, $tools);
    }

    private function docZipComProcNfe(): string
    {
        $procNfeXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe><infNFe Id="NFe35260712345678000199550010000012340000000001" versao="4.00">
    <ide><nNF>1234</nNF><serie>1</serie><dhEmi>2026-07-01T09:15:32-03:00</dhEmi></ide>
    <emit><CNPJ>12345678000199</CNPJ><xNome>Fornecedor Terceiro</xNome></emit>
    <det nItem="1"><prod><cEAN>7891234567890</cEAN><xProd>PECA X</xProd><qCom>2.0000</qCom><vUnCom>50.0000</vUnCom><NCM>84212300</NCM></prod></det>
  </infNFe></NFe>
</nfeProc>
XML;
        return base64_encode(gzencode($procNfeXml));
    }

    public function test_resposta_com_procnfe_retorna_completa_com_itens(): void
    {
        $docZip = $this->docZipComProcNfe();
        $xml = <<<XML
<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
  <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>138</cStat><xMotivo>Documento localizado</xMotivo>
  <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>1</ultNSU><maxNSU>1</maxNSU>
  <loteDistDFeInt>
    <docZip NSU="1" schema="procNFe_v4.00.xsd">{$docZip}</docZip>
  </loteDistDFeInt>
</retDistDFeInt>
XML;
        $tools = \Mockery::mock(Tools::class)->shouldIgnoreMissing();

        $resultado = $this->invocar($xml, '35260712345678000199550010000012340000000001', $tools);

        $this->assertSame('COMPLETA', $resultado->status);
        $this->assertSame('Fornecedor Terceiro', $resultado->dados['fornecedor_nome']);
        $this->assertCount(1, $resultado->dados['itens']);
        $this->assertSame('84212300', $resultado->dados['itens'][0]['ncm']);
    }

    public function test_resposta_sem_lote_retorna_nao_encontrada(): void
    {
        $xml = <<<XML
<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
  <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>137</cStat><xMotivo>Nenhum documento localizado</xMotivo>
  <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>0</ultNSU><maxNSU>0</maxNSU>
</retDistDFeInt>
XML;
        $tools = \Mockery::mock(Tools::class)->shouldIgnoreMissing();

        $resultado = $this->invocar($xml, 'CHAVE-INEXISTENTE', $tools);

        $this->assertSame('NAO_ENCONTRADA', $resultado->status);
    }

    public function test_resposta_so_com_resnfe_manifesta_ciencia_e_retorna_aguardando(): void
    {
        $resNfeFake = base64_encode(gzencode('<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"></resNFe>'));
        $xml = <<<XML
<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
  <tpAmb>2</tpAmb><verAplic>1.0</verAplic><cStat>138</cStat><xMotivo>Documento localizado</xMotivo>
  <dhResp>2026-09-05T10:00:00-03:00</dhResp><ultNSU>1</ultNSU><maxNSU>1</maxNSU>
  <loteDistDFeInt>
    <docZip NSU="1" schema="resNFe_v1.01.xsd">{$resNfeFake}</docZip>
  </loteDistDFeInt>
</retDistDFeInt>
XML;
        $tools = \Mockery::mock(Tools::class);
        $tools->shouldReceive('sefazManifesta')->once()->with('CHAVE-X', Tools::EVT_CIENCIA);

        $resultado = $this->invocar($xml, 'CHAVE-X', $tools);

        $this->assertSame('AGUARDANDO_MANIFESTACAO', $resultado->status);
    }
}
```

- [ ] **Step 2: Run to verify RED** — `php vendor/bin/phpunit tests/Unit/Fiscal/NfePhp/MotorNfeConsultarNotaRecebidaMappingTest.php`
- [ ] **Step 3: Implementar** (código já dado acima — os dois métodos em `MotorNfe.php`).
- [ ] **Step 4: Run GREEN.**
- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/NfePhp/MotorNfe.php backend/tests/Unit/Fiscal/NfePhp/MotorNfeConsultarNotaRecebidaMappingTest.php
git commit -m "feat(fiscal): MotorNfe consulta NF-e de terceiro via Distribuicao DFe"
```

---

### Task 2: `MotorNfe::listarNotasRecebidas()` — best-effort, sem paginação

**Files:**
- Modify: `backend/app/Services/Fiscal/NfePhp/MotorNfe.php`
- Test: `backend/tests/Unit/Fiscal/NfePhp/MotorNfeListarNotasRecebidasMappingTest.php`

```php
public function listarNotasRecebidas(string $cnpjOficina, string $ambiente): array
{
    $cfg = Configuracao::first();
    if (!$cfg) {
        return [];
    }

    try {
        $dados       = $this->certificados->obter($cfg);
        $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
        $tools       = new Tools($this->configJson($cfg, $ambiente), $certificate);

        $respostaXml = $tools->sefazDistDFe(0, 0, null);

        return $this->mapearListaDistDFe($respostaXml);
    } catch (\Throwable $e) {
        Log::warning('NFePHP/DistDFe: falha ao listar notas recebidas.', ['erro' => $e->getMessage()]);
        return [];
    }
}

/**
 * @return list<\App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo>
 */
private function mapearListaDistDFe(string $xml): array
{
    libxml_use_internal_errors(true);
    $sxml = simplexml_load_string($xml);
    libxml_clear_errors();
    if ($sxml === false) {
        return [];
    }

    $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
    $docZips = $sxml->xpath('//nfe:loteDistDFeInt/nfe:docZip');

    $resumos = [];
    foreach ($docZips ?: [] as $docZip) {
        $schema = (string) $docZip['schema'];
        if (!str_starts_with($schema, 'procNFe') && !str_starts_with($schema, 'resNFe')) {
            continue;
        }

        $conteudoGzip = base64_decode((string) $docZip, true);
        $xmlDoc       = $conteudoGzip !== false ? @gzdecode($conteudoGzip) : false;
        if ($xmlDoc === false) {
            continue;
        }

        $resumo = $this->resumoDeDocumento($xmlDoc, str_starts_with($schema, 'procNFe'));
        if ($resumo !== null) {
            $resumos[] = $resumo;
        }
    }

    return $resumos;
}

private function resumoDeDocumento(string $xmlDoc, bool $completo): ?\App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo
{
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string(preg_replace('/xmlns="[^"]*"/', '', $xmlDoc) ?? '');
    libxml_clear_errors();
    if ($doc === false) {
        return null;
    }

    // procNFe tem NFe>infNFe; resNFe tem os campos direto na raiz (chNFe,
    // xNome, CNPJ, vNF, dhEmi) — formatos diferentes, tratados separado.
    if ($completo) {
        $inf = $doc->NFe->infNFe ?? $doc->infNFe ?? null;
        if ($inf === null) return null;
        $chaveBruta = (string) ($inf['Id'] ?? '');
        return new \App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo(
            chaveAcesso: str_starts_with($chaveBruta, 'NFe') ? substr($chaveBruta, 3) : $chaveBruta,
            fornecedorNome: ((string) ($inf->emit->xNome ?? '')) ?: null,
            fornecedorCnpj: ((string) ($inf->emit->CNPJ ?? '')) ?: null,
            dataEmissao: substr((string) ($inf->ide->dhEmi ?? ''), 0, 10) ?: null,
            valorTotal: (float) ($inf->total->ICMSTot->vNF ?? 0),
            completa: true,
        );
    }

    return new \App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo(
        chaveAcesso: (string) ($doc->chNFe ?? ''),
        fornecedorNome: ((string) ($doc->xNome ?? '')) ?: null,
        fornecedorCnpj: ((string) ($doc->CNPJ ?? '')) ?: null,
        dataEmissao: substr((string) ($doc->dhEmi ?? ''), 0, 10) ?: null,
        valorTotal: (float) ($doc->vNF ?? 0),
        completa: false,
    );
}
```

- [ ] **Step 1: Write the failing test** (2 casos: resposta com um `procNFe` completo →
  1 resumo `completa=true`; resposta com um `resNFe` → 1 resumo `completa=false`, campos
  lidos direto da raiz do `resNFe` fake).
- [ ] **Step 2: RED.**
- [ ] **Step 3: Implementar** (código acima).
- [ ] **Step 4: GREEN.**
- [ ] **Step 5: Commit**

---

### Task 3: `NfePhpProvider implements ConsultaNotaTerceiroProvider`

**Files:**
- Modify: `backend/app/Services/Fiscal/Providers/NfePhpProvider.php`
- Test: `backend/tests/Unit/Fiscal/NfePhpProviderTest.php` (arquivo já existe — acrescentar
  ao final da classe de teste já existente, não criar um novo arquivo).

```php
// no topo:
use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;

// declaração da classe:
class NfePhpProvider implements FiscalProvider, ConsultaNotaTerceiroProvider

// novos métodos:
public function consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado
{
    return app(MotorNfe::class)->consultarNotaRecebida($chaveAcesso, $this->ambiente);
}

public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array
{
    // $desde sem uso — sefazDistDFe() só ordena por NSU incremental, não
    // por data de emissão; mesma limitação já documentada em
    // FocusNfeProvider::listarNotasRecebidas() pro parâmetro $desde.
    return app(MotorNfe::class)->listarNotasRecebidas($cnpjOficina, $this->ambiente);
}
```

- [ ] **Step 1: Write the failing tests** — 2 testes simples confirmando delegação
  (mock de `MotorNfe` via `app()->instance()`, confirma que `NfePhpProvider` repassa
  os argumentos certos e devolve o que `MotorNfe` devolveu).
- [ ] **Step 2: RED.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: GREEN.** Rodar a suíte Unit inteira pra confirmar zero regressão
  (`php vendor/bin/phpunit tests/Unit`).
- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Fiscal/Providers/NfePhpProvider.php backend/tests/Unit/Fiscal/NfePhpProviderTest.php
git commit -m "feat(fiscal): NfePhpProvider implementa ConsultaNotaTerceiroProvider via Distribuicao DFe"
```

---

### Task 4: Atualizar `PROGRESSO.md` e `TAREFAS.md`

Registrar a rodada (mencionar explicitamente o que ficou confirmado via
XSD/vendor vs. o que segue não confirmado contra a SEFAZ real), marcar
item concluído em `TAREFAS.md`, commit.
