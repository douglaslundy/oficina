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
 *
 * Todas as afirmações abaixo sobre o comportamento da Make foram confirmadas
 * lendo vendor/nfephp-org/sped-nfe/src/Make.php e as traits em
 * vendor/nfephp-org/sped-nfe/src/Traits/*.php nesta sessão (o brief original
 * era uma hipótese de trabalho, não uma transcrição verificada — ver
 * task-3-report.md para o detalhamento completo das divergências).
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
        // retorna null quando nenhum CNPJ/CPF foi adicionado, e acessar
        // ->nodeValue em null é um \Error (TypeError) em PHP 8, NÃO uma
        // \Exception — o catch(\Exception) dentro de render() não pega isso,
        // e a chamada quebra com erro fatal não tratado. Confirmado lendo o
        // método real, não hipotético.
        $make->tagemit((object) [
            'CNPJ'  => preg_replace('/\D/', '', $cfg->cnpj ?? ''),
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
}
