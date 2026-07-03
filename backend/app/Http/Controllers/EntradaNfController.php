<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\NotaEntrada;
use App\Models\Produto;
use App\Services\NotaEntradaXmlParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntradaNfController extends Controller
{
    public function parse(Request $request, NotaEntradaXmlParser $parser): JsonResponse
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'max:2048'],
        ]);

        $conteudo = (string) file_get_contents($request->file('arquivo')->getRealPath());

        try {
            $dados = $parser->parse($conteudo);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $config          = Configuracao::first();
        $markup          = (float) ($config->markup_padrao_entrada_nf ?? 40);
        $qtyMinimaPadrao = (int) ($config->estoque_limite_padrao ?? 5);

        $itens = array_map(function (array $item) use ($markup, $qtyMinimaPadrao) {
            $produto = $item['codigo_barras']
                ? Produto::where('codigo_barras', $item['codigo_barras'])->first()
                : null;

            if ($produto) {
                return [
                    'codigo_barras'  => $item['codigo_barras'],
                    'descricao_xml'  => $item['descricao'],
                    'quantidade'     => $item['quantidade'],
                    'valor_unitario' => $item['valor_unitario'],
                    'matched'        => true,
                    'produto_id'     => $produto->id,
                    'nome'           => $produto->nome,
                    'categoria'      => $produto->categoria,
                    'unidade'        => $produto->unidade,
                    'qty_atual'      => $produto->qty_atual,
                    'preco_venda'    => $produto->preco_venda,
                    'qty_minima'     => $produto->qty_minima,
                ];
            }

            $custo = $item['valor_unitario'];

            return [
                'codigo_barras'  => $item['codigo_barras'],
                'descricao_xml'  => $item['descricao'],
                'quantidade'     => $item['quantidade'],
                'valor_unitario' => $custo,
                'matched'        => false,
                'produto_id'     => null,
                'nome'           => $item['descricao'],
                'categoria'      => 'Outros',
                'unidade'        => 'Un',
                'qty_atual'      => 0,
                'preco_venda'    => round($custo * (1 + $markup / 100), 2),
                'qty_minima'     => $qtyMinimaPadrao,
            ];
        }, $dados['itens']);

        $jaLancada = $dados['chave_acesso']
            ? NotaEntrada::where('chave_acesso', $dados['chave_acesso'])->exists()
            : false;

        return response()->json([
            'numero_nf'       => $dados['numero_nf'],
            'serie'           => $dados['serie'],
            'chave_acesso'    => $dados['chave_acesso'],
            'data_emissao'    => $dados['data_emissao'],
            'fornecedor_nome' => $dados['fornecedor_nome'],
            'fornecedor_cnpj' => $dados['fornecedor_cnpj'],
            'valor_total'     => $dados['valor_total'],
            'ja_lancada'      => $jaLancada,
            'itens'           => $itens,
            'xml_original'    => $conteudo,
        ]);
    }
}
