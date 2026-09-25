<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

use App\Models\NotaFiscal;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;

/**
 * DANFSe v2.0 (Documento Auxiliar da NFS-e) — clone do PDF oficial gerado pelo
 * Emissor Nacional (o PDF-modelo é particular e não é versionado).
 *
 * Geometria copiada do PDF oficial (A4 595x842pt): 4 colunas em x = 11,9 /
 * 156,5 / 301,0 / 445,6; rótulos Arial Bold 6/7pt, valores Microsoft Sans Serif
 * 7pt (aqui Helvetica/Arial, mesmas métricas), células cinza #f2f2f2, filetes de
 * 0,5pt. Cada texto é posicionado pela LINHA BASE, como no original — o Blade
 * (`pdf.danfse`) só desenha o que este renderizador devolve.
 *
 * Os dados vêm do XML autorizado da NFS-e nacional (`NFSe/infNFSe`). Para notas
 * de outros provedores (Spedy/Focus, XML municipal) cai nos dados do banco; o que
 * não existe sai "-", exatamente como o documento oficial faz com campo vazio.
 */
final class DanfseRenderer
{
    private const X = [11.9, 156.5, 301.0, 445.6];
    private const LARGURA_LINHA = 578.27; // 8,5 → 586,77
    private const ESPACO_LINHA = 7.9;     // distância entre linhas de valor
    private const LARGURA_TEXTO_CHEIO = 574.0;
    private const LARGURA_TRUNCAR_SERVICO = 545.0; // o original corta a descrição do serviço da LC 116 antes da margem

    private const UF_POR_IBGE = [
        '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO',
        '21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL',
        '28' => 'SE', '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR',
        '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF',
    ];

    private ?FontMetrics $metricas = null;

