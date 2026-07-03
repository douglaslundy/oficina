<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\NotaEntradaResource;
use App\Models\Configuracao;
use App\Models\NotaEntrada;
use App\Models\NotaEntradaItem;
use App\Models\Produto;
use App\Services\EstoqueService;
use App\Services\NotaEntradaXmlParser;
use App\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function store(Request $request, EstoqueService $estoqueService, PlanLimitService $planLimit): JsonResponse
    {
        $validated = $request->validate([
            'numero_nf'              => ['nullable', 'string', 'max:20'],
            'serie'                  => ['nullable', 'string', 'max:5'],
            'chave_acesso'           => ['nullable', 'string', 'max:44'],
            'fornecedor_nome'        => ['nullable', 'string', 'max:150'],
            'fornecedor_cnpj'        => ['nullable', 'string', 'max:18'],
            'data_emissao'           => ['nullable', 'date'],
            'xml_original'           => ['nullable', 'string'],
            'itens'                  => ['required', 'array', 'min:1'],
            'itens.*.produto_id'     => ['nullable', 'uuid', 'exists:produtos,id'],
            'itens.*.codigo_barras'  => ['nullable', 'string', 'max:20'],
            'itens.*.nome'           => ['required_without:itens.*.produto_id', 'nullable', 'string', 'max:150'],
            'itens.*.categoria'      => ['required_without:itens.*.produto_id', 'nullable', 'string', 'max:40'],
            'itens.*.unidade'        => ['nullable', 'string', 'max:10'],
            'itens.*.quantidade'     => ['required', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario' => ['required', 'numeric', 'min:0'],
            'itens.*.preco_venda'    => ['nullable', 'numeric', 'min:0'],
            'itens.*.qty_minima'     => ['nullable', 'integer', 'min:0'],
        ]);

        if (!empty($validated['chave_acesso']) && NotaEntrada::where('chave_acesso', $validated['chave_acesso'])->exists()) {
            return response()->json(['message' => 'Esta nota fiscal já foi lançada anteriormente.'], 422);
        }

        $config         = Configuracao::first();
        $atualizarCusto = (bool) ($config->atualizar_custo_entrada_nf ?? true);
        $usuarioId      = (string) auth()->id();

        $nota = DB::transaction(function () use ($validated, $estoqueService, $planLimit, $atualizarCusto, $usuarioId) {
            $valorTotal = collect($validated['itens'])->sum(fn($i) => $i['quantidade'] * $i['valor_unitario']);

            $nota = NotaEntrada::create([
                'numero_nf'       => $validated['numero_nf'] ?? null,
                'serie'           => $validated['serie'] ?? null,
                'chave_acesso'    => $validated['chave_acesso'] ?? null,
                'fornecedor_nome' => $validated['fornecedor_nome'] ?? null,
                'fornecedor_cnpj' => $validated['fornecedor_cnpj'] ?? null,
                'valor_total'     => $valorTotal,
                'data_emissao'    => $validated['data_emissao'] ?? null,
                'xml_original'    => $validated['xml_original'] ?? null,
                'usuario_id'      => $usuarioId,
            ]);

            foreach ($validated['itens'] as $item) {
                $produtoCriado = false;

                if (!empty($item['produto_id'])) {
                    $produto = Produto::lockForUpdate()->findOrFail($item['produto_id']);
                    if ($atualizarCusto) {
                        $produto->update(['preco_custo' => $item['valor_unitario']]);
                    }
                } else {
                    $planLimit->verificarLimiteProdutos();
                    $produto = Produto::create([
                        'nome'          => $item['nome'],
                        'sku'           => strtoupper(Str::random(8)),
                        'codigo_barras' => $item['codigo_barras'] ?? null,
                        'categoria'     => $item['categoria'],
                        'unidade'       => $item['unidade'] ?? 'Un',
                        'qty_atual'     => 0,
                        'qty_minima'    => $item['qty_minima'] ?? 5,
                        'preco_custo'   => $item['valor_unitario'],
                        'preco_venda'   => $item['preco_venda'] ?? $item['valor_unitario'],
                    ]);
                    $produtoCriado = true;
                }

                $estoqueService->registrarEntradaItem(
                    $produto->id,
                    (int) $item['quantidade'],
                    $nota->id,
                    $usuarioId,
                );

                NotaEntradaItem::create([
                    'nota_entrada_id'   => $nota->id,
                    'produto_id'        => $produto->id,
                    'codigo_barras_xml' => $item['codigo_barras'] ?? null,
                    'descricao_xml'     => $item['nome'] ?? $produto->nome,
                    'quantidade'        => $item['quantidade'],
                    'valor_unitario'    => $item['valor_unitario'],
                    'produto_criado'    => $produtoCriado,
                ]);
            }

            return $nota;
        });

        return (new NotaEntradaResource($nota->load('itens')))->response()->setStatusCode(201);
    }

    public function index(): AnonymousResourceCollection
    {
        return NotaEntradaResource::collection(
            NotaEntrada::orderByDesc('criado_em')->paginate(20)
        );
    }

    public function show(string $id): NotaEntradaResource
    {
        return new NotaEntradaResource(NotaEntrada::with('itens')->findOrFail($id));
    }
}
