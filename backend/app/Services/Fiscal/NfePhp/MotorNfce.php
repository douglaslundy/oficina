<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Services\Fiscal\CrtResolver;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\NfePhp\Concerns\ProcessaRespostaSefaz;
use App\Services\NfeService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\SoapException;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;

/**
 * Motor de NFC-e (modelo 65) via NFePHP — pedido explícito do usuário
 * (2026-09-14): "implemente um motor de NFC-e completo pra NFePHP", depois
 * de descobrir na auditoria anterior que `NfePhpProvider` rejeitava NFCE de
 * propósito (não existia motor nenhum pra esse modelo).
 *
 * Reusa a maior parte da estrutura de `MotorNfe` (mesma biblioteca Make/
 * Tools, mesmo schema nacional de resposta — daí a extração de
 * `ProcessaRespostaSefaz`), mas com as diferenças reais entre NF-e e NFC-e
 * confirmadas lendo o vendor (nunca supostas):
 *
 * - `mod: 65`, `tpImp: 4` (DANFCE), `idDest` SEMPRE 1 (NFC-e não pode ser
 *   interestadual — ao contrário da NF-e, que aceita idDest 1 ou 2).
 * - QR Code: `Tools::signNFe()` (Common/Tools.php ~389-393) JÁ detecta
 *   `mod == 65` e chama `addQRCode()` automaticamente depois de assinar —
 *   não precisei montar QRCode::putQRTag() manualmente. Só precisei
 *   garantir que `CSC`/`CSCid` cheguem no config.json passado ao construtor
 *   de `Tools` (addQRCode() lê `$this->config->CSC`/`CSCid`, lança
 *   RuntimeException se faltarem — confirmado em Common/Tools.php ~576-580).
 * - Contingência: NFC-e NÃO usa EPEC (Common/Tools::
 *   checkContingencyForWebServices() lança explicitamente "Não existe
 *   serviço para contingência SVCRS ou SVCAN para NFCe" quando
 *   `$this->modelo == 65` — confirmado lendo o vendor). O modo real de
 *   contingência de NFC-e é OFFLINE (tpEmis=9): monta e assina a NFC-e
 *   localmente com tpEmis=9 (o QRCode automaticamente sai na variante
 *   offline — `QRCode::get200()`/`get300()` já ramificam em `tpEmis != 9`
 *   internamente), sem nenhuma chamada de rede — o cliente recebe o DANFCE
 *   na hora, e o XML já assinado é retransmitido depois via
 *   `sefazEnviaLote()` normal quando a conexão voltar (retransmitir()
 *   abaixo, mesmo padrão de MotorNfe::retransmitir() pra EPEC). Muito mais
 *   simples que EPEC — não precisa registrar evento prévio nenhum.
 */
class MotorNfce
{
    use ProcessaRespostaSefaz;

    public function __construct(
        private readonly CertificadoStore $certificados = new CertificadoStore(),
        private readonly NfeService $numeracao = new NfeService(),
    ) {}

