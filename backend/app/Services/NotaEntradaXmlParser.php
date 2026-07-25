<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Fiscal\ClassificacaoIcms;
use App\Services\Fiscal\ValidadorCamposFiscais;

class NotaEntradaXmlParser
{
    /**
     * @return array{
     *   chave_acesso: ?string, numero_nf: ?string, serie: ?string, data_emissao: ?string,
     *   fornecedor_nome: ?string, fornecedor_cnpj: ?string, valor_total: float,
     *   itens: list<array{codigo_barras: ?string, descricao: string, quantidade: float,
     *                     valor_unitario: float, ncm: ?string, cfop: ?string, cest: ?string,
     *                     unidade: ?string, origem: ?int, cst_csosn: ?string,
     *                     tributacao_icms: ?string}>
     * }
     */
    public function parse(string $xmlContent): array
    {
        $semNamespace = preg_replace('/xmlns="[^"]*"/', '', $xmlContent);

        libxml_use_internal_errors(true);
        $sxml = simplexml_load_string((string) $semNamespace);
        libxml_clear_errors();

        if ($sxml === false) {
            throw new \InvalidArgumentException('Arquivo XML inválido ou corrompido.');
        }

        $infNFe = null;
        if (isset($sxml->infNFe)) {
            $infNFe = $sxml->infNFe;
        } elseif (isset($sxml->NFe->infNFe)) {
            $infNFe = $sxml->NFe->infNFe;
        }

        if ($infNFe === null) {
            throw new \InvalidArgumentException('XML não é uma NF-e válida (modelo 55): nó infNFe não encontrado.');
        }

        $chaveBruta = (string) ($infNFe['Id'] ?? '');
        $chave      = str_starts_with($chaveBruta, 'NFe') ? substr($chaveBruta, 3) : ($chaveBruta ?: null);

        $itens = [];
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
                'cfop'            => ValidadorCamposFiscais::cfop(isset($prod->CFOP) ? (string) $prod->CFOP : null),
                'cest'            => ValidadorCamposFiscais::cest(isset($prod->CEST) ? (string) $prod->CEST : null),
                'unidade'         => ((string) ($prod->uCom ?? '')) ?: null,
                'origem'          => ValidadorCamposFiscais::origem($icms['orig']),
                'cst_csosn'       => $icms['cst'] ?? $icms['csosn'],
                'tributacao_icms' => ClassificacaoIcms::derivar($icms['cst'], $icms['csosn']),
            ];
        }

        $dhEmi = (string) ($infNFe->ide->dhEmi ?? $infNFe->ide->dEmi ?? '');

        return [
            'chave_acesso'    => $chave,
            'numero_nf'       => ((string) ($infNFe->ide->nNF ?? '')) ?: null,
            'serie'           => ((string) ($infNFe->ide->serie ?? '')) ?: null,
            'data_emissao'    => $dhEmi !== '' ? substr($dhEmi, 0, 10) : null,
            'fornecedor_nome' => ((string) ($infNFe->emit->xNome ?? '')) ?: null,
            'fornecedor_cnpj' => ((string) ($infNFe->emit->CNPJ ?? '')) ?: null,
            'valor_total'     => (float) ($infNFe->total->ICMSTot->vNF ?? 0),
            'itens'           => $itens,
        ];
    }

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

}
