<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Produto;
use App\Services\PlanLimitService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProdutoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $valorTotalEstoque = (float) Produto::where('ativo', true)
            ->selectRaw('COALESCE(SUM(preco_custo * qty_atual), 0) as total')
            ->value('total');

        $query = Produto::where('ativo', true);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(fn($q) => $q->where('nome', 'ilike', "%{$search}%")
                ->orWhere('sku', 'ilike', "%{$search}%")
                ->orWhere('codigo_barras', 'ilike', "%{$search}%"));
        }
        if ($request->has('categoria')) {
            $query->where('categoria', $request->categoria);
        }

        $all = $query->orderBy('nome')->get();

        // Filter by status_estoque (computed attribute)
        if ($request->has('status')) {
            $statuses = explode(',', (string)$request->status);
            $all = $all->filter(fn($p) => in_array($p->status_estoque, $statuses))->values();
        }

        // Simple pagination from collection
        $perPage = (int)($request->per_page ?? 20);
        $page    = (int)($request->page ?? 1);
        $total   = $all->count();
        $items   = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return ProdutoResource::collection($items)->additional([
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'valor_total_estoque' => $valorTotalEstoque,
            ],
        ]);
    }

    public function store(Request $request, PlanLimitService $planLimit): JsonResponse
    {
        $planLimit->verificarLimiteProdutos();

        $validated = $request->validate([
            'nome'          => ['required', 'string', 'max:150'],
            'sku'           => ['nullable', 'string', 'max:30', 'unique:produtos,sku'],
            'codigo_barras' => ['nullable', 'string', 'max:20', Rule::unique('produtos', 'codigo_barras')->where('oficina_id', TenancyContext::get())],
            'categoria'     => ['required', 'string', 'max:40'],
            'unidade'       => ['nullable', 'string', 'max:10'],
            'qty_atual'     => ['nullable', 'integer', 'min:0'],
            'qty_minima'    => ['nullable', 'integer', 'min:0'],
            'preco_custo'   => ['nullable', 'numeric', 'min:0'],
            'preco_venda'   => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['sku'] = $validated['sku'] ?? strtoupper(Str::random(8));

        $produto = Produto::create($validated);
        return (new ProdutoResource($produto))->response()->setStatusCode(201);
    }

    public function show(string $id): ProdutoResource
    {
        $produto = Produto::findOrFail($id);

        activity()
            ->performedOn($produto)
            ->causedBy(auth()->user())
            ->event('viewed')
            ->useLog(TenancyContext::getSlug() ?? 'default')
            ->log('viewed');

        return new ProdutoResource($produto);
    }

    public function update(Request $request, string $id): ProdutoResource
    {
        $produto   = Produto::findOrFail($id);
        $validated = $request->validate([
            'nome'          => ['sometimes', 'required', 'string', 'max:150'],
            'sku'           => ['sometimes', 'required', 'string', 'max:30', "unique:produtos,sku,{$id}"],
            'codigo_barras' => ['nullable', 'string', 'max:20', Rule::unique('produtos', 'codigo_barras')->where('oficina_id', TenancyContext::get())->ignore($id)],
            'categoria'     => ['sometimes', 'required', 'string', 'max:40'],
            'unidade'       => ['nullable', 'string', 'max:10'],
            'qty_minima'    => ['nullable', 'integer', 'min:0'],
            'preco_custo'   => ['nullable', 'numeric', 'min:0'],
            'preco_venda'   => ['nullable', 'numeric', 'min:0'],
        ]);
        $produto->update($validated);
        return new ProdutoResource($produto->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        $produto = Produto::findOrFail($id);
        $produto->update(['ativo' => false]);
        return response()->json(['message' => 'Produto desativado.']);
    }
}