    /**
     * @param array<string, mixed> $empresa Configuracao::toArray() (fallback quando não há XML nacional).
     * @return array{textos: list<array<string,mixed>>, retangulos: list<array<string,mixed>>, filetes: list<array<string,float>>, imagens: array<string,mixed>, borda: array<string,float>, titulo: string}
     */
    public function dadosParaTemplate(NotaFiscal $nota, array $empresa, ?string $qrCodeDataUri, string $logoDataUri): array
    {
        $d = $this->extrair($nota, $empresa);

        $textos = [];
        $retangulos = [];
        $filetes = [];

        // ---- Cabeçalho (3 células cinza + título centralizado + município/ambiente)
        foreach ([[8.5, 153.07], [153.07, 442.2], [442.2, 586.77]] as [$a, $b]) {
            $retangulos[] = ['x' => $a, 'y' => 5.67, 'w' => round($b - $a, 2), 'h' => 34.01];
        }
        $filetes[] = $this->filete(39.93);
        $textos[] = $this->texto(153.07, 20.8, 'DANFSe v2.0', 9, true, 289.13, 'center');
        $textos[] = $this->texto(153.07, 31.1, 'Documento Auxiliar da NFS-e', 9, true, 289.13, 'center');
        $textos[] = $this->texto(445.6, 18.7, 'Município: ' . $d['municipio_uf'], 8);
        $textos[] = $this->texto(445.6, 25.9, 'Ambiente Gerador: ' . $d['amb_ger'], 6);
        $textos[] = $this->texto(445.6, 32.7, 'Tipo de Ambiente: ' . $d['tp_amb'], 6);

        // ---- Chave + numeração
        $this->campo($textos, 11.9, 51.0, 58.9, 'CHAVE DE ACESSO DA NFS-e', $d['chave'], 7);
        $tri = [[71.2, 79.2, 'NÚMERO DA NFS-e', 'numero', 'COMPETÊNCIA DA NFS-e', 'competencia', 'DATA E HORA DA EMISSÃO DA NFS-e', 'dh_emissao'],
                [91.5, 99.4, 'NÚMERO DA DPS', 'n_dps', 'SÉRIE DA DPS', 'serie_dps', 'DATA E HORA DA EMISSÃO DA DPS', 'dh_dps'],
                [111.7, 119.6, 'EMITENTE DA NFS-e', 'emitente', 'SITUAÇÃO DA NFS-e', 'situacao', 'FINALIDADE', 'finalidade']];
        foreach ($tri as [$yl, $yv, $l0, $k0, $l1, $k1, $l2, $k2]) {
            $this->campo($textos, self::X[0], $yl, $yv, $l0, $d[$k0], 7);
            $this->campo($textos, self::X[1], $yl, $yv, $l1, $d[$k1], 7);
            $this->campo($textos, self::X[2], $yl, $yv, $l2, $d[$k2], 7);
        }
        // QR + legenda
        $textos[] = $this->texto(445.6, 97.4, 'A autenticidade desta NFS-e pode ser verificada', 6);
        $textos[] = $this->texto(445.6, 104.2, 'pela leitura deste código QR ou pela consulta da', 6);
        $textos[] = $this->texto(445.6, 111.0, 'chave de acesso no portal nacional da NFS-e', 6);

        // ---- Prestador
        $filetes[] = $this->filete(125.58);
        $retangulos[] = ['x' => 8.5, 'y' => 105.11, 'w' => 144.57, 'h' => 20.22];   // EMITENTE
        $retangulos[] = ['x' => 8.5, 'y' => 125.83, 'w' => 144.57, 'h' => 19.08];   // PRESTADOR
        $textos[] = $this->texto(self::X[0], 132.4, 'PRESTADOR / FORNECEDOR', 7, true);
        $this->campo($textos, self::X[1], 131.5, 139.2, 'CNPJ / CPF / NIF', $d['prest_doc']);
        $this->campo($textos, self::X[2], 131.5, 139.2, 'Indicador Municipal (Inscrição)', $d['prest_im']);
        $this->campo($textos, self::X[3], 131.5, 139.2, 'Telefone', $d['prest_fone']);
        $this->campo($textos, self::X[0], 150.5, 158.3, 'Nome / Nome Empresarial', $d['prest_nome']);
        $this->campo($textos, self::X[2], 150.5, 158.3, 'Município / Sigla UF', $d['prest_mun_uf']);
        $this->campo($textos, self::X[3], 150.5, 158.3, 'Código IBGE / CEP', $d['prest_ibge_cep']);
        $this->campo($textos, self::X[0], 169.6, 177.3, 'Endereço', $d['prest_end']);
        $this->campo($textos, self::X[2], 169.6, 177.3, 'E-mail', $d['prest_email']);
        $this->campo($textos, self::X[0], 188.7, 196.4, 'Simples Nacional na Data de Competência', $this->truncar($d['prest_sn'], 141.0));
        $this->campo($textos, self::X[1], 188.7, 196.4, 'Regime de Apuração Tributária pelo SN', $d['prest_regap']);

        // ---- Tomador
        $filetes[] = $this->filete(202.38);
        $retangulos[] = ['x' => 8.5, 'y' => 202.63, 'w' => 144.57, 'h' => 19.07];
        $textos[] = $this->texto(self::X[0], 209.2, 'TOMADOR / ADQUIRENTE', 7, true);
        $this->campo($textos, self::X[1], 208.3, 216.0, 'CNPJ / CPF / NIF', $d['toma_doc']);
        $this->campo($textos, self::X[2], 208.3, 216.0, 'Indicador Municipal (Inscrição)', $d['toma_im']);
        $this->campo($textos, self::X[3], 208.3, 216.0, 'Telefone', $d['toma_fone']);
        $this->campo($textos, self::X[0], 227.3, 235.1, 'Nome / Nome Empresarial', $d['toma_nome']);
        $this->campo($textos, self::X[2], 227.3, 235.1, 'Município / Sigla UF', $d['toma_mun_uf']);
        $this->campo($textos, self::X[3], 227.3, 235.1, 'Código IBGE / CEP', $d['toma_ibge_cep']);
        $this->campo($textos, self::X[0], 246.4, 254.1, 'Endereço', $d['toma_end']);
        $this->campo($textos, self::X[2], 246.4, 254.1, 'E-mail', $d['toma_email']);

        // ---- Destinatário / Intermediário (o sistema nunca os informa)
        $filetes[] = $this->filete(260.1);
        $filetes[] = $this->filete(268.53);
        $filetes[] = $this->filete(276.95);
        $textos[] = $this->texto(8.5, 266.8, 'DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e', 7, false, 578.27, 'center');
        $textos[] = $this->texto(8.5, 275.2, 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e', 7, false, 578.27, 'center');

        // ---- Serviço prestado
        $retangulos[] = ['x' => 8.5, 'y' => 277.2, 'w' => 144.57, 'h' => 19.07];
        $textos[] = $this->texto(self::X[0], 283.8, 'SERVIÇO PRESTADO', 7, true);
        $this->campo($textos, self::X[1], 282.8, 290.6, 'Código de Tributação Nacional/Municipal', $d['cod_trib']);
        $this->campo($textos, self::X[2], 282.8, 290.6, 'Código da NBS', $d['nbs']);
        $this->campo($textos, self::X[3], 282.8, 290.6, 'Local da Prestação / Sigla UF / País', $d['local_prest']);
        $textos[] = $this->texto(self::X[0], 302.7, $this->truncar($d['xtribnac'], self::LARGURA_TRUNCAR_SERVICO), 7);
        $textos[] = $this->texto(self::X[0], 314.1, 'Descrição do Serviço', 6, true);
        $linhasDescricao = $this->quebrar($d['desc'], self::LARGURA_TEXTO_CHEIO, 7);
        foreach ($linhasDescricao as $i => $linha) {
            $textos[] = $this->texto(self::X[0], 321.8 + $i * self::ESPACO_LINHA, $linha, 7);
        }
        $dy = (count($linhasDescricao) - 1) * self::ESPACO_LINHA; // a descrição empurra tudo que vem depois

        // ---- Tributação municipal (ISSQN)
        $filetes[] = $this->filete(327.77 + $dy);
        $retangulos[] = ['x' => 8.5, 'y' => 328.02 + $dy, 'w' => 144.57, 'h' => 19.08];
        $textos[] = $this->texto(self::X[0], 334.6 + $dy, 'TRIBUTAÇÃO MUNICIPAL (ISSQN)', 7, true);
        $this->campo($textos, self::X[1], 333.7 + $dy, 341.4 + $dy, 'Tipo de Tributação do ISSQN', $d['iss_tipo']);
        $this->campo($textos, self::X[2], 333.7 + $dy, 341.4 + $dy, 'Município / Sigla UF / País de Incidência do ISSQN', $d['iss_inc']);
        $this->campo($textos, self::X[0], 352.7 + $dy, 360.4 + $dy, 'BC ISSQN', $d['iss_bc']);
        $this->campo($textos, self::X[1], 352.7 + $dy, 360.4 + $dy, 'Alíquota Aplicada', $d['iss_aliq']);
        $this->campo($textos, self::X[2], 352.7 + $dy, 360.4 + $dy, 'Retenção do ISSQN', $d['iss_ret']);
        $this->campo($textos, self::X[3], 352.7 + $dy, 360.4 + $dy, 'ISSQN Apurado', $d['iss_val']);

        // ---- Tributação federal
        $filetes[] = $this->filete(366.42 + $dy);
        $retangulos[] = ['x' => 8.5, 'y' => 366.67 + $dy, 'w' => 144.57, 'h' => 19.07];
        $textos[] = $this->texto(self::X[0], 373.2 + $dy, 'TRIBUTAÇÃO FEDERAL (EXCETO CBS)', 7, true);
        $this->campo($textos, self::X[1], 372.3 + $dy, 380.0 + $dy, 'IRRF', '-');
        $this->campo($textos, self::X[2], 372.3 + $dy, 380.0 + $dy, 'Contribuição Previdenciária - Retida', '-');
        $this->campo($textos, self::X[3], 372.3 + $dy, 380.0 + $dy, 'Contribuições Sociais - Retidas', '-');
        $this->campo($textos, self::X[0], 391.4 + $dy, 399.1 + $dy, 'PIS - Débito Apuração Própria', '-');
        $this->campo($textos, self::X[1], 391.4 + $dy, 399.1 + $dy, 'COFINS - Débito Apuração Própria', '-');
        $this->campo($textos, self::X[2], 391.4 + $dy, 399.1 + $dy, 'Descrição Contrib. Sociais - Retidas', '-');

        // ---- Tributação IBS/CBS (sem o grupo na DPS: tudo "-", exceto o R$ 0,00 fixo do original)
        $filetes[] = $this->filete(405.07 + $dy);
        $retangulos[] = ['x' => 8.5, 'y' => 405.32 + $dy, 'w' => 144.57, 'h' => 19.07];
        $textos[] = $this->texto(self::X[0], 411.9 + $dy, 'TRIBUTAÇÃO IBS/CBS', 7, true);
        $this->campo($textos, self::X[1], 410.9 + $dy, 418.7 + $dy, 'CST / cClassTrib', '- / -');
        $this->campo($textos, self::X[2], 410.9 + $dy, 418.7 + $dy, 'Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', '- / - / - / -');
        $this->campo($textos, self::X[0], 430.0 + $dy, 437.7 + $dy, 'Exclusões e Reduções da Base de Cálculo', 'R$ 0,00');
        $this->campo($textos, self::X[1], 430.0 + $dy, 437.7 + $dy, 'Base de Cálculo Após Exclusões e Reduções', '-');
        $this->campo($textos, self::X[2], 430.0 + $dy, 437.7 + $dy, 'Red. Alíquota IBS / Red. Alíquota CBS', '- / - / -');
        $this->campo($textos, self::X[3], 430.0 + $dy, 437.7 + $dy, 'Alíquota - IBS UF / IBS Mun', '- / -');
        $this->campo($textos, self::X[0], 449.1 + $dy, 456.8 + $dy, 'Alíq. Efetiva Municipal - IBS', '-');
        $this->campo($textos, self::X[1], 449.1 + $dy, 456.8 + $dy, 'Valor Apurado Municipal - IBS', '-');
        $this->campo($textos, self::X[2], 449.1 + $dy, 456.8 + $dy, 'Alíq. Efetiva Estadual - IBS', '-');
        $this->campo($textos, self::X[3], 449.1 + $dy, 456.8 + $dy, 'Valor Apurado Estadual - IBS', '-');
        $this->campo($textos, self::X[0], 468.2 + $dy, 475.9 + $dy, 'Valor Total Apurado - IBS', '-');
        $this->campo($textos, self::X[1], 468.2 + $dy, 475.9 + $dy, 'Alíquota - CBS', '-');
        $this->campo($textos, self::X[2], 468.2 + $dy, 475.9 + $dy, 'Alíquota Efetiva - CBS', '-');
        $this->campo($textos, self::X[3], 468.2 + $dy, 475.9 + $dy, 'Valor Total Apurado - CBS', '-');

        // ---- Valor total
        $filetes[] = $this->filete(481.87 + $dy);
        $retangulos[] = ['x' => 8.5, 'y' => 482.12 + $dy, 'w' => 144.57, 'h' => 19.07];
        $retangulos[] = ['x' => 442.2, 'y' => 501.19 + $dy, 'w' => 144.57, 'h' => 19.07];
        $textos[] = $this->texto(self::X[0], 488.7 + $dy, 'VALOR TOTAL DA NFS-e', 7, true);
        $this->campo($textos, self::X[1], 487.7 + $dy, 495.5 + $dy, 'VALOR DA OPERAÇÃO / SERVIÇO', $d['v_servico']);
        $this->campo($textos, self::X[2], 487.7 + $dy, 495.5 + $dy, 'Desconto Incondicionado', $d['desc_incond']);
        $this->campo($textos, self::X[3], 487.7 + $dy, 495.5 + $dy, 'Desconto Condicionado', $d['desc_cond']);
        $this->campo($textos, self::X[0], 506.8 + $dy, 514.5 + $dy, 'Total das Retenções (ISSQN / Federais)', $d['tot_ret']);
        $this->campo($textos, self::X[1], 506.8 + $dy, 514.5 + $dy, 'VALOR LÍQUIDO DA NFS-e', $d['v_liquido']);
        $this->campo($textos, self::X[2], 506.8 + $dy, 514.5 + $dy, 'Total do IBS/CBS', 'R$ 0,00');
        $this->campo($textos, self::X[3], 506.8 + $dy, 514.5 + $dy, 'VALOR LÍQUIDO DA NFS-e + IBS/CBS', 'R$ 0,00');

        // ---- Informações complementares
        $filetes[] = $this->filete(520.51 + $dy);
        $textos[] = $this->texto(self::X[0], 527.3 + $dy, 'INFORMAÇÕES COMPLEMENTARES', 7, true);
        $y = 539.5 + $dy;
        $blocos = [];
        if ($d['inf_compl'] !== '') {
            $blocos[] = 'Inf. Cont.: ' . $d['inf_compl'];
        }
        $blocos[] = 'Totais aproximados dos Tributos cfe. Lei n° 12.741/2012: Federais: -; Estaduais: -; Municipais: -;';
        foreach ($blocos as $bloco) {
            foreach ($this->quebrar($bloco, self::LARGURA_TEXTO_CHEIO, 7) as $linha) {
                $textos[] = $this->texto(self::X[0], $y, $linha, 7);
                $y += self::ESPACO_LINHA;
            }
        }

        // ---- Rodapé (caixa fixa no pé da página)
        $textos[] = $this->texto(12.9, 801.9, 'DATA CIENTIFICAÇÃO:', 6, true);
        $textos[] = $this->texto(157.5, 801.9, 'IDENTIFICAÇÃO E ASSINATURA', 6, true);
        $textos[] = $this->texto(302.0, 801.9, 'N° NFS-e / CHAVE NFS-e', 6, true);
        $textos[] = $this->texto(302.0, 809.7, $d['numero'] . ' / ' . $d['chave'], 7);

        return [
            'titulo'     => 'DANFSe NFS-e ' . $d['numero'],
            'textos'     => $textos,
            'retangulos' => $retangulos,
            'filetes'    => $filetes,
            'borda'      => ['x' => 4.5, 'y' => 4.5, 'w' => 584.0, 'h' => 831.0],
            'rodape'     => ['x' => 8.5, 'y' => 795.3, 'w' => 577.3, 'h' => 19.1, 'sep1' => 153.6, 'sep2' => 298.1],
            'imagens'    => ['logo' => $logoDataUri, 'qr' => $qrCodeDataUri],
        ];
    }

    // ------------------------------------------------------------------ dados

    /** @return array<string, string> tudo já formatado, "-" onde não há valor */
    private function extrair(NotaFiscal $nota, array $empresa): array
    {
        $x = $this->carregarXmlNacional($nota);

        return $x !== null ? $this->dadosDoXml($nota, $x) : $this->dadosDoBanco($nota, $empresa);
    }

    private function carregarXmlNacional(NotaFiscal $nota): ?\SimpleXMLElement
    {
        if (empty($nota->xml_retorno)) {
            return null;
        }
        $x = @simplexml_load_string((string) $nota->xml_retorno);
        if ($x === false || $x->getName() !== 'NFSe' || !isset($x->infNFSe)) {
            return null;
        }

        return $x;
    }

    /** @return array<string, string> */
    private function dadosDoXml(NotaFiscal $nota, \SimpleXMLElement $x): array
    {
        $inf  = $x->infNFSe;
        $dps  = $inf->DPS->infDPS;
        $emit = $inf->emit;
        $prest = $dps->prest;
        $toma  = $dps->toma;
        $serv  = $dps->serv;
        $trib  = $dps->valores->trib;
        $s = static fn (mixed $v): string => trim((string) $v);

        $chave = (string) preg_replace('/^NFS/', '', $s($inf['Id']));
        $cMunEmit = $s($emit->enderNac->cMun);
        $ufEmit   = $s($emit->enderNac->UF);
        $tpEmit   = $s($dps->tpEmit);

        $endereco = implode(', ', array_filter([$s($emit->enderNac->xLgr), $s($emit->enderNac->nro), $s($emit->enderNac->xCpl), $s($emit->enderNac->xBairro)], static fn (string $p): bool => $p !== ''));

        $opSn = $s($prest->regTrib->opSimpNac);
        $regAp = $s($prest->regTrib->regApTribSN);

        $docToma = $s($toma->CNPJ) !== '' ? $s($toma->CNPJ) : $s($toma->CPF);

        $cLocPrest = $s($serv->locPrest->cLocPrestacao);
        $ufPrest   = $cLocPrest !== '' ? (self::UF_POR_IBGE[substr($cLocPrest, 0, 2)] ?? '-') : '-';
        $municipioPrest = $cLocPrest === $cMunEmit ? $s($inf->xLocPrestacao) : $s($inf->xLocPrestacao);

        $cTribNac = $s($serv->cServ->cTribNac);
        $cTribMun = $s($serv->cServ->cTribMun);

        $vServ = $s($dps->valores->vServPrest->vServ);
        $vLiq  = $s($inf->valores->vLiq);
        $incidencia = $s($inf->xLocIncid) !== ''
            ? $s($inf->xLocIncid) . ' / ' . (self::UF_POR_IBGE[substr($s($inf->cLocIncid), 0, 2)] ?? '-') . ' / -'
            : '-';

        $tribIss = $s($trib->tribMun->tribISSQN);
        $retIss  = $s($trib->tribMun->tpRetISSQN);
        $pAliq   = $s($trib->tribMun->pAliq);

        $situacao = $s($inf->cStat) === '100' ? 'NFS-e Gerada' : 'NFS-e';
        if ($nota->status === 'CANCELADA') {
            $situacao = 'NFS-e Cancelada';
        }

        $infComp = $s($serv->infoCompl->xInfComp);

        return [
            'municipio_uf' => $s($inf->xLocEmi) . ' - ' . $ufEmit,
            'amb_ger'      => $this->ou($s($inf->ambGer)),
            'tp_amb'       => $this->ou($s($dps->tpAmb)),
            'chave'        => $chave,
            'numero'       => $s($inf->nNFSe),
            'competencia'  => $this->data($s($dps->dCompet)),
            'dh_emissao'   => $this->dataHora($s($inf->dhProc)),
            'n_dps'        => $s($dps->nDPS),
            'serie_dps'    => $s($dps->serie),
            'dh_dps'       => $this->dataHora($s($dps->dhEmi)),
            'emitente'     => match ($tpEmit) { '1' => 'Prestador', '2' => 'Tomador', '3' => 'Intermediário', default => '-' },
            'situacao'     => $situacao,
            'finalidade'   => '-',
            'prest_doc'    => $this->documento($s($emit->CNPJ) !== '' ? $s($emit->CNPJ) : $s($emit->CPF)),
            'prest_im'     => $this->ou($s($prest->IM)),
            'prest_fone'   => $this->ou($s($prest->fone)),
            'prest_nome'   => $this->ou($s($emit->xNome)),
            'prest_mun_uf' => $s($inf->xLocEmi) !== '' ? $s($inf->xLocEmi) . ' / ' . $ufEmit : '-',
            'prest_ibge_cep' => $this->ibge($cMunEmit) . ' / ' . $this->cep($s($emit->enderNac->CEP)),
            'prest_end'    => $this->ou($endereco),
            'prest_email'  => $this->ou($s($prest->email)),
            'prest_sn'     => match ($opSn) {
                '1' => 'Não Optante',
                '2' => 'Optante - Microempreendedor Individual (MEI)',
                '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
                default => '-',
            },
            'prest_regap'  => match ($regAp) {
                '1' => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional',
                '2' => 'Regime de apuração dos tributos federais pelo Simples Nacional e municipal pelo regime normal (ISSQN)',
                '3' => 'Regime de apuração dos tributos pelo Simples Nacional (MEI)',
                default => '-',
            },
            'toma_doc'     => $this->documento($docToma),
            'toma_im'      => $this->ou($s($toma->IM)),
            'toma_fone'    => $this->ou($s($toma->fone)),
            'toma_nome'    => $this->ou($s($toma->xNome)),
            'toma_mun_uf'  => '-',
            'toma_ibge_cep' => '-',
            'toma_end'     => '-',
            'toma_email'   => $this->ou($s($toma->email)),
            'cod_trib'     => $this->codigoTributacao($cTribNac) . ' / ' . ($cTribMun !== '' ? $cTribMun : '-'),
            'nbs'          => $this->ou($s($serv->cServ->cNBS)),
            'local_prest'  => $s($inf->xLocPrestacao) !== '' ? $municipioPrest . ' / ' . $ufPrest . ' / -' : '-',
            'xtribnac'     => $this->ou($s($inf->xTribNac)),
            'desc'         => $this->ou($s($serv->cServ->xDescServ)),
            'iss_tipo'     => match ($tribIss) { '1' => 'Operação Tributável', '2' => 'Imunidade', '3' => 'Exportação de Serviço', '4' => 'Não Incidência', default => '-' },
            'iss_inc'      => $incidencia,
            'iss_bc'       => '-',
            'iss_aliq'     => $pAliq !== '' ? number_format((float) $pAliq, 2, ',', '.') . '%' : '-',
            'iss_ret'      => match ($retIss) { '1' => 'Não Retido', '2' => 'Retido pelo Tomador', '3' => 'Retido pelo Intermediário', default => '-' },
            'iss_val'      => '-',
            'v_servico'    => $this->moeda($vServ),
            'desc_incond'  => '-',
            'desc_cond'    => '-',
            'tot_ret'      => '-',
            'v_liquido'    => $this->moeda($vLiq !== '' ? $vLiq : $vServ),
            'inf_compl'    => $infComp,
        ];
    }

    /**
     * NFS-e de outros provedores (XML municipal / sem XML): monta o que o banco
     * sabe. Campos que o documento nacional trás do governo e aqui não existem
     * (competência exata, IM, regime detalhado) saem "-".
     *
     * @param array<string, mixed> $empresa
     * @return array<string, string>
     */
    private function dadosDoBanco(NotaFiscal $nota, array $empresa): array
    {
        $cli = $nota->cliente;
        $cidade = (string) ($empresa['cidade'] ?? '');
        $uf     = (string) ($empresa['uf'] ?? '');
        $ibge   = (string) ($empresa['codigo_ibge'] ?? '');
        $valor  = (float) ($nota->valor_total ?? 0);
        $emitidoEm = $nota->emitido_em ?? $nota->criado_em ?? null;
        if (is_string($emitidoEm)) {
            $emitidoEm = \Illuminate\Support\Carbon::parse($emitidoEm);
        }
        $regime = strtolower((string) ($empresa['regime_tributario'] ?? ''));
        $simples = str_contains($regime, 'simples') || str_contains($regime, 'mei');
        $endereco = implode(', ', array_filter([(string) ($empresa['logradouro'] ?? $empresa['endereco'] ?? ''), (string) ($empresa['numero'] ?? ''), (string) ($empresa['bairro'] ?? '')], static fn (string $p): bool => $p !== ''));

        return [
            'municipio_uf' => $cidade !== '' ? $cidade . ' - ' . $uf : '-',
            'amb_ger'      => '-',
            'tp_amb'       => ($nota->ambiente ?? 'PRODUCAO') === 'PRODUCAO' ? '1' : '2',
            'chave'        => (string) preg_replace('/^NFS/', '', (string) ($nota->chave_acesso ?? '')),
            'numero'       => (string) ($nota->numero ?? '-'),
            'competencia'  => $emitidoEm ? $emitidoEm->format('d/m/Y') : '-',
            'dh_emissao'   => $emitidoEm ? $emitidoEm->format('d/m/Y H:i:s') : '-',
            'n_dps'        => '-',
            'serie_dps'    => '-',
            'dh_dps'       => '-',
            'emitente'     => 'Prestador',
            'situacao'     => $nota->status === 'CANCELADA' ? 'NFS-e Cancelada' : 'NFS-e Gerada',
            'finalidade'   => '-',
            'prest_doc'    => $this->documento((string) ($empresa['cnpj'] ?? '')),
            'prest_im'     => $this->ou((string) ($empresa['inscricao_municipal'] ?? '')),
            'prest_fone'   => $this->ou((string) ($empresa['telefone'] ?? '')),
            'prest_nome'   => $this->ou((string) ($empresa['razao_social'] ?? '')),
            'prest_mun_uf' => $cidade !== '' ? $cidade . ' / ' . $uf : '-',
            'prest_ibge_cep' => $this->ibge($ibge) . ' / ' . $this->cep((string) ($empresa['cep'] ?? '')),
            'prest_end'    => $this->ou($endereco),
            'prest_email'  => $this->ou((string) ($empresa['email'] ?? '')),
            'prest_sn'     => $simples ? 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)' : '-',
            'prest_regap'  => $simples ? 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional' : '-',
            'toma_doc'     => $this->documento((string) ($cli?->cpf_cnpj ?? '')),
            'toma_im'      => '-',
            'toma_fone'    => $this->ou((string) ($cli?->telefone ?? '')),
            'toma_nome'    => $this->ou((string) ($cli?->nome ?? '')),
            'toma_mun_uf'  => trim((string) ($cli?->cidade ?? '')) !== '' ? $cli->cidade . ' / ' . $cli->uf : '-',
            'toma_ibge_cep' => $this->ibge((string) ($cli?->codigo_ibge ?? '')) . ' / ' . $this->cep((string) ($cli?->cep ?? '')),
            'toma_end'     => $this->ou(implode(', ', array_filter([(string) ($cli?->endereco ?? ''), (string) ($cli?->bairro ?? '')], static fn (string $p): bool => $p !== ''))),
            'toma_email'   => $this->ou((string) ($cli?->email ?? '')),
            'cod_trib'     => '14.01.01 / -',
            'nbs'          => '-',
            'local_prest'  => $cidade !== '' ? $cidade . ' / ' . $uf . ' / -' : '-',
            'xtribnac'     => 'Lubrificação, limpeza, lustração, revisão, carga e recarga, conserto, restauração, blindagem, manutenção e conservação de máquinas, veículos, aparelhos, equipamentos, motores, elevadores ou de qualquer objeto (exceto peças e partes empregadas, que ficam sujeitas ao ICMS).',
            'desc'         => $this->ou((string) ($nota->observacoes ?? '')),
            'iss_tipo'     => 'Operação Tributável',
            'iss_inc'      => $cidade !== '' ? $cidade . ' / ' . $uf . ' / -' : '-',
            'iss_bc'       => '-',
            'iss_aliq'     => $nota->aliquota_iss !== null ? number_format((float) $nota->aliquota_iss, 2, ',', '.') . '%' : '-',
            'iss_ret'      => 'Não Retido',
            'iss_val'      => $nota->valor_iss !== null ? $this->moeda((string) $nota->valor_iss) : '-',
            'v_servico'    => $this->moeda((string) $valor),
            'desc_incond'  => (float) ($nota->desconto ?? 0) > 0 ? $this->moeda((string) $nota->desconto) : '-',
            'desc_cond'    => '-',
            'tot_ret'      => '-',
            'v_liquido'    => $this->moeda((string) $valor),
            'inf_compl'    => (string) ($nota->informacoes_complementares_xml ?? ''),
        ];
    }

