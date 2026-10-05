<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Pdf;

use App\Models\NotaFiscal;
use App\Models\OrdemServico;

/**
 * Dados dos dois cupons impressos:
 *
 *  - DANFE NFC-e (documento auxiliar da NFC-e, modelo 65): blocos e textos do
 *    leiaute oficial do MOC/NT do DANFE NFC-e — emitente; título; itens
 *    (código, descrição, qtde, UN, vl unit, vl total); totais; forma de
 *    pagamento; tributos totais (Lei 12.741/2012); mensagem fiscal;
 *    consulta pela chave (URL + chave em grupos de 4); consumidor; número,
 *    série, emissão, via consumidor; protocolo e data de autorização;
 *    QR Code; informações adicionais. Lê o que foi AUTORIZADO do XML da nota
 *    e cai pro banco quando o motor (Spedy/Focus) não devolve o XML.
 *  - Cupom NÃO fiscal: dados da venda (OS), sem nenhum elemento de documento
 *    fiscal e com a frase "SEM VALOR FISCAL".
 */
class CupomRenderer
{
    private const FORMAS_PAGAMENTO = [
        '01' => 'Dinheiro', '02' => 'Cheque', '03' => 'Cartão de Crédito', '04' => 'Cartão de Débito',
        '05' => 'Crédito Loja', '10' => 'Vale Alimentação', '11' => 'Vale Refeição', '12' => 'Vale Presente',
        '13' => 'Vale Combustível', '15' => 'Boleto Bancário', '16' => 'Depósito Bancário', '17' => 'PIX',
        '18' => 'Transferência', '19' => 'Programa de Fidelidade', '90' => 'Sem pagamento', '99' => 'Outros',
    ];

