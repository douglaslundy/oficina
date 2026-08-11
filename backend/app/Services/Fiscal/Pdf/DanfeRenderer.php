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
        // simplexml_load_string() retorna `false` (não `null`) quando o XML
        // é inválido/malformado — o operador nullsafe (?->) só faz
        // short-circuit em `null`, então chamar ->registerXPathNamespace()
        // direto num `false` lançaria um \Error fatal ("Call to a member
        // function ... on bool"), derrubando a request inteira em vez de
        // cair no fallback pra notas_fiscais_itens que este método promete.
        // Checagem explícita `=== false` ANTES de qualquer chamada de
        // método — mesmo padrão já usado (e corrigido pelo mesmo motivo) em
        // MotorNfe::processarRespostaAutorizacao()/extrairCStatEvento().
        $sxml = null;
        if (!empty($nota->xml_retorno)) {
            $sxmlCarregado = @simplexml_load_string($nota->xml_retorno);
            if ($sxmlCarregado !== false) {
                $sxml = $sxmlCarregado;
                $sxml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
            }
        }

        $itens = [];
        if ($sxml !== null) {
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
