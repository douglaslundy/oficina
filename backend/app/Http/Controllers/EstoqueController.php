<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MovimentacaoEstoque;
use App\Models\Produto;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstoqueController extends Controller
{
    public function __construct(private readonly EstoqueService $estoqueService) {}

    public function entrada(Request $request, string $produtoId): JsonResponse
    {
        $request->validate([
            'quantidade' => ['required', 'integer', 'min:1'],
            'motivo'     => ['required', 'string', 'max:100'],
        ]);

        $produto = Produto::findOrFail($produtoId);
        $this->estoqueService->entradaManual(
            $produto,
            $request->quantidade,
            $request->motivo,
            (string) auth()->id()
        );

        return response()->json([
            'message'   => 'Entrada registrada com sucesso.',
            'qty_atual' => $produto->fresh()->qty_atual,
        ]);
    }

    public function saida(Request $request, string $produtoId): JsonResponse
    {
        $request->validate([
            'quantidade' => ['required', 'integer', 'min:1'],
            'motivo'     => ['required', 'string', 'max:100'],
        ]);

        $produto = Produto::findOrFail($produtoId);

        try {
            $this->estoqueService->saidaManual(
                $produto,
                $request->quantidade,
                $request->motivo,
                (string) auth()->id()
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'   => 'Saída registrada com sucesso.',
            'qty_atual' => $produto->fresh()->qty_atual,
        ]);
    }

    public function historico(string $produtoId): JsonResponse
    {
        $movs = MovimentacaoEstoque::where('produto_id', $produtoId)
            ->orderBy('criado_em', 'desc')
            ->limit(50)
            ->get();

        return response()->json($movs);
    }
}