    // ------------------------------------------------------------- formatação

    private function ou(string $v): string
    {
        return $v === '' ? '-' : $v;
    }

    private function documento(string $v): string
    {
        $n = (string) preg_replace('/\D/', '', $v);

        return match (strlen($n)) {
            14 => preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $n),
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $n),
            default => $this->ou($v),
        };
    }

    private function cep(string $v): string
    {
        $n = (string) preg_replace('/\D/', '', $v);

        return strlen($n) === 8 ? (string) preg_replace('/^(\d{2})(\d{3})(\d{3})$/', '$1.$2-$3', $n) : '-';
    }

    private function ibge(string $v): string
    {
        $n = (string) preg_replace('/\D/', '', $v);

        return strlen($n) === 7 ? substr($n, 0, 2) . '.' . substr($n, 2) : '-';
    }

    private function codigoTributacao(string $c): string
    {
        return strlen($c) === 6 ? substr($c, 0, 2) . '.' . substr($c, 2, 2) . '.' . substr($c, 4, 2) : $this->ou($c);
    }

    private function moeda(string $v): string
    {
        return $v === '' ? '-' : 'R$ ' . number_format((float) $v, 2, ',', '.');
    }

    private function data(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) === 1 ? "{$m[3]}/{$m[2]}/{$m[1]}" : '-';
    }

    /** '2026-09-25T15:51:40-03:00' → '25/09/2026 15:51:40' (mantém a hora local do documento) */
    private function dataHora(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}:\d{2})/', $iso, $m) === 1 ? "{$m[3]}/{$m[2]}/{$m[1]} {$m[4]}" : '-';
    }

    // ---------------------------------------------------------------- desenho

    /** @param list<array<string,mixed>> $textos */
    private function campo(array &$textos, float $x, float $yRotulo, float $yValor, string $rotulo, string $valor, int $tamanhoRotulo = 6): void
    {
        $textos[] = $this->texto($x, $yRotulo, $rotulo, $tamanhoRotulo, true);
        $textos[] = $this->texto($x, $yValor, $valor, 7);
    }

    /** @return array<string,mixed> */
    private function texto(float $x, float $linhaBase, string $t, int $tamanho, bool $negrito = false, ?float $largura = null, string $alinhar = 'left'): array
    {
        return ['x' => $x, 'y' => $linhaBase, 't' => $t, 'size' => $tamanho, 'bold' => $negrito, 'w' => $largura, 'align' => $alinhar];
    }

    /** @return array<string,float> */
    private function filete(float $y): array
    {
        return ['x' => 8.5, 'y' => round($y, 2), 'w' => self::LARGURA_LINHA];
    }

    private function largura(string $texto, int $tamanho, bool $negrito = false): float
    {
        if ($this->metricas === null) {
            $this->metricas = (new Dompdf())->getFontMetrics();
        }

        return (float) $this->metricas->getTextWidth($texto, $negrito ? 'Helvetica-Bold' : 'Helvetica', $tamanho);
    }

    /** Corta por palavras até caber com " ..." (mesmo comportamento do original). */
    private function truncar(string $texto, float $larguraMax, int $tamanho = 7): string
    {
        if ($this->largura($texto, $tamanho) <= $larguraMax) {
            return $texto;
        }
        $palavras = explode(' ', $texto);
        while (count($palavras) > 1) {
            array_pop($palavras);
            $candidato = implode(' ', $palavras) . ' ...';
            if ($this->largura($candidato, $tamanho) <= $larguraMax) {
                return $candidato;
            }
        }

        return $texto;
    }

    /** @return list<string> */
    private function quebrar(string $texto, float $larguraMax, int $tamanho): array
    {
        $linhas = [];
        foreach (preg_split('/\R/', $texto) ?: [$texto] as $paragrafo) {
            $atual = '';
            foreach (explode(' ', $paragrafo) as $palavra) {
                $tentativa = $atual === '' ? $palavra : $atual . ' ' . $palavra;
                if ($atual !== '' && $this->largura($tentativa, $tamanho) > $larguraMax) {
                    $linhas[] = $atual;
                    $atual = $palavra;
                } else {
                    $atual = $tentativa;
                }
            }
            $linhas[] = $atual;
        }

        return $linhas === [] ? [''] : $linhas;
    }
}