    /**
     * @param array<string, mixed> $empresa
     * @return array<string, mixed>
     */
    public function dadosNfce(NotaFiscal $nota, array $empresa, ?string $qrCodeDataUri): array
    {
        $x = $this->lerXml((string) $nota->xml_retorno);

        $itens = [];
        if ($x !== null && isset($x->infNFe->det)) {
            foreach ($x->infNFe->det as $det) {
                $p = $det->prod;
                $itens[] = [
                    'codigo'    => (string) $p->cProd,
                    'descricao' => (string) $p->xProd,
                    'qtd'       => (float) $p->qCom,
                    'un'        => (string) $p->uCom,
                    'vl_unit'   => (float) $p->vUnCom,
                    'vl_total'  => (float) $p->vProd,
                ];
            }
        }
        if ($itens === []) {
            foreach ($nota->itens as $i) {
                $itens[] = [
                    'codigo'    => (string) ($i->sku ?: ''),
                    'descricao' => (string) $i->descricao,
                    'qtd'       => (float) $i->quantidade,
                    'un'        => (string) ($i->unidade ?: 'UN'),
                    'vl_unit'   => (float) $i->valor_unitario,
                    'vl_total'  => round((float) $i->quantidade * (float) $i->valor_unitario, 2),
                ];
            }
        }

        $total    = $x !== null && isset($x->infNFe->total->ICMSTot->vNF) ? (float) $x->infNFe->total->ICMSTot->vNF : (float) $nota->valor_total;
        $desconto = $x !== null && isset($x->infNFe->total->ICMSTot->vDesc) ? (float) $x->infNFe->total->ICMSTot->vDesc : (float) ($nota->desconto ?? 0);
        $soma     = round(array_sum(array_column($itens, 'vl_total')), 2);

        $pagamentos = [];
        if ($x !== null && isset($x->infNFe->pag->detPag)) {
            foreach ($x->infNFe->pag->detPag as $d) {
                $cod = (string) $d->tPag;
                $pagamentos[] = [
                    'forma' => $cod === '99' && (string) $d->xPag !== '' ? (string) $d->xPag : (self::FORMAS_PAGAMENTO[$cod] ?? 'Outros'),
                    'valor' => (float) $d->vPag,
                ];
            }
        }
        if ($pagamentos === [] && $nota->forma_pagamento) {
            $pagamentos[] = ['forma' => (string) $nota->forma_pagamento, 'valor' => $total];
        }
        $troco = $x !== null && isset($x->infNFe->pag->vTroco) ? (float) $x->infNFe->pag->vTroco : 0.0;

        $tpAmb  = $x !== null ? (string) ($x->infNFe->ide->tpAmb ?? '') : '';
        $tpEmis = $x !== null ? (string) ($x->infNFe->ide->tpEmis ?? '') : '';
        $homologacao = $tpAmb === '2' || ($tpAmb === '' && $nota->ambiente === 'HOMOLOGACAO');
        $contingencia = $nota->status === 'CONTINGENCIA' || $tpEmis === '9';

        $mensagens = [];
        if ($nota->status === 'CANCELADA') {
            $mensagens[] = 'NFC-e CANCELADA';
        }
        if ($homologacao) {
            $mensagens[] = 'EMITIDA EM AMBIENTE DE HOMOLOGAÇÃO - SEM VALOR FISCAL';
        }
        if ($contingencia) {
            $mensagens[] = 'EMITIDA EM CONTINGÊNCIA';
            $mensagens[] = 'Pendente de autorização';
        }

        $doc = '';
        $nomeConsumidor = '';
        if ($x !== null && isset($x->infNFe->dest)) {
            $doc = (string) ($x->infNFe->dest->CPF ?? $x->infNFe->dest->CNPJ ?? $x->infNFe->dest->idEstrangeiro ?? '');
            $nomeConsumidor = (string) ($x->infNFe->dest->xNome ?? '');
        } elseif ($x === null && $nota->cliente) {
            $doc = (string) preg_replace('/\D/', '', (string) $nota->cliente->cpf_cnpj);
            $nomeConsumidor = (string) $nota->cliente->nome;
        }

        $chave = (string) preg_replace('/\D/', '', (string) $nota->chave_acesso);
        $dhEmi = $x !== null && isset($x->infNFe->ide->dhEmi) ? (string) $x->infNFe->ide->dhEmi : '';
        $emissao = $dhEmi !== '' ? $this->dataHora($dhEmi) : ($nota->emitido_em?->format('d/m/Y H:i:s') ?? '-');

        $dhRecbto = $x !== null && isset($x->protNFe->infProt->dhRecbto) ? (string) $x->protNFe->infProt->dhRecbto : '';
        $nProt = $x !== null && isset($x->protNFe->infProt->nProt) ? (string) $x->protNFe->infProt->nProt : '';
        $protocolo = $nProt !== '' ? $nProt : (string) $nota->protocolo;

        $infCpl = $nota->informacoes_complementares_xml;
        $vTotTrib = $x !== null && isset($x->infNFe->total->ICMSTot->vTotTrib) ? (float) $x->infNFe->total->ICMSTot->vTotTrib : null;
        if ($vTotTrib === null && isset($empresa['percentual_tributos_aproximados']) && $empresa['percentual_tributos_aproximados'] !== null) {
            // XML sem vTotTrib (Spedy/Focus, ou nota antiga): aplica o percentual informado em Empresa > Impressão.
            $vTotTrib = round($total * (float) $empresa['percentual_tributos_aproximados'] / 100, 2);
        }
        $urlChave = $x !== null && isset($x->infNFeSupl->urlChave) ? trim((string) $x->infNFeSupl->urlChave) : '';

        return [
            'emitente'          => $this->emitente($empresa),
            'itens'             => $itens,
            'qtd_itens'         => count($itens),
            'valor_total_itens' => $soma,
            'desconto'          => $desconto,
            'valor_a_pagar'     => $total,
            'pagamentos'        => $pagamentos,
            'troco'             => $troco,
            'tributos_totais'   => $vTotTrib,
            'mensagens_fiscais' => $mensagens,
            'numero'            => (string) ($nota->numero ?? '-'),
            'serie'             => (string) ($nota->serie ?? ''),
            'emissao'           => $emissao,
            'protocolo'         => $protocolo,
            'data_autorizacao'  => $dhRecbto !== '' ? $this->dataHora($dhRecbto) : ($nota->emitido_em?->format('d/m/Y H:i:s') ?? ''),
            'chave'             => $chave,
            'chave_formatada'   => $chave === '' ? '' : trim(chunk_split($chave, 4, ' ')),
            'url_consulta'      => $urlChave,
            'consumidor_doc'    => $this->formatarDocumento($doc),
            'consumidor_nome'   => $nomeConsumidor,
            'info_adicional'    => $infCpl,
            'qr_code'           => $qrCodeDataUri,
            'autorizada'        => $protocolo !== '' && ! $contingencia,
        ];
    }

