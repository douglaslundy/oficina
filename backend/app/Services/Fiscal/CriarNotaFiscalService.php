<?php
declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\EmissaoBloqueadaException;
use App\Models\Cliente;
use App\Models\Configuracao;
use App\Models\NotaFiscal;
use App\Models\NotaFiscalItem;
use App\Models\Produto;
use Illuminate\Support\Facades\DB;

/**
 * Cria uma NotaFiscal (RASCUNHO) a partir de dados já validados — com toda
 * a checagem fiscal (UF da empresa/cliente, regime, NCM/origem/tributação
 * de ICMS por item), a seleção automática NFC-e vs NF-e, a resolução de
 * CFOP/CST-CSOSN e a numeração de série.
 *
 * Extraído de NotaFiscalController::store() (2026-09-05) pra ser
 * reaproveitado pelo EmissaoOrquestradorService (OS mista → 2 notas).
 * Lógica idêntica à do controller; a única diferença é que os bloqueios
 * fiscais viram EmissaoBloqueadaException em vez de `response()->json(422)`.
 */
class CriarNotaFiscalService
{
    /**
     * @param array{
     *   cliente_id: string,
     *   os_id?: ?string,
     *   natureza_operacao: string,      // 'Prestação de Serviços' | 'Venda de Mercadoria'
     *   forma_pagamento?: ?string,
     *   subtotal?: ?float,              // obrigatório pra 'Prestação de Serviços'
     *   desconto?: ?float,
     *   aliquota_iss?: ?float,
     *   observacoes?: ?string,
     *   forcar_nfe?: ?bool,
     *   itens?: list<array{produto_id: string, quantidade: float|int, valor_unitario: float|int}>,
     * } $dados
     *
     * @throws EmissaoBloqueadaException
     */
    public function criar(array $dados): NotaFiscal
    {
        $ehVenda = $dados['natureza_operacao'] === 'Venda de Mercadoria';
        $modelo  = 'NFS-e';

        $configuracao  = null;
        $cliente       = null;
        $produtosPorId = [];

        if ($ehVenda) {
            $configuracao = Configuracao::first();
            if (! $configuracao || empty($configuracao->uf) || empty($configuracao->regime_tributario)) {
                throw new EmissaoBloqueadaException('Complete a UF e o regime tributário da empresa em Configurações antes de emitir NF-e.');
            }

            $cliente = Cliente::find($dados['cliente_id']);
            if (! $cliente || empty($cliente->uf)) {
                throw new EmissaoBloqueadaException('Complete a UF do cliente antes de emitir NF-e.');
            }

            // Seleção automática NFC-e/NF-e — mesma regra do store():
            // PF (CPF, 11 dígitos), sem forcar_nfe e dentro do estado → NFC-e.
            $cpfCnpjLimpo   = preg_replace('/\D/', '', (string) $cliente->cpf_cnpj);
            $ehPessoaFisica = strlen((string) $cpfCnpjLimpo) === 11;
            $forcarNfe      = (bool) ($dados['forcar_nfe'] ?? false);
            $mesmoEstado    = strtoupper((string) $cliente->uf) === strtoupper((string) $configuracao->uf);
            $modelo         = ($ehPessoaFisica && ! $forcarNfe && $mesmoEstado) ? 'NFC-e' : 'NF-e';

            foreach ($dados['itens'] ?? [] as $item) {
                $produto = Produto::findOrFail($item['produto_id']);

                if ($produto->tributacao_icms === null) {
                    throw new EmissaoBloqueadaException("Produto \"{$produto->nome}\" está com a tributação de ICMS pendente de revisão. Complete em Produtos › Pendências Fiscais antes de emitir NF-e.");
                }

                // origem nula bloqueia — 0 é um valor fiscal válido e distinto
                // (mercadoria nacional), defaultar pra 0 afirmaria um fato falso.
                if ($produto->origem === null) {
                    throw new EmissaoBloqueadaException("Produto \"{$produto->nome}\" está com a origem da mercadoria pendente de revisão. Complete em Produtos › Pendências Fiscais antes de emitir NF-e.");
                }

                $produtosPorId[$produto->id] = $produto;
            }
        }

        $subtotal = $ehVenda
            ? collect($dados['itens'] ?? [])->sum(fn ($i) => $i['quantidade'] * $i['valor_unitario'])
            : (float) ($dados['subtotal'] ?? 0);

        $desconto   = (float) ($dados['desconto'] ?? 0);
        $aliquota   = (float) ($dados['aliquota_iss'] ?? 5.00);
        $valorIss   = $ehVenda ? 0.0 : (($subtotal - $desconto) * $aliquota) / 100;
        $valorTotal = ($subtotal - $desconto) + $valorIss;

        $serie = '001';
        if ($ehVenda) {
            $serie = $modelo === 'NFC-e' ? ($configuracao->serie_nfce ?: '001') : ($configuracao->serie_nf ?: '001');
        }

        return DB::transaction(function () use ($dados, $modelo, $serie, $subtotal, $desconto, $aliquota, $valorIss, $valorTotal, $ehVenda, $configuracao, $cliente, $produtosPorId) {
            $nota = NotaFiscal::create([
                'cliente_id'        => $dados['cliente_id'],
                'os_id'             => $dados['os_id'] ?? null,
                'natureza_operacao' => $dados['natureza_operacao'],
                'forma_pagamento'   => $dados['forma_pagamento'] ?? null,
                'observacoes'       => $dados['observacoes'] ?? null,
                'modelo'            => $modelo,
                'serie'             => $serie,
                'subtotal'          => $subtotal,
                'desconto'          => $desconto,
                'aliquota_iss'      => $aliquota,
                'valor_iss'         => $valorIss,
                'valor_total'       => $valorTotal,
                'status'            => 'RASCUNHO',
            ]);

            if ($ehVenda) {
                $oficinaUf = $configuracao->uf;
                $regime    = $configuracao->regime_tributario;

                foreach ($dados['itens'] ?? [] as $item) {
                    $produto    = $produtosPorId[$item['produto_id']];
                    $tributacao = $produto->tributacao_icms;

                    $cfop = $modelo === 'NFC-e'
                        ? CfopConsumidorResolver::resolver($oficinaUf, $cliente->uf)
                        : CfopSaidaResolver::resolver($oficinaUf, $cliente->uf, $tributacao === 'ST');
                    $cstCsosn = TributacaoIcmsSaidaResolver::resolver($regime, $tributacao);

                    NotaFiscalItem::create([
                        'nota_fiscal_id'  => $nota->id,
                        'produto_id'      => $produto->id,
                        'sku'             => $produto->sku,
                        'descricao'       => $produto->nome,
                        'unidade'         => $produto->unidade,
                        'ncm'             => $produto->ncm,
                        'cfop'            => $cfop,
                        'origem'          => $produto->origem,
                        'tributacao_icms' => $tributacao,
                        'cst_csosn'       => $cstCsosn,
                        'quantidade'      => $item['quantidade'],
                        'valor_unitario'  => $item['valor_unitario'],
                    ]);
                }
            }

            return $nota;
        });
    }
}