    public function montarNfce(
        NotaFiscalData $nota,
        Configuracao $cfg,
        string $ambiente,
        int $numeroNfce,
        int $serieNfce,
        int $tpEmis = 1,
    ): string {
        $crt = CrtResolver::resolver($cfg->regime_tributario ?? '');

        // Mesma guarda explícita de MotorNfe::montarNfe() — sem ela, um
        // CNPJ vazio corrompe silenciosamente a chave de acesso em produção
        // (PHP puro não converte o E_WARNING de ->nodeValue em null numa
        // exceção catchável, só o PHPUnit faz isso).
        $cnpjLimpo = preg_replace('/\D/', '', $cfg->cnpj ?? '') ?? '';
        if ($cnpjLimpo === '') {
            throw new \InvalidArgumentException('CNPJ da empresa não configurado — não é possível montar a chave de acesso da NFC-e sem ele.');
        }

        $make = new Make();

        $make->taginfNFe((object) [
            'versao' => '4.00',
            'Id'     => null,
            'pk_nItem' => '',
        ]);

        $make->tagide((object) [
            'cUF'      => $this->cUfMg(),
            'natOp'    => $nota->naturezaOperacao,
            'mod'      => 65,
            'serie'    => $serieNfce,
            'nNF'      => $numeroNfce,
            'dhEmi'    => now()->format('c'),
            'tpNF'     => 1, // Saída
            'idDest'   => 1, // NFC-e é sempre operação interna — nunca interestadual.
            'cMunFG'   => $cfg->codigo_ibge,
            'tpImp'    => 4, // DANFCE (documento auxiliar simplificado/cupom)
            'tpEmis'   => $tpEmis, // 1 = normal; 9 = contingência offline (ver docblock da classe)
            'tpAmb'    => $ambiente === 'PRODUCAO' ? 1 : 2,
            'finNFe'   => 1,
            'indFinal' => 1, // Consumidor final — sempre, é a própria definição de NFC-e.
            'indPres'  => 1, // Operação presencial
            'procEmi'  => 0,
            'verProc'  => config('app.version', '1.0.0'),
        ]);

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

        // Destinatário na NFC-e não exige endereço (ao contrário da NF-e).
        // Corrigido — achado real rodando schemaValidate(): a suposição
        // original de que `indIEDest` "não existe pra NFC-e" estava errada
        // — é o MESMO XSD (`nfe_v4.00.xsd`, sem `nfce_v4.00.xsd` separado
        // no vendor) e a própria Make já inclui `indIEDest` por padrão
        // mesmo sem pedirmos, sem violar o schema. Este sistema sempre
        // vincula um Cliente com CPF/CNPJ à nota, então incluímos o
        // documento quando presente; omitimos o grupo inteiro só se não
        // houver nenhum (venda anônima de balcão), caso que Make::tagdest()
        // aceita — dest é opcional na NFC-e.
        $docTomador = preg_replace('/\D/', '', $nota->tomador['cpf_cnpj'] ?? '') ?? '';
        if ($docTomador !== '') {
            $make->tagdest((object) array_filter([
                (strlen($docTomador) > 11 ? 'CNPJ' : 'CPF') => $docTomador,
                'xNome' => $nota->tomador['nome'] ?? '',
            ]));
        }

        foreach ($nota->itens as $i => $item) {
            $nItem = $i + 1;

            // Mesmo bug real de MotorNfe::montarNfe() ("cStat=883: GTIN
            // (cEAN) sem informação") — SEFAZ exige cEAN/cEANTrib
            // preenchidos desde 12/09/2022, com o GTIN de verdade ou o
            // literal "SEM GTIN" quando o produto não tem código de barras.
            $gtin = trim((string) ($item['codigo_barras'] ?? '')) ?: 'SEM GTIN';

            // Mesmo bug real de MotorNfe::montarNfe() ("cStat=806: Operação
            // com ICMS-ST sem informação do CEST") — CEST nunca era lido/
            // mandado aqui também, mesmo quando o produto já tinha o dado.
            $make->tagprod((object) [
                'item'    => $nItem,
                'cProd'   => $item['sku'] ?? $item['produto_id'],
                'cEAN'    => $gtin,
                'xProd'   => $item['descricao'],
                'NCM'     => $item['ncm'],
                'CEST'    => $item['cest'] ?? null,
                'CFOP'    => $item['cfop'],
                'uCom'    => $item['unidade'] ?? 'UN',
                'qCom'    => $item['quantidade'],
                'vUnCom'  => $item['valor_unitario'],
                'vProd'   => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                'cEANTrib' => $gtin,
                'uTrib'   => $item['unidade'] ?? 'UN',
                'qTrib'   => $item['quantidade'],
                'vUnTrib' => $item['valor_unitario'],
                'indTot'  => 1,
            ]);

            $tributacaoSt = $item['tributacao_icms'] === 'ST';

            // Mesma lógica ICMS/ICMSSN de MotorNfe::montarNfe() — schema
            // idêntico pros dois modelos nesse grupo.
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

            // IBS/CBS: mesma decisão de MotorNfe — não implementado nesta
            // v1 (schema=9/PL_009_V4 não monta o bloco pra CRT algum; ver
            // docblock equivalente em MotorNfe::montarNfe()).

            // PIS/COFINS: obrigatório por item no XSD independente do CRT
            // — mesmo CST 49 (Outras Operações) com base/valor zerados já
            // usado em MotorNfe::montarNfe(), mesma justificativa (Simples
            // Nacional paga PIS/COFINS via DAS, não por operação).
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

        // CORRIGIDO — achado real rodando schemaValidate() contra o XSD
        // oficial (mesmo `nfe_v4.00.xsd` serve NF-e e NFC-e — não existe um
        // nfce_v4.00.xsd separado no vendor): a suposição original era que
        // NFC-e não precisava de `<transp>` por não ter frete de balcão —
        // ERRADO, o XSD exige `<transp>` ANTES de `<pag>` na sequência,
        // independente do modelo ("Element 'pag': This element is not
        // expected. Expected is ( transp )."). Sem isso, TODA emissão de
        // NFC-e seria rejeitada por schema, sempre — mesmo `modFrete=9`
        // (sem frete) que MotorNfe já usa pra NF-e.
        $make->tagtransp((object) ['modFrete' => 9]);

        // Bug real de produção (2026-09-15, "cStat=441: Rejeicao: Descricao
        // do pagamento obrigatoria para meio de pagamento 99-outros"):
        // `tPagDe()` já mapeava certo, mas a SEFAZ exige `xPag` (descrição)
        // sempre que o resultado é '99' — nunca mandado aqui. Mesmo fix
        // aplicado em MotorNfe::montarNfe().
        $tPag = $this->tPagDe($nota->formaPagamento);
        $make->tagpag((object) []);
        $make->tagdetPag((object) [
            'indPag' => 0,
            'tPag'   => $tPag,
            'xPag'   => $tPag === '99' ? ($nota->formaPagamento ?: 'Outros') : null,
            'vPag'   => $vProdTotal,
        ]);

        $xml = $make->getXML();
        if ($make->getErrors() !== []) {
            throw new \RuntimeException('Falha ao montar XML da NFC-e: ' . implode('; ', $make->getErrors()));
        }

        return $xml;
    }

    /**
     * Tabela oficial de formas de pagamento (NT vigente) — mapeamento
     * best-effort a partir do texto livre já usado no resto do sistema
     * (`OrdemServico.forma_pagamento`/`NotaFiscal.forma_pagamento`).
     *
     * Correção 2026-09-15: o comentário original aqui dizia que cair em
     * '99' (Outros) "nunca falha a emissão" — ERRADO, confirmado por
     * rejeição real (cStat=441: "Descricao do pagamento obrigatoria para
     * meio de pagamento 99-outros"). `montarNfce()` agora manda `xPag`
     * sempre que o resultado aqui é '99'.
     */
    private function tPagDe(string $formaPagamento): string
    {
        $normalizado = strtoupper(trim($formaPagamento));
        return match (true) {
            $normalizado === '' => '99',
            str_contains($normalizado, 'DINHEIRO') => '01',
            str_contains($normalizado, 'DEBITO') || str_contains($normalizado, 'DÉBITO') => '04',
            str_contains($normalizado, 'CREDITO') || str_contains($normalizado, 'CRÉDITO') => '03',
            str_contains($normalizado, 'PIX') => '17',
            default => '99',
        };
    }

    private function cUfMg(): int
    {
        return 31;
    }

    public function emitir(NotaFiscalData $nota, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $nota->referenciaExterna);
        }

