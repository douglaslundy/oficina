<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\Produto;
use App\Support\BuscaTexto;

/**
 * Monta as linhas da exportação "produtos + dados fiscais" e as serializa em
 * JSON/XML (XLSX e PDF são escritos por App\Exports\ProdutosFiscaisExport e
 * pela view pdf.produtos_fiscais, a partir das mesmas linhas).
 */
class ExportacaoProdutosFiscal
{
    /** chave da linha => cabeçalho exibido (XLSX/PDF). A ordem é a das colunas. */
    public const COLUNAS = [
        'nome'            => 'Produto',
        'sku'             => 'SKU',
        'codigo_barras'   => 'Código de barras',
        'categoria'       => 'Categoria',
        'ncm'             => 'NCM',
        'cest'            => 'CEST',
        'origem'          => 'Origem',
        'tributacao_icms' => 'Tributação ICMS',
        'fiscal_fonte'    => 'Fonte fiscal',
        'revisado_em'     => 'Revisado em',
        'situacao_fiscal' => 'Situação fiscal',
    ];

    /**
     * Uma linha por produto. Quem não tem NENHUM campo fiscal preenchido
     * (NCM, CEST, origem e tributação) vai por último; dentro de cada grupo
     * a ordem é por nome, ignorando acento e caixa.
     *
     * @param iterable<Produto> $produtos
     * @param list<string> $idsComDivergencia ids de produtos com divergência aberta contra o XML do fornecedor
     * @return list<array<string, mixed>>
     */
    public function linhas(iterable $produtos, array $idsComDivergencia = []): array
    {
        $itens = [];
        foreach ($produtos as $produto) {
            $itens[] = [
                'grupo' => $this->semNenhumCampoFiscal($produto) ? 1 : 0,
                'chave' => BuscaTexto::normalizar((string) $produto->nome),
                'linha' => $this->linha($produto, in_array($produto->id, $idsComDivergencia, true)),
            ];
        }

        usort($itens, fn (array $a, array $b) => $a['grupo'] <=> $b['grupo'] ?: strnatcmp($a['chave'], $b['chave']));

        return array_column($itens, 'linha');
    }

    public function situacao(Produto $produto, bool $comDivergencia): string
    {
        if ($this->vazio($produto->ncm)) {
            return 'Sem NCM';
        }
        // ST sem CEST bloqueia a emissão de NF-e (SEFAZ rejeita com cStat=806).
        if ($produto->tributacao_icms === 'ST' && $this->vazio($produto->cest)) {
            return 'ICMS-ST sem CEST';
        }
        if ($produto->fiscal_fonte === 'PADRAO') {
            return 'Padrão da categoria';
        }
        if ($comDivergencia) {
            return 'Divergência com fornecedor';
        }

        return 'Completo';
    }

    /**
     * Comparação estrita de propósito: origem 0 (mercadoria nacional) é um
     * valor fiscal válido e NÃO pode ser confundido com ausente.
     */
    public function semNenhumCampoFiscal(Produto $produto): bool
    {
        return $this->vazio($produto->ncm)
            && $this->vazio($produto->cest)
            && $produto->origem === null
            && $this->vazio($produto->tributacao_icms);
    }

    /** @param list<array<string, mixed>> $linhas */
    public function paraJson(array $linhas, string $geradoEm): string
    {
        return json_encode(
            ['gerado_em' => $geradoEm, 'total' => count($linhas), 'produtos' => $linhas],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<array<string, mixed>> $linhas */
    public function paraXml(array $linhas, string $geradoEm): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $raiz = $doc->createElement('produtos');
        $raiz->setAttribute('gerado_em', $geradoEm);
        $raiz->setAttribute('total', (string) count($linhas));
        $doc->appendChild($raiz);

        foreach ($linhas as $linha) {
            $no = $doc->createElement('produto');
            foreach (array_keys(self::COLUNAS) as $chave) {
                $valor = $linha[$chave] ?? null;
                // createTextNode escapa &, < e > — createElement(nome, valor) não escaparia o &.
                $filho = $doc->createElement($chave);
                if ($valor !== null) {
                    $filho->appendChild($doc->createTextNode((string) $valor));
                }
                $no->appendChild($filho);
            }
            $raiz->appendChild($no);
        }

        return (string) $doc->saveXML();
    }

    /** @return array<string, mixed> */
    private function linha(Produto $produto, bool $comDivergencia): array
    {
        return [
            'nome'            => $produto->nome,
            'sku'             => $produto->sku,
            'codigo_barras'   => $produto->codigo_barras,
            'categoria'       => $produto->categoria,
            'ncm'             => $this->vazio($produto->ncm) ? null : $produto->ncm,
            'cest'            => $this->vazio($produto->cest) ? null : $produto->cest,
            'origem'          => $produto->origem,
            'tributacao_icms' => $this->vazio($produto->tributacao_icms) ? null : $produto->tributacao_icms,
            'fiscal_fonte'    => $this->vazio($produto->fiscal_fonte) ? null : $produto->fiscal_fonte,
            'revisado_em'     => $produto->fiscal_revisado_em?->format('d/m/Y H:i'),
            'situacao_fiscal' => $this->situacao($produto, $comDivergencia),
        ];
    }

    private function vazio(mixed $valor): bool
    {
        return $valor === null || $valor === '';
    }
}
