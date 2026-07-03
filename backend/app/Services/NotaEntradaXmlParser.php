<?php
declare(strict_types=1);

namespace App\Services;

class NotaEntradaXmlParser
{
    /**
     * @return array{
     *   chave_acesso: ?string, numero_nf: ?string, serie: ?string, data_emissao: ?string,
     *   fornecedor_nome: ?string, fornecedor_cnpj: ?string, valor_total: float,
     *   itens: list<array{codigo_barras: ?string, descricao: string, quantidade: float, valor_unitario: float}>
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

            $itens[] = [
                'codigo_barras'  => $ean,
                'descricao'      => (string) ($prod->xProd ?? ''),
                'quantidade'     => (float) ($prod->qCom ?? 0),
                'valor_unitario' => (float) ($prod->vUnCom ?? 0),
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
}