    /**
     * @param array<string, mixed> $empresa
     * @return array<string, mixed>
     */
    public function dadosNaoFiscal(OrdemServico $os, array $empresa): array
    {
        $os->loadMissing(['cliente', 'itens', 'pagamentos']);

        $itens = $os->itens->where('aprovado', '!==', false)->map(fn ($i) => [
            'descricao' => (string) $i->descricao,
            'qtd'       => (float) $i->quantidade,
            'vl_unit'   => (float) $i->valor_unitario,
            'vl_total'  => round((float) $i->quantidade * (float) $i->valor_unitario, 2),
        ])->values()->all();

        $subtotal = round(array_sum(array_column($itens, 'vl_total')), 2);
        $desconto = (float) ($os->desconto ?? 0);
        $total    = (float) $os->valor_total;
        $pago     = (float) $os->valor_pago;

        $pagamentos = $os->pagamentos->map(fn ($p) => ['forma' => (string) $p->forma_pagamento, 'valor' => (float) $p->valor])->all();
        if ($pagamentos === [] && $pago > 0 && $os->forma_pagamento) {
            $pagamentos[] = ['forma' => (string) $os->forma_pagamento, 'valor' => $pago];
        }

        return [
            'emitente'   => $this->emitente($empresa),
            'os_numero'  => (string) $os->numero,
            'data'       => now()->format('d/m/Y H:i'),
            'cliente'    => (string) ($os->cliente?->nome ?? 'Consumidor'),
            'veiculo'    => trim((string) $os->veiculo_descricao . ($os->veiculo_placa ? ' - ' . $os->veiculo_placa : '')),
            'itens'      => $itens,
            'subtotal'   => $subtotal,
            'desconto'   => $desconto,
            'total'      => $total,
            'pagamentos' => $pagamentos,
            'valor_pago' => $pago,
            'saldo'      => max(0.0, round($total - $pago, 2)),
        ];
    }

    /**
     * @param array<string, mixed> $empresa
     * @return array<string, string>
     */
    private function emitente(array $empresa): array
    {
        $logradouro = trim((string) ($empresa['logradouro'] ?? $empresa['endereco'] ?? ''));
        $numero     = trim((string) ($empresa['numero'] ?? ''));
        $partes     = array_filter([
            trim($logradouro . ($numero !== '' ? ', ' . $numero : '')),
            trim((string) ($empresa['bairro'] ?? '')),
            trim((string) ($empresa['cidade'] ?? '') . (! empty($empresa['uf']) ? ' - ' . $empresa['uf'] : '')),
        ], fn ($p) => $p !== '');

        return [
            'razao_social' => (string) ($empresa['razao_social'] ?? ''),
            'nome'         => (string) (($empresa['nome_fantasia'] ?? '') ?: ($empresa['razao_social'] ?? '')),
            'cnpj'         => $this->formatarDocumento((string) ($empresa['cnpj'] ?? '')),
            'ie'           => (string) ($empresa['inscricao_estadual'] ?? ''),
            'endereco'     => implode(', ', $partes),
            'telefone'     => (string) ($empresa['telefone'] ?? ''),
        ];
    }

    private function lerXml(string $xml): ?\SimpleXMLElement
    {
        if (trim($xml) === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $s = simplexml_load_string((string) preg_replace('/xmlns="[^"]*"/', '', $xml));
        libxml_clear_errors();
        if ($s === false) {
            return null;
        }

        // procNFe: <nfeProc><NFe>...</NFe><protNFe/></nfeProc>; NFe solta: <NFe>...</NFe>.
        $nfe = isset($s->NFe) ? $s->NFe : $s;
        if (! isset($nfe->infNFe)) {
            return null;
        }

        // Junta num único objeto navegável: ->infNFe, ->infNFeSupl, ->protNFe.
        $raiz = new \SimpleXMLElement('<r/>');
        $this->anexar($raiz, $nfe->infNFe);
        if (isset($nfe->infNFeSupl)) {
            $this->anexar($raiz, $nfe->infNFeSupl);
        }
        if (isset($s->protNFe)) {
            $this->anexar($raiz, $s->protNFe);
        }

        return $raiz;
    }

    private function anexar(\SimpleXMLElement $destino, \SimpleXMLElement $origem): void
    {
        $a = dom_import_simplexml($destino);
        $b = dom_import_simplexml($origem);
        $a->appendChild($a->ownerDocument->importNode($b, true));
    }

    private function dataHora(string $iso): string
    {
        try {
            return (new \DateTimeImmutable($iso))->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return $iso;
        }
    }

    private function formatarDocumento(string $doc): string
    {
        $d = (string) preg_replace('/\D/', '', $doc);

        return match (strlen($d)) {
            11      => (string) preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d),
            14      => (string) preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d),
            default => $doc,
        };
    }
}
