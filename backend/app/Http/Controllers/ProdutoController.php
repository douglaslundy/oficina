<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Produto;
use App\Services\Fiscal\ProdutoFiscalService;
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

        $validated = $request->validate(array_merge([
            'nome'          => ['required', 'string', 'max:150'],
            'sku'           => ['nullable', 'string', 'max:30', 'unique:produtos,sku'],
            'codigo_barras' => ['nullable', 'string', 'max:20', Rule::unique('produtos', 'codigo_barras')->where('oficina_id', TenancyContext::get())],
            'categoria'     => ['required', 'string', 'max:40'],
            'unidade'       => ['nullable', 'string', 'max:10'],
            'qty_atual'     => ['nullable', 'integer', 'min:0'],
            'qty_minima'    => ['nullable', 'integer', 'min:0'],
            'preco_custo'   => ['nullable', 'numeric', 'min:0'],
            'preco_venda'   => ['nullable', 'numeric', 'min:0'],
        ], $this->regrasFiscais()));

        $validated['sku'] = $validated['sku'] ?? strtoupper(Str::random(8));

        // Detectar se o usuário forneceu dados fiscais não-vazios
        $temMudancaFiscal = $this->temMudancaFiscalNoValidated($validated);

        $produto = Produto::create($validated);
        app(ProdutoFiscalService::class)->aplicarPadraoCategoria($produto);
        $this->carimbarRevisaoFiscal($produto, $temMudancaFiscal);
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
        $validated = $request->validate(array_merge([
            'nome'          => ['sometimes', 'required', 'string', 'max:150'],
            'sku'           => ['sometimes', 'required', 'string', 'max:30', "unique:produtos,sku,{$id}"],
            'codigo_barras' => ['nullable', 'string', 'max:20', Rule::unique('produtos', 'codigo_barras')->where('oficina_id', TenancyContext::get())->ignore($id)],
            'categoria'     => ['sometimes', 'required', 'string', 'max:40'],
            'unidade'       => ['nullable', 'string', 'max:10'],
            'qty_minima'    => ['nullable', 'integer', 'min:0'],
            'preco_custo'   => ['nullable', 'numeric', 'min:0'],
            'preco_venda'   => ['nullable', 'numeric', 'min:0'],
        ], $this->regrasFiscais()));
        $produto->update($validated);

        // Capturar mudanças fiscais imediatamente após update e antes de chamar stamp
        $temMudancaFiscal = $this->temMudancaFiscalNoProduto($produto);

        $this->carimbarRevisaoFiscal($produto, $temMudancaFiscal);
        return new ProdutoResource($produto->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        $produto = Produto::findOrFail($id);
        $produto->update(['ativo' => false]);
        return response()->json(['message' => 'Produto desativado.']);
    }

    /** @return array<string, list<string>> Regras de validação dos campos fiscais editáveis. */
    private function regrasFiscais(): array
    {
        return [
            'ncm'             => ['nullable', 'string', 'size:8'],
            'cest'            => ['nullable', 'string', 'size:7'],
            'origem'          => ['nullable', 'integer', 'min:0', 'max:8'],
            'tributacao_icms' => ['nullable', 'string', 'in:NORMAL,ST'],
        ];
    }

    /**
     * Carimba a revisão manual quando o usuário realmente alterou dados fiscais.
     * Só é chamado quando há mudança fiscal efetiva, evitando sobrescrever PADRAO.
     */
    private function carimbarRevisaoFiscal(Produto $produto, bool $temMudancaFiscal): void
    {
        if (!$temMudancaFiscal) {
            return;
        }

        $produto->update([
            'fiscal_fonte'       => 'MANUAL',
            'fiscal_revisado_em' => now(),
        ]);
    }

    /**
     * Verifica se o validated request contém algum campo fiscal não-vazio.
     * Usado em store() para distinguir entre "usuário preencheu" vs "formulário apenas enviou campo vazio".
     */
    private function temMudancaFiscalNoValidated(array $validated): bool
    {
        $camposFiscais = array_keys($this->regrasFiscais());
        foreach ($camposFiscais as $campo) {
            if (!empty($validated[$campo] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica se algum campo fiscal foi realmente alterado no modelo.
     * Usado em update() após $produto->update() para capturar mudanças reais.
     */
    private function temMudancaFiscalNoProduto(Produto $produto): bool
    {
        $camposFiscais = array_keys($this->regrasFiscais());
        foreach ($camposFiscais as $campo) {
            if ($produto->wasChanged($campo)) {
                return true;
            }
        }
        return false;
    }
}
