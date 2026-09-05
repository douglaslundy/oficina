<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Services\Fiscal\CrtResolver;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\NfeService;
use App\Services\NotaEntradaXmlParser;
use Illuminate\Support\Facades\Log;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\SoapException;
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
 *
 * Task 5 (consultar()/cancelar()/retransmitir()): confirmado lendo
 * vendor/nfephp-org/sped-nfe/src/Tools.php (sefazConsultaChave(),
 * sefazCancela()) e os XSDs oficiais em schemes/PL_009_V4/
 * (leiauteConsSitNFe_v4.00.xsd, leiauteEvento_v1.00.xsd). Achado principal:
 * Tools::sefazCancela() é sefazEvento() por baixo (só monta o tagAdic de
 * nProt/xJust e delega) — a resposta tem exatamente o mesmo formato
 * retEnvEvento (cStat de LOTE antes de retEvento/infEvento/cStat, o cStat
 * real do registro do evento) já tratado por extrairCStatEvento() na Task 4.
 * O brief original de cancelar() fazia um xpath plano (`//nfe:cStat`) que
 * pegaria o cStat de LOTE em vez do cStat do evento — o mesmo bug já
 * corrigido (e testado) em extrairCStatEvento(); corrigido aqui reutilizando
 * esse método em vez de duplicar o parsing errado. Já consultar()
 * (retConsSitNFe) NÃO tem esse problema: confirmado no XSD
 * (leiauteConsSitNFe_v4.00.xsd, TRetConsSitNFe) que o cStat de topo é um
 * campo direto da resposta — representa a SITUAÇÃO ATUAL da NF-e
 * (100/101/151/205/217/218, Tabela de Status da Consulta Protocolo) e não um
 * "cStat de lote" concorrente com outro cStat de mesmo peso semântico; o
 * xpath plano do brief já estava correto aí, só documentado/confirmado.
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
                'cProd'   => $item['sku'] ?? $item['produto_id'],
                'xProd'   => $item['descricao'],
                'NCM'     => $item['ncm'],
                'CFOP'    => $item['cfop'],
                'uCom'    => $item['unidade'] ?? 'UN',
                'qCom'    => $item['quantidade'],
                'vUnCom'  => $item['valor_unitario'],
                'vProd'   => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                'uTrib'   => $item['unidade'] ?? 'UN',
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
            // PISOutr/COFINSOutr de tagPIS()/tagCOFINS() — vPIS/vCOFINS vão
            // como "0.00" (conditionalNumberFormatting(0) formata pra
            // string, não é tratado como ausente).
            //
            // CORRIGIDO — achado do review desta task, validado rodando
            // schemaValidate() contra o XSD real
            // (schemes/PL_009_V4/leiauteNFe_v4.00.xsd): o comentário
            // original dizia que vBC/pPIS (e vBC/pCOFINS) eram "não
            // obrigatórios nesse branch" — ERRADO. O XSD define PISOutr/
            // COFINSOutr com um <xs:choice> OBRIGATÓRIO logo depois do CST:
            // (vBC + pPIS) OU (qBCProd + vAliqProd), sem minOccurs="0" no
            // choice em si — omitir os dois pares gera erro de validação
            // real ("Element 'vPIS': This element is not expected. Expected
            // is one of ( vBC, qBCProd )"), rejeitado pela SEFAZ por schema
            // em TODA emissão. Corrigido incluindo vBC/pPIS e vBC/pCOFINS
            // como zero — mesma disciplina de "nunca chutar uma alíquota
            // real": são só os campos zero que o schema exige pra Simples
            // Nacional, não um valor fiscal inventado.
            //
            // CRT=3 (Regime Normal) usa o mesmo CST 49 / valores zero por
            // enquanto — a oficina real deste projeto nunca é CRT=3 (mesma
            // ressalva já aplicada ao bloco IBS/CBS acima), então calcular a
            // alíquota real de PIS/COFINS do Regime Normal é escopo fora
            // desta v1; não "chutamos" uma alíquota que não foi confirmada.
            $make->tagPIS((object) [
                'item' => $nItem,
                'CST'  => '49',
                'vBC'  => 0,
                'pPIS' => 0,
                'vPIS' => 0,
            ]);
            $make->tagCOFINS((object) [
                'item'    => $nItem,
                'CST'     => '49',
                'vBC'     => 0,
                'pCOFINS' => 0,
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

        // FINDING 5 do fix wave pós-revisão da Etapa C2 (2026-08-11),
        // parked desde a review de uma task anterior: cUfMg() (abaixo, em
        // montarNfe()) hardcoda MG (31) em <ide><cUF> incondicionalmente,
        // enquanto configJson() deriva siglaUF de $cfg->uf — uma oficina mal
        // configurada (uf != 'MG') assinaria e transmitiria (ou queimaria
        // um número real tentando) uma NF-e com UF internamente
        // contraditória, falhando só remotamente na SEFAZ em vez de local e
        // de graça. Guard colocado ANTES da alocação de número (early
        // return), mesmo espírito do guard de CNPJ vazio em montarNfe().
        if (strtoupper((string) ($cfg->uf ?? '')) !== 'MG') {
            return EmissaoResultado::erro(
                'Etapa C2 do NFePHP só emite NF-e para oficinas em MG — cUfMg() está fixo em 31/MG (ver spec, Seção A).',
                $nota->referenciaExterna,
            );
        }

        try {
            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);

            // FINDING 4 do fix wave: se $nota->numeroReservado veio populado
            // (NfeService::montarNotaData() só populada isso quando esta
            // NotaFiscal JÁ tem um numero persistido de uma tentativa
            // NFEPHP anterior — ver lá), esta é uma retentativa após
            // rejeição/erro, não uma primeira emissão — reusa o mesmo nNF em
            // vez de queimar um novo (spec Seção B, "nota rejeitada não
            // queima o número").
            $numeroNfe = $nota->numeroReservado !== null
                ? (int) $nota->numeroReservado
                : $this->numeracao->proximoNumeroNfe();
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

                return $this->processarRespostaAutorizacao($resp, $nota->referenciaExterna, $xmlAssinado, (string) $numeroNfe);
            } catch (SoapException $eTransmissao) {
                // CORRIGIDO — achado do review desta task: o brief original
                // capturava `\Throwable` aqui, o que faria QUALQUER erro
                // (schema inválido do signNFe(), URL de serviço mal
                // configurada, InvalidArgumentException de entrada ruim,
                // etc.) cair pra EPEC — registrando um evento de
                // contingência real, legalmente vinculante, na SEFAZ por um
                // motivo que nunca foi de comunicação. O spec é explícito:
                // EPEC só entra por falha de COMUNICAÇÃO (timeout/conexão),
                // nunca como reação a qualquer outro tipo de erro. Restrito
                // a SoapException — confirmado em
                // sped-common/src/Soap/SoapCurl.php que é essa a classe
                // lançada pela camada HTTP (SoapCurl::send()) pra timeout,
                // conexão recusada, resposta não-200, corpo vazio/não-XML —
                // ou seja, exatamente "falha de comunicação". Qualquer outro
                // \Throwable (ex.: ValidatorException de schema) propaga pro
                // catch externo e retorna ERRO direto, sem tentar EPEC.
                //
                // Passamos o XML NÃO assinado — tentarEpec() precisa
                // reassiná-lo com os ajustes de contingência (tpEmis=4
                // etc), então reaproveitar o já assinado pra modo normal
                // (tpEmis=1) seria descartado de qualquer forma.
                Log::warning(
                    'MotorNfe: falha na transmissão normal, tentando EPEC.',
                    ['erro' => $eTransmissao->getMessage(), 'ref' => $nota->referenciaExterna],
                );

                return $this->tentarEpec($tools, $xml, $nota->referenciaExterna, (string) $numeroNfe);
            }
        } catch (\Throwable $e) {
            Log::warning('MotorNfe: falha ao emitir.', ['erro' => $e->getMessage(), 'ref' => $nota->referenciaExterna]);
            // FINDING 4 do fix wave: se a exceção aconteceu DEPOIS de
            // $numeroNfe ser alocado (variável ainda em escopo — try/catch
            // no PHP compartilha o escopo da função), o número foi
            // realmente queimado e precisa ser reportado pro controller
            // persistir, senão a retentativa (via
            // NfeService::montarNotaData()) nunca o encontra e queima outro.
            // Se a exceção veio de ANTES da alocação (ex.: certificado
            // inválido), isset() é false e não inventamos um número que
            // nunca existiu.
            return EmissaoResultado::erro(
                'Falha técnica ao emitir NF-e via NFePHP: ' . $e->getMessage(),
                $nota->referenciaExterna,
                isset($numeroNfe) ? (string) $numeroNfe : null,
            );
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
     *
     * CORRIGIDO — Finding 3 do fix wave pós-revisão da Etapa C2
     * (2026-08-11): `numero` na chamada de `autorizada()` era hardcoded
     * `null` com um comentário dizendo "controller mantém o valor
     * existente" — mas o "valor existente" no controller vinha de
     * `NfeService::proximoNumeroNf()` (contador do Spedy/Focus!), chamado
     * ANTES mesmo de saber que o NFePHP ia processar esta nota. Resultado:
     * toda emissão de NF-e via NFePHP queimava DOIS números de DOIS
     * contadores diferentes e persistia o ERRADO. `$numeroReal` agora é
     * obrigatório (não opcional — método privado, único chamador é
     * `emitir()`, que sempre tem o valor de `$numeroNfe` alocado antes de
     * transmitir; um parâmetro sem default força esse dado nunca ser
     * esquecido silenciosamente numa chamada futura). Passado também pros
     * ramos de `rejeitada()` — o número já foi alocado e queimado mesmo
     * quando a SEFAZ rejeita (ver Finding 4, `EmissaoResultado::
     * rejeitada()`/`NotaFiscalData::numeroReservado`).
     */
    private function processarRespostaAutorizacao(string $respostaXml, ?string $ref, string $xmlEnviado, string $numeroReal): EmissaoResultado
    {
        $sxml = @simplexml_load_string($respostaXml);
        if ($sxml === false) {
            return EmissaoResultado::erro('Resposta da SEFAZ não pôde ser interpretada.', $ref, $numeroReal);
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
                    numero: $numeroReal,
                    xml: $xmlEnviado,
                    pdfUrl: null,
                    ref: $ref,
                );
            }

            $xMotivo = (string) ($protNFe->xpath('.//nfe:xMotivo')[0] ?? 'Rejeitada pela SEFAZ.');
            return EmissaoResultado::rejeitada("cStat={$cStat}: {$xMotivo}", $ref, $numeroReal);
        }

        // Sem protNFe — lote rejeitado antes mesmo de processar a nota
        // individual (erro de schema, duplicidade, etc.).
        return EmissaoResultado::rejeitada("Lote rejeitado (cStat={$cStatLote}).", $ref, $numeroReal);
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
     *
     * CORRIGIDO — Finding 2/3 do fix wave pós-revisão da Etapa C2
     * (2026-08-11): `$numeroNfe` agora chega como parâmetro (alocado por
     * `emitir()` antes de tentar a transmissão normal) pra ser repassado a
     * `EmissaoResultado::contingencia()` — sem isso o número real da nota
     * em contingência nunca era persistido (mesma classe de bug do
     * `numero: null` de `processarRespostaAutorizacao()`, ver lá).
     */
    private function tentarEpec(Tools $tools, string $xml, ?string $ref, string $numeroNfe): EmissaoResultado
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

            // Cópia fiel de Tools::sefazEPEC() (Tools.php ~1023-1026): recusa
            // enviar o evento EPEC se a UF do emitente (cOrgaoAutor, derivado
            // de configJson()->siglaUF = $cfg->uf) não bater com a UF
            // codificada na própria chave de acesso (os 2 primeiros dígitos,
            // recalculados acima a partir de <ide><cUF>, fixo em 31/MG via
            // cUfMg()). Achado do review desta task: esse guard existe no
            // vendor original e não tinha equivalente aqui — sem ele, uma
            // oficina mal configurada com $cfg->uf !== 'MG' produziria e
            // enviaria um evento EPEC com dados fiscais internamente
            // contraditórios (UF do autor != UF da chave) em vez de falhar
            // alto. cUF já vem em texto com 2 dígitos (ex. "31"); comparação
            // como string, igual ao vendor (`!=`, frouxa, mas ambos os lados
            // já são strings de 2 dígitos aqui).
            $ufChave = substr($chNFe, 0, 2);
            if ((string) $tools->cUF !== $ufChave) {
                throw new \RuntimeException("O autor [{$tools->cUF}] não é da mesma UF que a NF-e [{$ufChave}] — configuração de UF da oficina inconsistente com o cUF fixo desta etapa (MG/31).");
            }

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
                // Finding 4 do fix wave: $numeroNfe já foi alocado e queimado
                // por emitir() antes de chegar aqui — reportar pro controller
                // persistir, senão a retentativa queima outro (mesmo
                // raciocínio do catch externo de emitir()).
                return EmissaoResultado::erro('Resposta do evento EPEC não pôde ser interpretada.', $ref, $numeroNfe);
            }

            // 135/136 = Evento registrado e vinculado (ou não) à NF-e —
            // códigos de sucesso confirmados contra o uso interno do próprio
            // pacote em Complements::addEnvEventoProtocol()
            // ($cStatValids = ['135','136'], lança exceção se o cStat não
            // estiver nessa lista). Tratamento conservador: só aceita como
            // CONTINGENCIA se reconhecer um desses cStat, senão ERRO (nunca
            // "chuta" sucesso).
            if (in_array($cStat, ['135', '136'], true)) {
                // Finding 2 do fix wave: contingencia() agora exige a chave
                // de acesso e o número real como 1º/2º args — $chNFe já foi
                // calculada acima (recalculada por ContingencyNFe::adjust()
                // com tpEmis=4) e $numeroNfe veio como parâmetro do método.
                return EmissaoResultado::contingencia($chNFe, $numeroNfe, $xmlContingencia, $ref);
            }

            return EmissaoResultado::erro("EPEC não autorizado (cStat={$cStat}). SEFAZ e EPEC ambos indisponíveis ou rejeitaram.", $ref, $numeroNfe);
        } catch (\Throwable $e) {
            Log::warning('MotorNfe: falha também no EPEC.', ['erro' => $e->getMessage(), 'ref' => $ref]);
            return EmissaoResultado::erro('SEFAZ indisponível e contingência EPEC também falhou: ' . $e->getMessage(), $ref, $numeroNfe);
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

    /**
     * Consulta a situação atual de uma NF-e pela chave de acesso
     * (sefazConsultaChave() / NfeConsultaProtocolo) — nunca decide
     * autorizada/cancelada sem essa confirmação explícita da SEFAZ. Usado
     * por retransmitir() (abaixo) antes de qualquer reenvio, e pela Task 6
     * (NfePhpProvider) para reconciliação manual/consulta avulsa.
     */
    public function consultar(string $chave, string $ambiente): EmissaoResultado
    {
        // CORRIGIDO vs. o brief: `Configuracao::first()` ficava FORA do
        // try/catch (mesmo padrão que emitir() já tem — não mexido aqui,
        // fora do escopo desta task, mas achado documentado no report).
        // Sem conexão de banco disponível (ambiente de teste sem DB, ver
        // MotorNfeConsultarTest), `Model::resolveConnection()` lança um
        // \Error fatal ("Call to a member function connection() on null"),
        // não uma \Exception — escapa incapturado e quebra o teste do
        // próprio brief pra retransmitir(), que depende de consultar()
        // falhar graciosamente aqui. Todo o corpo (incluindo a busca da
        // Configuracao) agora está dentro do try/catch, igual ao resto
        // deste método já fazia pra falhas de certificado/rede.
        try {
            $cfg = Configuracao::first();
            if (! $cfg) {
                return EmissaoResultado::erro('Configurações da empresa não encontradas.', $chave);
            }

            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            $resp = $tools->sefazConsultaChave($chave);

            return $this->processarRespostaConsulta($resp, $chave);
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao consultar NF-e: ' . $e->getMessage(), $chave);
        }
    }

    /**
     * Parsing puro (sem I/O) da resposta de sefazConsultaChave() —
     * separado do método público pra ser testável via reflection, mesmo
     * padrão de processarRespostaAutorizacao().
     *
     * Confirmado contra schemes/PL_009_V4/leiauteConsSitNFe_v4.00.xsd
     * (TRetConsSitNFe): cStat é um campo DIRETO da resposta (aparece antes
     * de protNFe/retCancNFe/procEventoNFe na sequência do XSD) — representa
     * a SITUAÇÃO ATUAL da NF-e segundo a Tabela de Status da Consulta
     * Protocolo (100 = Autorizado; 101/151 = Cancelamento homologado
     * dentro/fora do prazo; 217/218 = NF-e não consta / já cancelada na
     * base da SEFAZ), e não um "cStat de lote" que precede um cStat de
     * evento com peso semântico diferente (como em retEnvEvento — ver
     * processarRespostaCancelamento() abaixo). `//nfe:cStat` já pega o
     * campo certo porque é o primeiro cStat em ordem de documento; nProt só
     * existe aninhado em protNFe/infProt (ou retCancNFe/infCanc), então
     * `//nfe:nProt` também resolve sem ambiguidade quando presente.
     */
    private function processarRespostaConsulta(string $respostaXml, string $chave): EmissaoResultado
    {
        $sxml = @simplexml_load_string($respostaXml);
        if ($sxml === false) {
            return EmissaoResultado::erro('Resposta da consulta não pôde ser interpretada.', $chave);
        }
        $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $cStat = (string) ($sxml->xpath('//nfe:cStat')[0] ?? '');

        return match (true) {
            $cStat === '100' => EmissaoResultado::autorizada(
                chave: $chave,
                protocolo: (string) ($sxml->xpath('//nfe:nProt')[0] ?? ''),
                numero: null,
                xml: null,
                pdfUrl: null,
                ref: $chave,
            ),
            in_array($cStat, ['101', '151'], true) => EmissaoResultado::cancelada($chave),
            default => EmissaoResultado::erro(
                "NF-e em status não reconhecido (cStat={$cStat}); não classificamos como autorizada sem confirmação.",
                $chave,
            ),
        };
    }

    /**
     * Cancela uma NF-e (evento de cancelamento) — exige o protocolo da
     * autorização original, não só a chave (assinatura de
     * Tools::sefazCancela(string $chave, string $xJust, string $nProt,
     * ...), confirmada em Tools.php). NfePhpProvider::cancelar() (Task 6)
     * precisa carregar a NotaFiscal e passar $nota->protocolo aqui.
     */
    public function cancelar(string $chave, string $motivo, string $protocolo, string $ambiente): EmissaoResultado
    {
        // CORRIGIDO vs. o brief — mesmo achado documentado em consultar():
        // Configuracao::first() precisa estar DENTRO do try/catch, senão um
        // \Error de conexão de banco ausente escapa incapturado.
        try {
            $cfg = Configuracao::first();
            if (! $cfg) {
                return EmissaoResultado::erro('Configurações da empresa não encontradas.', $chave);
            }

            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            $resp = $tools->sefazCancela($chave, $motivo, $protocolo);

            return $this->processarRespostaCancelamento($resp, $chave);
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao cancelar NF-e: ' . $e->getMessage(), $chave);
        }
    }

    /**
     * Parsing puro (sem I/O) da resposta de sefazCancela() — separado do
     * método público pra ser testável via reflection.
     *
     * CORRIGIDO vs. o brief: confirmado em Tools::sefazCancela() (Tools.php
     * ~600-615) que ele só monta o tagAdic de <nProt>/<xJust> e DELEGA pra
     * sefazEvento() — mesmo transporte que tentarEpec() já usa pro evento
     * EPEC (Tools::EVT_CANCELA vs Tools::EVT_EPEC, mesmo método por baixo).
     * A resposta é portanto um retEnvEvento, com o MESMO formato de dois
     * cStat (um de LOTE, outro aninhado em retEvento/infEvento) que
     * extrairCStatEvento() já existe pra tratar corretamente — confirmado
     * contra schemes/PL_009_V4/leiauteEvento_v1.00.xsd (TRetEnvEvento tem
     * cStat próprio, "status da registro do Evento" a nível de lote, ANTES
     * de retEvento na sequência) e contra o próprio uso interno do vendor
     * (Complements::addEnvEventoProtocol(), que explicitamente extrai o
     * cStat de DENTRO de retEvento, nunca um xpath genérico). O brief
     * original fazia um xpath plano `//nfe:cStat` direto na resposta —
     * exatamente o bug já corrigido (e coberto por teste,
     * MotorNfeEmitirTest::test_extrair_cstat_evento_ignora_cstat_de_lote_
     * usa_cstat_do_evento) em extrairCStatEvento(). Sem essa correção,
     * cancelar() reportaria "não confirmado" mesmo em cancelamentos
     * registrados com sucesso, porque o cStat de lote (tipicamente ~128,
     * "Lote de Evento Processado") nunca bate com os códigos de sucesso do
     * EVENTO. Corrigido reutilizando extrairCStatEvento() em vez de
     * duplicar o parsing.
     *
     * Códigos de sucesso 135/136 = evento registrado (vinculado ou não) —
     * mesma tabela usada por tentarEpec(). 155 = específico de
     * EVT_CANCELA, confirmado no próprio vendor: Complements::
     * addEnvEventoProtocol() monta $cStatValids = ['135','136'] e só
     * adiciona '155' quando $tpEvento == Tools::EVT_CANCELA
     * (Complements.php ~314-317) — a hipótese do brief pros 3 códigos já
     * batia com o vendor real.
     */
    private function processarRespostaCancelamento(string $respostaXml, string $chave): EmissaoResultado
    {
        $cStat = $this->extrairCStatEvento($respostaXml);
        if ($cStat === null) {
            return EmissaoResultado::erro('Resposta do cancelamento não pôde ser interpretada.', $chave);
        }

        if (in_array($cStat, ['135', '136', '155'], true)) {
            return EmissaoResultado::cancelada($chave);
        }

        return EmissaoResultado::erro("Cancelamento não confirmado (cStat={$cStat}).", $chave);
    }

    /**
     * Retransmite uma NF-e em CONTINGENCIA — usada pela reconciliação
     * agendada (Task 8) e por um reenvio manual futuro. [decisão do spec]
     * Consulta sefazConsultaChave() PRIMEIRO: se já está autorizada (a
     * transmissão pode ter chegado apesar do timeout que causou o EPEC),
     * apenas concilia localmente em vez de reenviar às cegas — mesma lição
     * das rodadas 7/8 do fluxo de pagamento (ack perdido não significa que o
     * efeito não aconteceu).
     *
     * AJUSTADO na Task 8: o mesmo raciocínio vale para CANCELADA — se a
     * consulta prévia mostra que a SEFAZ já homologou o cancelamento
     * (cStat 101/151, ver processarRespostaConsulta()), a nota foi
     * cancelada por fora (ex.: admin cancelou manualmente enquanto ela
     * estava em contingência) e reenviar o lote de qualquer forma só
     * gastaria uma chamada à SEFAZ para, na prática, ser rejeitado — sem
     * este guard, o comando de reconciliação hourly (Task 8) reenviaria a
     * mesma nota cancelada todo hour, indefinidamente, até o prazo de 7
     * dias estourar. Achado da revisão da Task 5, endereçado aqui porque é
     * o único lugar que chama retransmitir() em loop.
     */
    public function retransmitir(NotaFiscal $nota, string $ambiente): EmissaoResultado
    {
        if (empty($nota->chave_acesso)) {
            return EmissaoResultado::erro(
                'NF-e em contingência sem chave de acesso — não é possível retransmitir.',
                $nota->referencia_externa,
            );
        }

        $statusAtual = $this->consultar($nota->chave_acesso, $ambiente);
        if ($statusAtual->status === 'AUTORIZADA' || $statusAtual->status === 'CANCELADA') {
            return $statusAtual; // já autorizada ou cancelada de verdade — só concilia, não reenvia.
        }

        if (empty($nota->xml_retorno)) {
            return EmissaoResultado::erro(
                'NF-e em contingência sem XML salvo — não é possível retransmitir.',
                $nota->referencia_externa,
            );
        }

        // CORRIGIDO vs. o brief — mesmo achado documentado em consultar():
        // Configuracao::first() precisa estar DENTRO do try/catch. Aqui
        // não é exercido pelos testes desta task (o teste "sem xml salvo"
        // retorna antes de chegar aqui), mas é a mesma classe de bug —
        // corrigido por consistência, já que este bloco é código novo desta
        // task, não o emitir() já aprovado.
        try {
            $cfg = Configuracao::first();
            if (! $cfg) {
                return EmissaoResultado::erro('Configurações da empresa não encontradas.', $nota->referencia_externa);
            }

            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            // Reenvia o MESMO xml salvo (com tpEmis=4 já embutido pelo EPEC
            // original) — nunca remontamos o XML aqui. A chave de acesso
            // codifica o tipo de emissão; remontar mudaria a chave já
            // impressa no DANFE de contingência entregue ao cliente.
            $resp = $tools->sefazEnviaLote([$nota->xml_retorno], (string) $nota->numero, 1);

            return $this->processarRespostaAutorizacao($resp, $nota->referencia_externa, $nota->xml_retorno, (string) $nota->numero);
        } catch (\Throwable $e) {
            Log::warning(
                'MotorNfe: falha ao retransmitir NF-e em contingência.',
                ['erro' => $e->getMessage(), 'nota_id' => $nota->id],
            );
            return EmissaoResultado::erro('Falha ao retransmitir: ' . $e->getMessage(), $nota->referencia_externa);
        }
    }

    /**
     * Inutiliza uma faixa de numeração não usada (queda de processo entre
     * alocar o número e transmitir, ver spec Seção B). Ação administrativa
     * pontual, não parte do fluxo normal de emissão — não persiste como
     * `NotaFiscal` (ver NotaFiscalController::inutilizarNumeracao()).
     *
     * cStat de sucesso VERIFICADO CONTRA O VENDOR REAL, não só a hipótese do
     * brief: vendor/nfephp-org/sped-nfe/src/Complements.php::
     * addInutNFeProtocol() — o próprio método do pacote que processa esse
     * retorno internamente — faz `if ($cStat != 102) { throw ...; }`
     * (Complements.php ~182). 102 é exatamente o cStat que o pacote
     * instalado neste projeto trata como sucesso de inutilização, não uma
     * suposição não verificada. Confirmado também no XSD
     * (schemes/PL_009_V4/leiauteInutNFe_v4.00.xsd, TRetInutNFe): `cStat` é
     * um campo DIRETO de `infInut` — ao contrário de cancelar()/tentarEpec()
     * (retEnvEvento, cStat de LOTE antes de retEvento/infEvento/cStat), aqui
     * não existe "cStat de lote" concorrente, então o xpath plano
     * `//nfe:cStat` já pega o campo certo sem ambiguidade (mesma situação de
     * consultar()/processarRespostaConsulta(), documentada lá).
     *
     * Assinatura real de `Tools::sefazInutiliza()` (Tools.php ~194) é
     * `(int $nSerie, int $nIni, int $nFin, string $xJust, ?int $tpAmb = null,
     * ?string $ano = null): string` — os 4 primeiros parâmetros batem com o
     * brief; `$tpAmb`/`$ano` ficam null (default), porque `$tpAmb` já vem do
     * `configJson($cfg, $ambiente)` passado ao construtor de `Tools`
     * (`$this->tpAmb` interno), mesmo padrão que os outros métodos deste
     * arquivo já usam pra não duplicar a decisão de ambiente.
     */
    public function inutilizar(int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): EmissaoResultado
    {
        // Defensivo: o controller já valida numero_final >= numero_inicial
        // (regra `gte:numero_inicial`), mas este método público pode ser
        // chamado por qualquer outro caller futuro (console command, job)
        // sem passar pelo controller — falhar local e rápido aqui é melhor
        // que gastar uma chamada real à SEFAZ com uma faixa sem sentido.
        if ($numeroFinal < $numeroInicial) {
            return EmissaoResultado::erro('Número final não pode ser menor que o número inicial.');
        }

        // CORRIGIDO vs. o brief — mesmo achado já documentado em
        // consultar()/cancelar()/retransmitir(): `Configuracao::first()`
        // precisa estar DENTRO do try/catch. Sem conexão de banco disponível
        // (ambiente de teste sem DB, ver MotorNfeInutilizarTest),
        // `Model::resolveConnection()` lança um `\Error` fatal, não uma
        // `\Exception` — escaparia incapturado se ficasse fora do try, como
        // o brief original propunha.
        try {
            $cfg = Configuracao::first();
            if (! $cfg) {
                return EmissaoResultado::erro('Configurações da empresa não encontradas.');
            }

            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new Tools($this->configJson($cfg, $ambiente), $certificate);
            $tools->model(55);

            $resp = $tools->sefazInutiliza($serie, $numeroInicial, $numeroFinal, $justificativa);

            $sxml = @simplexml_load_string($resp);
            if ($sxml === false) {
                return EmissaoResultado::erro('Resposta da SEFAZ não pôde ser interpretada.');
            }
            $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
            $cStat = (string) ($sxml->xpath('//nfe:cStat')[0] ?? '');

            // 102 = Inutilização homologada — confirmado contra o vendor
            // real (ver docblock do método).
            if ($cStat === '102') {
                // [decisão do brief, preservada] reusa o status "efeito
                // concluído" de cancelada() pra sinalizar sucesso —
                // inutilização não é bem "nota cancelada", mas criar um
                // status novo só pra essa ação administrativa pontual (que
                // não persiste como NotaFiscal) seria over-engineering; o
                // controller só precisa saber "deu certo" ou "erro com
                // mensagem X", que os dois status já cobrem.
                return EmissaoResultado::cancelada();
            }

            $xMotivo = (string) ($sxml->xpath('//nfe:xMotivo')[0] ?? '');
            return EmissaoResultado::erro("Inutilização não homologada (cStat={$cStat}): {$xMotivo}");
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao inutilizar numeração: ' . $e->getMessage());
        }
    }

    /**
     * Consulta uma NF-e emitida CONTRA o CNPJ desta oficina (nota de
     * terceiro / entrada de mercadoria) direto na SEFAZ, via o webservice
     * nacional de Distribuição DFe — sem depender de provedor terceiro
     * (Spedy/Focus), usando só o certificado A1 da própria oficina.
     *
     * Confirmado em vendor/nfephp-org/sped-nfe/src/Tools.php:384 —
     * `sefazDistDFe(int $ultNSU = 0, int $numNSU = 0, ?string $chave = null,
     * string $fonte = 'AN'): string`: quando `$chave` é informada, o corpo
     * da requisição vira `<consChNFe><chNFe>...</chNFe></consChNFe>`
     * (consulta por chave específica, ignorando ultNSU/numNSU). Retorna o
     * corpo cru da resposta como string.
     *
     * CORRIGIDO vs. o brief desta rodada — mesmo achado já documentado em
     * consultar()/cancelar()/retransmitir()/inutilizar(): o brief deixava
     * `Configuracao::first()` FORA do try/catch. Sem conexão de banco
     * (ambiente de teste sem Postgres, ver memória do projeto),
     * `Model::resolveConnection()` lança um `\Error` fatal — não uma
     * `\Exception` — que escaparia incapturado em vez de virar um
     * `ConsultaNotaTerceiroResultado::erro()` gracioso. Corpo inteiro dentro
     * do try, igual ao resto deste arquivo.
     */
    public function consultarNotaRecebida(string $chaveAcesso, string $ambiente): ConsultaNotaTerceiroResultado
    {
        try {
            $cfg = Configuracao::first();
            if (! $cfg) {
                return ConsultaNotaTerceiroResultado::erro('Configurações da empresa não encontradas.');
            }

            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new Tools($this->configJson($cfg, $ambiente), $certificate);

            $respostaXml = $tools->sefazDistDFe(0, 0, $chaveAcesso);

            return $this->interpretarRespostaDistDFe($respostaXml, $chaveAcesso, $tools);
        } catch (\Throwable $e) {
            Log::warning('NFePHP/DistDFe: falha ao consultar NF-e de terceiro.', [
                'chave' => $chaveAcesso,
                'erro'  => $e->getMessage(),
            ]);
            return ConsultaNotaTerceiroResultado::erro('Falha ao consultar NF-e de terceiro: ' . $e->getMessage());
        }
    }

    /**
     * Parsing puro (sem I/O de rede) da resposta de sefazDistDFe() —
     * separado do método público pra ser testável via reflection, mesmo
     * padrão de processarRespostaConsulta()/extrairCStatEvento().
     *
     * Estrutura confirmada contra o XSD oficial do pacote instalado,
     * schemes/PL_010_V1.30/retDistDFeInt_v1.01.xsd: a raiz `retDistDFeInt`
     * tem tpAmb/verAplic/cStat/xMotivo/dhResp/ultNSU/maxNSU e um
     * `loteDistDFeInt` OPCIONAL (`minOccurs="0"`) com até 50 `docZip`
     * (`maxOccurs="50"`). Cada `docZip` é `xs:base64Binary` e a própria
     * documentação do XSD diz "O conteúdo desta tag estará compactado no
     * padrão gZip" — daí o base64_decode() + gzdecode(). O atributo
     * `schema` (obrigatório) identifica o documento: `resNFe_v1.xx.xsd`
     * (resumo, SEM itens), `procNFe_v3.10.xsd`/`procNFe_v4.00.xsd` (XML
     * completo da NF-e autorizada, COM itens — mesmo `nfeProc` que
     * NotaEntradaXmlParser já sabe parsear), `resEvento_1.00.xsd` /
     * `procEventoNFe_v1.00.xsd` (eventos, irrelevantes aqui).
     *
     * [decisão] cStat/xMotivo entram SÓ no log, nunca numa comparação de
     * controle de fluxo: o tipo do XSD é `TStat` genérico, sem nenhuma
     * enumeração de valores documentada nesse schema — hardcodar um número
     * como "não encontrado" seria inventar um contrato que o schema não dá.
     * O sinal usado é estrutural e está no próprio XSD: `loteDistDFeInt` é
     * opcional, então a AUSÊNCIA de `docZip` já É "nenhum documento",
     * qualquer que seja o código devolvido.
     *
     * $tools só é usado no branch "veio resumo, não veio XML completo", pra
     * manifestar ciência da operação.
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
        $docZips = $sxml->xpath('//nfe:loteDistDFeInt/nfe:docZip') ?: [];

        if ($docZips === []) {
            Log::info('NFePHP/DistDFe: nenhum docZip na resposta.', [
                'chave'   => $chaveAcesso,
                'cStat'   => (string) ($sxml->xpath('//nfe:cStat')[0] ?? ''),
                'xMotivo' => (string) ($sxml->xpath('//nfe:xMotivo')[0] ?? ''),
            ]);
            return ConsultaNotaTerceiroResultado::naoEncontrada();
        }

        foreach ($docZips as $docZip) {
            if (! str_starts_with((string) $docZip['schema'], 'procNFe')) {
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

        // Só veio resNFe (resumo, sem itens) — manifesta "ciência da
        // operação" (mesmo caminho de Spedy/Focus) e devolve aguardando.
        // Nunca inventa itens a partir do resumo: o resNFe não tem <det>
        // nenhum, e um lançamento de entrada sem itens é pior que nenhum.
        //
        // NÃO CONFIRMADO contra a SEFAZ real (exigiria certificado + rede):
        // se uma consulta consChNFe feita pelo próprio CNPJ destinatário já
        // devolve o procNFe direto, ou se depende de manifestação prévia
        // como acontece via Spedy/Focus. Por isso os dois cenários são
        // tratados, e o desconhecido degrada pro caminho seguro.
        try {
            $tools->sefazManifesta($chaveAcesso, Tools::EVT_CIENCIA);
        } catch (\Throwable $e) {
            Log::warning('NFePHP/DistDFe: falha ao manifestar ciência.', [
                'chave' => $chaveAcesso,
                'erro'  => $e->getMessage(),
            ]);
        }

        return ConsultaNotaTerceiroResultado::aguardandoManifestacao();
    }

    /**
     * Lista as NF-e emitidas contra o CNPJ desta oficina que o Ambiente
     * Nacional já tem pra distribuir — varredura por NSU
     * (`sefazDistDFe(0, 0, null)` monta `<distNSU><ultNSU>000...0</ultNSU>`,
     * confirmado em Tools.php:384).
     *
     * LIMITAÇÃO CONHECIDA E DELIBERADA DESTA v1: sem paginação. O XSD limita
     * cada resposta a 50 `docZip` (`maxOccurs="50"` em loteDistDFeInt) e
     * devolve `ultNSU`/`maxNSU` justamente pra o cliente continuar de onde
     * parou — nada disso é usado aqui: sempre recomeçamos do NSU 0 e
     * ficamos com o primeiro lote. Se houver mais de 50 documentos, os
     * excedentes não aparecem. Não implementado nesta rodada (YAGNI):
     * paginação correta exige um checkpoint de NSU persistido por oficina, e
     * o motor NFePHP não está registrado em nenhuma oficina em produção
     * ainda. Revisitar antes do primeiro uso real com volume.
     *
     * $cnpjOficina não é usado: o CNPJ consultado é sempre o do certificado/
     * configuração passada ao construtor de Tools (`configJson()`), não um
     * parâmetro do webservice. Mantido na assinatura por simetria com a
     * interface ConsultaNotaTerceiroProvider e com Spedy/Focus.
     *
     * CORRIGIDO vs. o brief desta rodada: o brief capturava \Throwable e
     * devolvia `[]`. Isso viola o contrato explícito de
     * ConsultaNotaTerceiroProvider::listarNotasRecebidas() ("@throws
     * \RuntimeException quando o provedor falha — nunca deve devolver `[]`
     * silenciosamente pra isso, só pra 'de fato não tem nota nenhuma'") e
     * quebraria EntradaNfController::recebidas(), que só distingue os dois
     * casos por essa exceção: uma queda da SEFAZ/certificado inválido
     * apareceria na tela como "nenhuma nota pendente", escondendo o erro
     * real do usuário — exatamente o bug que aquele contrato foi escrito pra
     * impedir. Aqui a falha vira RuntimeException; `[]` fica reservado pra
     * "a SEFAZ respondeu e não há documentos".
     *
     * @return list<ConsultaNotaTerceiroResumo>
     */
    public function listarNotasRecebidas(string $cnpjOficina, string $ambiente): array
    {
        try {
            $cfg = Configuracao::first();
            if (! $cfg) {
                throw new \RuntimeException('Configurações da empresa não encontradas.');
            }

            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new Tools($this->configJson($cfg, $ambiente), $certificate);

            $respostaXml = $tools->sefazDistDFe(0, 0, null);

            return $this->mapearListaDistDFe($respostaXml);
        } catch (\Throwable $e) {
            Log::warning('NFePHP/DistDFe: falha ao listar notas recebidas.', ['erro' => $e->getMessage()]);
            throw new \RuntimeException(
                'Falha ao consultar notas recebidas na SEFAZ: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Parsing puro (sem I/O) do lote devolvido por sefazDistDFe() sem chave.
     * Mesmo envelope de interpretarRespostaDistDFe() (ver lá o grounding no
     * XSD); a diferença é que aqui interessam TODOS os documentos, e tanto
     * o XML completo (procNFe) quanto o resumo (resNFe) viram uma linha da
     * listagem — a flag `completa` é o que diz à tela se ainda falta
     * manifestação pra conseguir os itens. Eventos (resEvento/
     * procEventoNFe) são descartados: não são notas.
     *
     * Um docZip corrompido é pulado com log, nunca derruba o lote inteiro —
     * perder uma linha é melhor que esconder as outras 49. Já uma resposta
     * INTEIRA ilegível é outra coisa: é falha, não "nenhuma nota", e por
     * isso lança (o catch de listarNotasRecebidas() converte na
     * RuntimeException que o contrato exige). Devolver `[]` aqui
     * reintroduziria pela porta dos fundos o bug que aquele contrato
     * existe pra impedir — a tela diria "nenhuma nota recebida" pra uma
     * SEFAZ devolvendo lixo/HTML de erro. `[]` fica reservado pro caso em
     * que a resposta é válida e o lote está ausente/vazio.
     *
     * @return list<ConsultaNotaTerceiroResumo>
     */
    private function mapearListaDistDFe(string $xml): array
    {
        libxml_use_internal_errors(true);
        $sxml = simplexml_load_string($xml);
        libxml_clear_errors();

        if ($sxml === false) {
            throw new \RuntimeException('Resposta da SEFAZ não pôde ser interpretada ao listar notas recebidas.');
        }

        $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
        $docZips = $sxml->xpath('//nfe:loteDistDFeInt/nfe:docZip') ?: [];

        $resumos = [];
        foreach ($docZips as $docZip) {
            $schema   = (string) $docZip['schema'];
            $completo = str_starts_with($schema, 'procNFe');

            if (! $completo && ! str_starts_with($schema, 'resNFe')) {
                continue;
            }

            $conteudoGzip = base64_decode((string) $docZip, true);
            $xmlDoc       = $conteudoGzip !== false ? @gzdecode($conteudoGzip) : false;

            if ($xmlDoc === false) {
                Log::warning('NFePHP/DistDFe: docZip ilegível na listagem, pulado.', ['schema' => $schema]);
                continue;
            }

            $resumo = $this->resumoDeDocumento($xmlDoc, $completo);
            if ($resumo !== null) {
                $resumos[] = $resumo;
            }
        }

        return $resumos;
    }

    /**
     * procNFe e resNFe têm formatos DIFERENTES e por isso são lidos
     * separadamente (confirmado nos XSDs do pacote):
     * - procNFe: `NFe > infNFe`, com a chave no atributo `Id` (prefixado
     *   por "NFe"), emitente em `emit`, data em `ide/dhEmi` e total em
     *   `total/ICMSTot/vNF`.
     * - resNFe (resNFe_v1.01.xsd): campos direto na raiz — `chNFe`, `CNPJ`,
     *   `xNome`, `dhEmi`, `vNF`.
     *
     * O namespace é removido por regex antes do parse (mesma técnica já
     * usada em NotaEntradaXmlParser::parse()), pra poder acessar os nós por
     * nome sem registrar prefixo em cada sub-elemento.
     */
    private function resumoDeDocumento(string $xmlDoc, bool $completo): ?ConsultaNotaTerceiroResumo
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string((string) preg_replace('/xmlns="[^"]*"/', '', $xmlDoc));
        libxml_clear_errors();

        if ($doc === false) {
            return null;
        }

        if ($completo) {
            $inf = $doc->NFe->infNFe ?? $doc->infNFe ?? null;
            if ($inf === null) {
                return null;
            }

            $chaveBruta = (string) ($inf['Id'] ?? '');

            return new ConsultaNotaTerceiroResumo(
                chaveAcesso: str_starts_with($chaveBruta, 'NFe') ? substr($chaveBruta, 3) : $chaveBruta,
                fornecedorNome: ((string) ($inf->emit->xNome ?? '')) ?: null,
                fornecedorCnpj: ((string) ($inf->emit->CNPJ ?? '')) ?: null,
                dataEmissao: substr((string) ($inf->ide->dhEmi ?? ''), 0, 10) ?: null,
                valorTotal: (float) ($inf->total->ICMSTot->vNF ?? 0),
                completa: true,
            );
        }

        return new ConsultaNotaTerceiroResumo(
            chaveAcesso: (string) ($doc->chNFe ?? ''),
            fornecedorNome: ((string) ($doc->xNome ?? '')) ?: null,
            fornecedorCnpj: ((string) ($doc->CNPJ ?? '')) ?: null,
            dataEmissao: substr((string) ($doc->dhEmi ?? ''), 0, 10) ?: null,
            valorTotal: (float) ($doc->vNF ?? 0),
            completa: false,
        );
    }
}
