<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\CrtResolver;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\NfeService;
use Illuminate\Support\Facades\Log;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Factories\Contingency;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;

/**
 * Monta (mas não assina nem transmite) o XML de uma NF-e via a classe Make
 * do sped-nfe. Extraído como método isolado, sem I/O, pra ser testável sem
 * rede nem certificado — mesmo padrão de MotorNfse::montarDps().
 *
 * IBS/CBS só entra no XML quando CRT === 3 (Regime Normal) — Simples
 * Nacional (CRT=1) fica sem o bloco, dispensado até 04/01/2027 (NT
 * 2025.002-RTC). Ver spec 2026-08-10-etapa-c2-nfe-epec-design.md.
 *
 * Todas as afirmações abaixo sobre o comportamento da Make foram confirmadas
 * lendo vendor/nfephp-org/sped-nfe/src/Make.php e as traits em
 * vendor/nfephp-org/sped-nfe/src/Traits/*.php nesta sessão (o brief original
 * era uma hipótese de trabalho, não uma transcrição verificada — ver
 * task-3-report.md para o detalhamento completo das divergências).
 *
 * Task 4 (emitir()/configJson()/processarRespostaAutorizacao()/tentarEpec()):
 * todas as afirmações sobre Tools::sefazEnviaLote()/sefazEPEC()/signNFe()/
 * sefazEvento() abaixo foram confirmadas lendo vendor/nfephp-org/sped-nfe/
 * src/Tools.php, src/Common/Tools.php, src/Factories/Contingency.php e
 * src/Factories/ContingencyNFe.php nesta sessão, e a divergência mais grave
 * (Tools::sefazEPEC() inalcançável — ver comentário em tentarEpec()) foi
 * REPRODUZIDA empiricamente com um certificado de teste antes de decidir a
 * correção — não é só leitura de código. Ver task-4-report.md.
 */
class MotorNfe
{
    public function __construct(
        private readonly CertificadoStore $certificados = new CertificadoStore(),
        private readonly NfeService $numeracao = new NfeService(),
    ) {}