        if (strtoupper((string) ($cfg->uf ?? '')) !== 'MG') {
            return EmissaoResultado::erro(
                'MotorNfce só emite NFC-e para oficinas em MG — cUfMg() está fixo em 31/MG (mesma limitação de MotorNfe).',
                $nota->referenciaExterna,
            );
        }

        $csc = $this->cscDe($cfg, $ambiente);
        if ($csc === null) {
            return EmissaoResultado::erro(
                'CSC (Código de Segurança do Contribuinte) não configurado para NFC-e — cadastre em Configurações › Fiscal antes de emitir.',
                $nota->referenciaExterna,
            );
        }

        try {
            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);

            $numeroNfce = $nota->numeroReservado !== null
                ? (int) $nota->numeroReservado
                : $this->numeracao->proximoNumeroNfceNfephp();
            $serieNfce  = (int) ($cfg->serie_nfce ?: 1);

            $tools = new Tools($this->configJson($cfg, $ambiente, $csc), $certificate);
            $tools->model(65);

            $xml = $this->montarNfce($nota, $cfg, $ambiente, $numeroNfce, $serieNfce);

            try {
                // signNFe() já detecta mod=65 e adiciona o QRCode
                // automaticamente (ver docblock da classe) — nenhuma chamada
                // manual a QRCode::putQRTag() necessária aqui.
                $xmlAssinado = $tools->signNFe($xml);
                $resp = $tools->sefazEnviaLote([$xmlAssinado], (string) $numeroNfce, 1);

                return $this->processarRespostaAutorizacao($resp, $nota->referenciaExterna, $xmlAssinado, (string) $numeroNfce);
            } catch (SoapException $eTransmissao) {
                // Diferente de MotorNfe: NFC-e não usa EPEC (o vendor recusa
                // explicitamente esse tipo de contingência pra modelo 65 —
                // ver docblock da classe). A contingência real de NFC-e é
                // OFFLINE: remonta com tpEmis=9 (muda a chave de acesso, por
                // isso remontamos em vez de só trocar um campo) e assina —
                // sem nenhuma chamada de rede. O cliente recebe o DANFCE na
                // hora; a retransmissão de verdade acontece depois (ver
                // retransmitir()).
                Log::warning(
                    'MotorNfce: falha na transmissão normal, caindo para contingência offline (tpEmis=9).',
                    ['erro' => $eTransmissao->getMessage(), 'ref' => $nota->referenciaExterna],
                );

                try {
                    $xmlOffline = $this->montarNfce($nota, $cfg, $ambiente, $numeroNfce, $serieNfce, tpEmis: 9);
                    $xmlOfflineAssinado = $tools->signNFe($xmlOffline);

                    $dom = new \DOMDocument('1.0', 'UTF-8');
                    $dom->loadXML($xmlOfflineAssinado);
                    $chNFe = substr($dom->getElementsByTagName('infNFe')->item(0)?->getAttribute('Id') ?? '', 3, 44);
                    if ($chNFe === '' || strlen($chNFe) !== 44) {
                        throw new \RuntimeException('Não foi possível extrair a chave de acesso da NFC-e offline.');
                    }

                    return EmissaoResultado::contingencia($chNFe, (string) $numeroNfce, $xmlOfflineAssinado, $nota->referenciaExterna);
                } catch (\Throwable $eOffline) {
                    Log::warning('MotorNfce: falha também na contingência offline.', ['erro' => $eOffline->getMessage(), 'ref' => $nota->referenciaExterna]);
                    return EmissaoResultado::erro(
                        'SEFAZ indisponível e contingência offline da NFC-e também falhou: ' . $eOffline->getMessage(),
                        $nota->referenciaExterna,
                        (string) $numeroNfce,
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MotorNfce: falha ao emitir.', ['erro' => $e->getMessage(), 'ref' => $nota->referenciaExterna]);
            return EmissaoResultado::erro(
                'Falha técnica ao emitir NFC-e via NFePHP: ' . $e->getMessage(),
                $nota->referenciaExterna,
                isset($numeroNfce) ? (string) $numeroNfce : null,
            );
        }
    }

    /**
     * CSC/CSCId são secrets DISTINTOS por ambiente (homologação e produção
     * são cadastros separados no portal da SEFAZ) — ver migração
     * `add_nfce_csc_and_modelo_venda_padrao_to_configuracoes`. Retorna null
     * quando o par do ambiente ativo não está configurado (emitir() vira
     * ERRO explícito em vez de deixar addQRCode() do vendor lançar uma
     * RuntimeException genérica lá dentro).
     */
    private function cscDe(Configuracao $cfg, string $ambiente): ?array
    {
        $id        = $ambiente === 'PRODUCAO' ? $cfg->csc_id_producao : $cfg->csc_id_homologacao;
        $encrypted = $ambiente === 'PRODUCAO' ? $cfg->csc_token_producao_encrypted : $cfg->csc_token_homologacao_encrypted;

        if (empty($id) || empty($encrypted)) {
            return null;
        }

        try {
            $token = Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }

        return ['id' => $id, 'token' => $token];
    }

    /**
     * @see MotorNfe::configJson() — mesma estrutura, mais CSC/CSCid (exigidos
     * por Common\Tools::addQRCode(), lidos de $this->config->CSC/CSCid).
     */
    private function configJson(Configuracao $cfg, string $ambiente, array $csc): string
    {
        return json_encode([
            'atualizacao'  => now()->format('Y-m-d H:i:s'),
            'tpAmb'        => $ambiente === 'PRODUCAO' ? 1 : 2,
            'razaosocial'  => $cfg->razao_social,
            'siglaUF'      => $cfg->uf ?: 'MG',
            'cnpj'         => preg_replace('/\D/', '', $cfg->cnpj ?? ''),
            'schemes'      => 'PL_009_V4',
            'versao'       => '4.00',
            'CSC'          => $csc['token'],
            'CSCid'        => $csc['id'],
        ]) ?: '{}';
    }

    public function consultar(string $chave, string $ambiente): EmissaoResultado
    {
        try {
            $cfg = Configuracao::first();
            if (! $cfg) {
                return EmissaoResultado::erro('Configurações da empresa não encontradas.', $chave);
            }

            $csc = $this->cscDe($cfg, $ambiente) ?? ['id' => '', 'token' => ''];

            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new Tools($this->configJson($cfg, $ambiente, $csc), $certificate);
            $tools->model(65);

            $resp = $tools->sefazConsultaChave($chave);

            return $this->processarRespostaConsulta($resp, $chave);
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao consultar NFC-e: ' . $e->getMessage(), $chave);
        }
    }

    public function cancelar(string $chave, string $motivo, string $protocolo, string $ambiente): EmissaoResultado
    {
        try {
            $cfg = Configuracao::first();
            if (! $cfg) {
                return EmissaoResultado::erro('Configurações da empresa não encontradas.', $chave);
            }

            $csc = $this->cscDe($cfg, $ambiente) ?? ['id' => '', 'token' => ''];

            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new Tools($this->configJson($cfg, $ambiente, $csc), $certificate);
            $tools->model(65);

            $resp = $tools->sefazCancela($chave, $motivo, $protocolo);

            return $this->processarRespostaCancelamento($resp, $chave);
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao cancelar NFC-e: ' . $e->getMessage(), $chave);
        }
    }

    /**
     * Retransmite uma NFC-e em contingência OFFLINE — ao contrário do EPEC
     * (NF-e), não existe evento prévio pra confirmar: o XML já foi assinado
     * e entregue ao cliente (DANFCE impresso), só falta mesmo transmitir
     * pra SEFAZ. Mesmo guard de MotorNfe::retransmitir(): consulta antes de
     * reenviar (a transmissão pode ter ido apesar do timeout que causou a
     * contingência).
     */
    public function retransmitir(NotaFiscal $nota, string $ambiente): EmissaoResultado
    {
        if (empty($nota->chave_acesso)) {
            return EmissaoResultado::erro(
                'NFC-e em contingência sem chave de acesso — não é possível retransmitir.',
                $nota->referencia_externa,
            );
        }

        $statusAtual = $this->consultar($nota->chave_acesso, $ambiente);
        if ($statusAtual->status === 'AUTORIZADA' || $statusAtual->status === 'CANCELADA') {
            return $statusAtual;
        }

        if (empty($nota->xml_retorno)) {
            return EmissaoResultado::erro(
                'NFC-e em contingência sem XML salvo — não é possível retransmitir.',
                $nota->referencia_externa,
            );
        }

        try {
            $cfg = Configuracao::first();
            if (! $cfg) {
                return EmissaoResultado::erro('Configurações da empresa não encontradas.', $nota->referencia_externa);
            }

            $csc = $this->cscDe($cfg, $ambiente) ?? ['id' => '', 'token' => ''];

            $dados       = $this->certificados->obter($cfg);
            $certificate = Certificate::readPfx($dados['pfx'], $dados['senha']);
            $tools       = new Tools($this->configJson($cfg, $ambiente, $csc), $certificate);
            $tools->model(65);

            // Reenvia o MESMO xml salvo (com tpEmis=9 e QRCode offline já
            // embutidos) — nunca remontamos aqui, senão a chave de acesso
            // mudaria em relação ao DANFCE já impresso e entregue.
            $resp = $tools->sefazEnviaLote([$nota->xml_retorno], (string) $nota->numero, 1);

            return $this->processarRespostaAutorizacao($resp, $nota->referencia_externa, $nota->xml_retorno, (string) $nota->numero);
        } catch (\Throwable $e) {
            Log::warning(
                'MotorNfce: falha ao retransmitir NFC-e em contingência.',
                ['erro' => $e->getMessage(), 'nota_id' => $nota->id],
            );
            return EmissaoResultado::erro('Falha ao retransmitir: ' . $e->getMessage(), $nota->referencia_externa);
        }
    }
}