    public function montarNfe(
        NotaFiscalData $nota,
        Configuracao $cfg,
        string $ambiente,
        int $numeroNfe,
        int $serieNfe,
    ): string {
        $crt = CrtResolver::resolver($cfg->regime_tributario ?? '');

        // Guarda explícita — não confiar na Make/sped-nfe pra falhar sozinha
        // aqui. Fora do PHPUnit (que converte E_WARNING em exceção
        // catchável via seu error handler), acessar ->nodeValue num node
        // CNPJ/CPF ausente NÃO lança nada em PHP puro: getXML() retorna uma
        // string "bem-sucedida", getErrors() fica vazio, e
        // sped-common\Keys::build() monta a chave de acesso a partir de um
        // CNPJ zero-padded (vazio -> "00000000000000") sem avisar ninguém.
        // Isso corromperia silenciosamente a chave de acesso — um valor
        // fiscal legalmente significativo — exatamente o tipo de "chutar"
        // que este projeto proíbe (mesma disciplina já aplicada a
        // origem/tributacao_icms noutros pontos do código fiscal). Por isso
        // falhamos alto e explicitamente aqui, antes de chamar tagemit()/
        // getXML(), em vez de depender do comportamento inconsistente da
        // biblioteca entre ambiente de teste e produção.
        $cnpjLimpo = preg_replace('/\D/', '', $cfg->cnpj ?? '') ?? '';
        if ($cnpjLimpo === '') {
            throw new \InvalidArgumentException('CNPJ da empresa não configurado — não é possível montar a chave de acesso da NF-e sem ele.');
        }

        $make = new Make();

        $make->taginfNFe((object) [
            'versao' => '4.00',
            'Id'     => null, // calculado pela própria Make ao montar a chave de acesso (confirmado em checkNFeKey(), chamado por render()/getXML())
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

        // Corrigido: o brief não passava CNPJ/CPF do emitente. Sem isso,
        // Traits/TraitCalculations::checkNFeKey() (chamado por render(), que
        // getXML() chama implicitamente) faz
        // $emit->getElementsByTagName('CPF')->item(0)->nodeValue — item(0)
        // retorna null quando nenhum CNPJ/CPF foi adicionado. Em PHP puro
        // (produção), acessar ->nodeValue em null é só um E_WARNING, não uma
        // \Exception — render() segue adiante, getXML() retorna uma string
        // "bem-sucedida" e getErrors() fica vazio, com a chave de acesso
        // montada a partir de um CNPJ vazio/zero-padded (corrompida em
        // silêncio). Só sob PHPUnit esse E_WARNING vira exceção catchável
        // (conversor de erros do PHPUnit) e aparece em getErrors() — o que
        // mascarava o problema real ao rodar só os testes. A guarda contra
        // CNPJ vazio no início de montarNfe() é o que garante a falha alta
        // de verdade; isto aqui só usa o valor já validado.
        $make->tagemit((object) [
            'CNPJ'  => $cnpjLimpo,
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

            // Corrigido: o brief tentava enviar CSOSN através de tagICMS()
            // (passando 'CSOSN' no array e forçando 'CST' => null pro CRT=1).
            // O vendor real NÃO aceita CSOSN em tagICMS() — o $possible de
            // TraitTagDetICMS::tagICMS() não lista 'CSOSN' (equilizeParameters
            // descartaria silenciosamente a chave), e o switch($std->CST) não
            // teria nenhum case pra CST null. O grupo ICMSSN (Simples
            // Nacional) é um MÉTODO SEPARADO, tagICMSSN(), que monta as tags
            // <ICMSSN101>/<ICMSSN102>/etc a partir de $std->CSOSN — confirmado
            // em Traits/TraitTagDetICMS.php. addTagDet() já sabe escolher
            // entre aICMS[] e aICMSSN[] ao montar o grupo <ICMS> de cada item
            // (nunca os dois juntos), então não precisamos escolher a tag pai
            // manualmente — só qual dos dois métodos chamar.
            if ($crt === 1) {
                $make->tagICMSSN((object) [
                    'item'  => $nItem,
                    'orig'  => $item['origem'],
                    'CSOSN' => $item['cst_csosn'],
                ]);
            } else {
                $make->tagICMS((object) array_filter([
                    'item'   => $nItem,
                    'orig'   => $item['origem'],
                    'CST'    => $item['cst_csosn'],
                    'modBC'  => $tributacaoSt ? null : 3,
                    'vBC'    => $tributacaoSt ? null : round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                    'pICMS'  => $tributacaoSt ? null : 0,
                    'vICMS'  => $tributacaoSt ? null : 0,
                ], static fn ($v) => $v !== null));
            }

            // IBS/CBS: só emitido quando CRT === 3 (Regime Normal). Simples
            // Nacional (CRT=1, regime real da oficina) fica sem o bloco —
            // dispensado até 04/01/2027 (NT 2025.002-RTC v1.40). Preencher
            // hoje para CRT=1 seria adivinhar regras que a NT explicitamente
            // diz que ainda não foram publicadas.
            if ($crt === 3) {
                // [decisão] Bloco IBS/CBS deliberadamente NÃO implementado
                // nesta v1, mesmo para CRT=3 — TraitTagDetIBSCBS existe no
                // vendor instalado (confirmado: a trait está listada em
                // Make.php e há um TraitTagGALCZFMCBS relacionado), mas seus
                // métodos só entram em uso quando $this->flagIBSCBS é true E
                // $this->schema > 9 (addTagTotal() em Make.php) — a Make é
                // instanciada aqui sem argumento de schema (schema=9,
                // PL_009_V4), então o bloco IBS/CBS nunca é montado nesta
                // versão, para CRT algum. Implementar isso de verdade
                // exigiria decidir a versão de schema (PL_010+) e mapear os
                // muitos campos de TraitTagDetIBSCBS/TraitTagGALCZFMCBS às
                // cegas — arriscaria montar um XML que a Make aceita mas a
                // SEFAZ rejeita por schema incorreto — pior que não montar
                // nada. Como o regime real da oficina é Simples Nacional
                // (CRT=1, dispensado do bloco até 04/01/2027), este branch
                // nunca é alcançado na prática hoje; documentado como
                // limitação conhecida da v1 (ver spec, "Riscos conhecidos"),
                // não implementado — item pra revisitar antes de atender
                // qualquer oficina em Regime Normal.
            }

            // PIS/COFINS: grupo obrigatório por item no XSD da NF-e v4.00,
            // independente do CRT (achado da revisão da Task 3 — Make::
            // addTagDet() só inclui <PIS>/<COFINS> quando aPIS[item]/
            // aCOFINS[item] são populados por tagPIS()/tagCOFINS()
            // explícitos, confirmado em Make.php ~743-755; sem isso a SEFAZ
            // rejeitaria por schema). Métodos confirmados em
            // Traits/TraitTagDetPIS.php e Traits/TraitTagDetCOFINS.php.
            //
            // CST 49 ("Outras Operações") com base/valor zerados é o padrão
            // universal e não controverso pra Simples Nacional (CRT=1): o
            // PIS/COFINS de empresas do Simples é pago via DAS unificado,
            // não calculado por operação na NF-e. CST 49 cai no branch
            // PISOutr/COFINSOutr de tagPIS()/tagCOFINS() — vBC/pPIS ficam
            // null (omitidos, não obrigatórios nesse branch) e vPIS/vCOFINS
            // vão como "0.00" (conditionalNumberFormatting(0) formata pra
            // string, não é tratado como ausente).
            //
            // CRT=3 (Regime Normal) usa o mesmo CST 49 / valor zero por
            // enquanto — a oficina real deste projeto nunca é CRT=3 (mesma
            // ressalva já aplicada ao bloco IBS/CBS acima), então calcular a
            // alíquota real de PIS/COFINS do Regime Normal é escopo fora
            // desta v1; não "chutamos" uma alíquota que não foi confirmada.
            $make->tagPIS((object) [
                'item' => $nItem,
                'CST'  => '49',
                'vPIS' => 0,
            ]);
            $make->tagCOFINS((object) [
                'item'    => $nItem,
                'CST'     => '49',
                'vCOFINS' => 0,
            ]);
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

        // Confirmado: getXML() chama render() internamente quando $this->xml
        // ainda está vazio (Make.php linha ~350) — não precisamos chamar
        // montaNFe()/render() explicitamente antes, como o brief já supunha.
        // render() nunca lança exceção pra fora: qualquer \Exception interna
        // é capturada e vira uma entrada em $this->errors (Make.php ~502),
        // então getXML() sempre retorna string (nunca `false`) — o brief
        // cogitava getXML() === false como possibilidade, mas a assinatura
        // real já é `: string` e o branch de erro é só getErrors() não-vazio.
        $xml = $make->getXML();
        if ($make->getErrors() !== []) {
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

    /**
     * Monta o XML, assina e transmite pra SEFAZ-MG via Tools::sefazEnviaLote()
     * (síncrono, indSinc=1). Em falha de COMUNICAÇÃO (nunca decisão
     * antecipada — não há consulta prévia de sefazStatus()), cai pra
     * contingência EPEC via tentarEpec().
     */
    public function emitir(NotaFiscalData $nota, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $nota->referenciaExterna);
        }

        try {
            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);

            $numeroNfe = $this->numeracao->proximoNumeroNfe();
            $serieNfe  = (int) ($cfg->serie_nfe ?: 1);

            $tools = new Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            $xml = $this->montarNfe($nota, $cfg, $ambiente, $numeroNfe, $serieNfe);

            try {
                // Corrigido: o brief passava $xml (NÃO assinado) direto pra
                // sefazEnviaLote(). Confirmado em Tools::sefazEnviaLote()
                // (Tools.php ~65) que ele NÃO assina internamente — só monta
                // o envelope e chama Common/Tools::isValid(), que por sua
                // vez chama Validator::isValid() (sped-common), que LANÇA
                // ValidatorException se o XML não bater com o XSD (o retorno
                // bool de Tools::isValid() é descartado pelo chamador, mas a
                // exception já escapou antes disso — confirmado lendo
                // sped-common/src/Validator.php). O schema da NF-e exige
                // <Signature>; sem assinar, TODA tentativa de emissão
                // falharia localmente (nunca chegaria a rede) e seria
                // classificada como "falha de comunicação" por este mesmo
                // catch, caindo sempre pra EPEC — o oposto do que EPEC
                // deveria ser (contingência excepcional, não o caminho
                // padrão). Assinamos aqui via Tools::signNFe(), confirmado
                // em Common/Tools.php ~367.
                $xmlAssinado = $tools->signNFe($xml);

                // indSinc=1: processamento síncrono — resposta já vem com o
                // resultado da autorização, sem precisar de sefazConsultaRecibo()
                // separado. Mesmo nível de "síncrono dentro da requisição" que
                // Spedy/Focus/NFS-e já usam (ver Global Constraints do spec).
                $resp = $tools->sefazEnviaLote([$xmlAssinado], (string) $numeroNfe, 1);

                return $this->processarRespostaAutorizacao($resp, $nota->referenciaExterna, $xmlAssinado);
            } catch (\Throwable $eTransmissao) {
                // Falha de COMUNICAÇÃO (timeout/conexão) — nunca decisão
                // antecipada (não consultamos sefazStatus() antes, ver spec).
                // Cai pra EPEC. Passamos o XML NÃO assinado — tentarEpec()
                // precisa reassiná-lo com os ajustes de contingência
                // (tpEmis=4 etc), então reaproveitar o já assinado pra modo
                // normal (tpEmis=1) seria descartado de qualquer forma.
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
     * JSON de configuração exigido pelo construtor de Tools — confirmado
     * contra vendor/nfephp-org/sped-nfe/src/Common/Tools.php (construtor
     * espera `string $configJson`, decodifica com json_decode() dentro de
     * Config::validate()) e contra o schema oficial em
     * vendor/nfephp-org/sped-nfe/storage/config.schema: os campos abaixo
     * (tpAmb, razaosocial, cnpj, siglaUF, schemes, versao) são exatamente os
     * marcados "required": true nesse schema; "atualizacao" é opcional mas
     * inofensivo de incluir. A hipótese do brief pra este método já batia
     * com o vendor real — só corrigido o docblock de retorno (é `string`
     * JSON, não um array).
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
     * Parsing da resposta de sefazEnviaLote() (indSinc=1) — string XML
     * (confirmado: Tools::sefazEnviaLote() retorna string, o corpo cru da
     * resposta HTTP/SOAP, sem descartar o envelope SOAP externo).
     *
     * Estrutura confirmada contra o XSD oficial do leiaute
     * (schemes/PL_009_V4/leiauteNFe_v4.00.xsd, complexType TRetEnviNFe /
     * TProtNFe): retEnviNFe tem um cStat de LOTE direto, e um `protNFe`
     * opcional cujo `infProt` tem o cStat/chNFe/nProt/xMotivo do
     * PROCESSAMENTO SÍNCRONO da nota em si — que é o que importa aqui. A
     * hipótese original do brief pra esse xpath já batia com o XSD
     * verificado; mantido como estava. `//nfe:...` (busca em qualquer
     * profundidade) funciona aqui mesmo com o envelope SOAP em volta, porque
     * XPath com `//` não se importa com a árvore de ancestrais fora do
     * namespace pesquisado.
     *
     * Corrigido — bug real encontrado rodando os testes (não só leitura de
     * código, igual ao espírito da Task 3): `SimpleXMLElement::
     * registerXPathNamespace()` registra o prefixo só NO OBJETO em que foi
     * chamado, não é herdado por sub-elementos retornados por `->xpath()`.
     * O brief original chamava `registerXPathNamespace()` uma vez em $sxml
     * e depois `$protNFe->xpath('.//nfe:cStat')` num objeto FILHO diferente
     * (`$protNFe`, retornado pelo primeiro xpath) — sem o prefixo `nfe`
     * registrado nele, a busca aninhada sempre voltava vazia, e todo
     * cStat/chNFe/nProt do protNFe virava string vazia (nunca "100", então
     * a nota sempre caía no branch de REJEITADA em vez de reconhecer
     * autorização real). Corrigido registrando o namespace de novo em
     * `$protNFe` antes das buscas aninhadas.
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
            $protNFe->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
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

    /**
     * Contingência EPEC — tenta registrar o evento de emissão em
     * contingência quando a transmissão normal falha por comunicação.
     *
     * CORRIGIDO — divergência grave confirmada contra o vendor instalado
     * (nfephp-org/sped-nfe v5.2.8), e REPRODUZIDA EMPIRICAMENTE (não só por
     * leitura de código, com um certificado de teste real) antes desta
     * implementação: `Tools::sefazEPEC()` exige, na primeira linha, que
     * `$this->contingency->type === 'EPEC'` (Tools.php ~1008), mas
     * internamente termina chamando `sefazEvento()` (Tools.php ~1082), que
     * por sua vez chama `checkContingencyForWebServices()` (Common/Tools.php
     * ~433) — esse método só aceita `type` vazio, 'SVCRS' ou 'SVCAN';
     * QUALQUER outro valor não-vazio, incluindo 'EPEC' (o único valor que a
     * própria guarda de `sefazEPEC()` aceita), lança RuntimeException. Ao
     * reproduzir isso neste ambiente com um certificado de teste, chamar
     * `Tools::sefazEPEC()` com `contingency->type='EPEC'` lançou
     * "Esse modo de contingência [EPEC] não possue webservice próprio,
     * portanto não haverão envios." — SEMPRE, sem nenhuma tentativa de rede
     * (é 100% local/determinística, não depende da SEFAZ estar de pé). Ou
     * seja: como instalado, `Tools::sefazEPEC()` é inalcançável através do
     * fluxo público documentado pelo próprio pacote — inclusive o exemplo
     * oficial `fake/fakeSefazEPEC.php` do pacote está quebrado no mesmo
     * ponto (chama `Contingency::activate(..., 'EPEC')`, que TAMBÉM lança,
     * já que `activate()` só aceita 'SVCAN'/'SVCRS' como tipo — o exemplo só
     * "funciona" porque engole qualquer exceção num try/catch mudo).
     *
     * Em vez de deixar a contingência EPEC permanentemente inoperante,
     * reproduzimos aqui o corpo de `Tools::sefazEPEC()` (Tools.php
     * 1002-1091) usando só métodos PÚBLICOS e já testados do próprio pacote
     * (`signNFe()`, `sefazEvento()`), pulando só a chamada interna quebrada.
     * Nenhuma lógica de SOAP/layout foi inventada — os campos do `$tagAdic`
     * abaixo são cópia fiel da implementação original do pacote, só
     * realocados pra este método. Se uma atualização futura do
     * nfephp-org/sped-nfe corrigir `checkContingencyForWebServices()` pra
     * aceitar 'EPEC', este bloco pode voltar a ser uma chamada direta a
     * `$tools->sefazEPEC($xml, $verAplic)`.
     */
    private function tentarEpec(Tools $tools, string $xml, ?string $ref): EmissaoResultado
    {
        try {
            $contingency = new Contingency();
            $contingency->type = 'EPEC';
            // Contingency::activate()/configBuild() só sabem mapear tpEmis
            // pra SVCAN(6)/SVCRS(7) — como activate() nunca produz o tipo
            // 'EPEC' (ver comentário acima), setamos tpEmis=4 (EPEC, tabela
            // oficial de formas de emissão) manualmente.
            $contingency->tpEmis = 4;
            $contingency->timestamp = time();
            $contingency->motive = 'SEFAZ-MG indisponivel no momento da emissao - contingencia EPEC ativada automaticamente pelo sistema';
            $tools->contingency = $contingency;

            // signNFe() com contingency->type != '' chama
            // ContingencyNFe::adjust() ANTES de assinar — injeta
            // tpEmis/dhCont/xJust e recalcula a chave de acesso (Factories/
            // ContingencyNFe.php) — exatamente o que
            // Tools::sefazEPEC()->correctNFeForContingencyMode() faria na
            // primeira linha, se fosse alcançável.
            $xmlContingencia = $tools->signNFe($xml);

            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;
            $dom->loadXML($xmlContingencia);

            $infNFe = $dom->getElementsByTagName('infNFe')->item(0);
            $emit   = $dom->getElementsByTagName('emit')->item(0);
            $dest   = $dom->getElementsByTagName('dest')->item(0);
            $total  = $dom->getElementsByTagName('total')->item(0);
            if ($infNFe === null || $emit === null || $dest === null || $total === null) {
                throw new \RuntimeException('XML da NF-e em contingência incompleto — faltam nós obrigatórios (infNFe/emit/dest/total).');
            }

            // Cópia fiel de Tools::sefazEPEC() (Tools.php ~1022): remove o
            // prefixo "NFe" do atributo Id de infNFe pra extrair a chave de
            // acesso (já recalculada acima por ContingencyNFe::adjust() com
            // tpEmis=4).
            $chNFe = substr($infNFe->getAttribute('Id'), 3, 44);

            $verAplic = config('app.version', '1.0.0');
            $dhEmi    = $dom->getElementsByTagName('dhEmi')->item(0)->nodeValue ?? '';
            $tpNF     = $dom->getElementsByTagName('tpNF')->item(0)->nodeValue ?? '1';
            $emitIE   = $emit->getElementsByTagName('IE')->item(0)->nodeValue ?? '';
            $destUF   = $dest->getElementsByTagName('UF')->item(0)->nodeValue ?? '';
            $vNF      = $total->getElementsByTagName('vNF')->item(0)->nodeValue ?? '0.00';
            $vICMS    = $total->getElementsByTagName('vICMS')->item(0)->nodeValue ?? '0.00';
            $vST      = $total->getElementsByTagName('vST')->item(0)->nodeValue ?? '0.00';

            $cnpjDestNode = $dest->getElementsByTagName('CNPJ')->item(0);
            $cpfDestNode  = $dest->getElementsByTagName('CPF')->item(0);
            if ($cnpjDestNode !== null && $cnpjDestNode->nodeValue !== '') {
                $destId = '<CNPJ>' . $cnpjDestNode->nodeValue . '</CNPJ>';
            } elseif ($cpfDestNode !== null && $cpfDestNode->nodeValue !== '') {
                $destId = '<CPF>' . $cpfDestNode->nodeValue . '</CPF>';
            } else {
                $idEstrangeiroNode = $dest->getElementsByTagName('idEstrangeiro')->item(0);
                $destId = '<idEstrangeiro>' . ($idEstrangeiroNode->nodeValue ?? '') . '</idEstrangeiro>';
            }

            $ieDestNode = $dest->getElementsByTagName('IE')->item(0);
            $destIe = ($ieDestNode !== null && $ieDestNode->nodeValue !== '') ? '<IE>' . $ieDestNode->nodeValue . '</IE>' : '';

            // $tools->cUF já é UFList::getCodeByUF($tools->config->siglaUF),
            // calculado no construtor de Tools — mesmo valor que
            // Tools::sefazEPEC() recalcularia como cOrgaoAutor.
            $tagAdic = "<cOrgaoAutor>{$tools->cUF}</cOrgaoAutor>"
                . '<tpAutor>1</tpAutor>'
                . "<verAplic>{$verAplic}</verAplic>"
                . "<dhEmi>{$dhEmi}</dhEmi>"
                . "<tpNF>{$tpNF}</tpNF>"
                . "<IE>{$emitIE}</IE>"
                . '<dest>'
                . "<UF>{$destUF}</UF>"
                . $destId
                . $destIe
                . "<vNF>{$vNF}</vNF>"
                . "<vICMS>{$vICMS}</vICMS>"
                . "<vST>{$vST}</vST>"
                . '</dest>';

            // Zera o type ANTES de chamar sefazEvento() — signNFe() acima já
            // precisava de contingency->type='EPEC' pra acionar
            // ContingencyNFe::adjust(), mas sefazEvento() (chamado a seguir)
            // não usa mais contingency->type depois do guard quebrado (ver
            // docblock do método); zerar aqui evita cair na mesma exceção
            // interna de checkContingencyForWebServices().
            $tools->contingency->type = '';

            $respEpec = $tools->sefazEvento('AN', $chNFe, Tools::EVT_EPEC, 1, $tagAdic, null, null);

            $cStat = $this->extrairCStatEvento($respEpec);
            if ($cStat === null) {
                return EmissaoResultado::erro('Resposta do evento EPEC não pôde ser interpretada.', $ref);
            }

            // 135/136 = Evento registrado e vinculado (ou não) à NF-e —
            // códigos de sucesso confirmados contra o uso interno do próprio
            // pacote em Complements::addEnvEventoProtocol()
            // ($cStatValids = ['135','136'], lança exceção se o cStat não
            // estiver nessa lista). Tratamento conservador: só aceita como
            // CONTINGENCIA se reconhecer um desses cStat, senão ERRO (nunca
            // "chuta" sucesso).
            if (in_array($cStat, ['135', '136'], true)) {
                return EmissaoResultado::contingencia($xmlContingencia, $ref);
            }

            return EmissaoResultado::erro("EPEC não autorizado (cStat={$cStat}). SEFAZ e EPEC ambos indisponíveis ou rejeitaram.", $ref);
        } catch (\Throwable $e) {
            Log::warning('MotorNfe: falha também no EPEC.', ['erro' => $e->getMessage(), 'ref' => $ref]);
            return EmissaoResultado::erro('SEFAZ indisponível e contingência EPEC também falhou: ' . $e->getMessage(), $ref);
        }
    }

    /**
     * Extrai o cStat do REGISTRO DO EVENTO (retEvento/infEvento/cStat) da
     * resposta de sefazEvento(), isolado num método próprio pra ser
     * testável sem I/O (mesmo padrão de processarRespostaAutorizacao()).
     *
     * Corrigido: retEnvEvento tem um cStat de LOTE direto (status do
     * recebimento do lote de eventos) além do cStat aninhado em
     * retEvento/infEvento (status do REGISTRO DO EVENTO em si — o que
     * realmente importa aqui). Confirmado em
     * schemes/PL_009_V4/leiauteEvento_v1.00.xsd (TRetEnvEvento tem cStat
     * próprio + retEvento[]/TRetEvento/infEvento/cStat) e no próprio código
     * do pacote (Complements::addEnvEventoProtocol(), que extrai
     * especificamente retEvento->infEvento->cStat, não o cStat de nível de
     * lote). Um xpath genérico //nfe:cStat pegaria o cStat do LOTE primeiro
     * (ordem do documento), classificando errado — por isso a busca abaixo
     * é restrita a dentro de retEvento.
     *
     * @return string|null null quando a resposta não é XML válido; string
     *   vazia quando é XML válido mas não tem retEvento/cStat (tratado como
     *   "não reconhecido" pelo chamador, nunca como sucesso).
     *
     * Corrigido — mesmo bug real encontrado (e corrigido) em
     * processarRespostaAutorizacao(): registerXPathNamespace() não é
     * herdado por sub-elementos retornados por xpath(); precisa ser
     * chamado de novo em $retEvento antes da busca aninhada, senão
     * `.//nfe:cStat` sempre volta vazio.
     */
    private function extrairCStatEvento(string $respostaXml): ?string
    {
        $sxml = @simplexml_load_string($respostaXml);
        if ($sxml === false) {
            return null;
        }
        $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $retEvento = $sxml->xpath('//nfe:retEvento')[0] ?? null;
        if ($retEvento === null) {
            return '';
        }
        $retEvento->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
        return (string) ($retEvento->xpath('.//nfe:cStat')[0] ?? '');
    }
}
