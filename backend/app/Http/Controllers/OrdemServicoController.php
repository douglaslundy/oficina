<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OrdemServicoResource;
use App\Models\OrdemServico;
use App\Services\ClienteStatusService;
use App\Services\EstoqueService;
use App\Services\PlanLimitService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class OrdemServicoController extends Controller
{
    public function __construct(
        private readonly EstoqueService $estoqueService,
        private readonly ClienteStatusService $clienteStatusService,
        private readonly PlanLimitService $planLimit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = OrdemServico::with(['cliente', 'mecanico']);

        if ($request->has('cliente_id')) {
            $query->where('cliente_id', $request->cliente_id);
        }
        if ($request->has('status')) {
            $query->whereIn('status', explode(',', (string)$request->status));
        }
        if ($request->has('mecanico_id')) {
            $query->where('mecanico_id', $request->mecanico_id);
        }
        if ($request->has('numero')) {
            $query->where('numero', (int)$request->numero);
        }
        if ($request->has('data_inicio')) {
            $query->whereDate('criado_em', '>=', $request->data_inicio);
        }
        if ($request->has('data_fim')) {
            $query->whereDate('criado_em', '<=', $request->data_fim);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('cliente', fn($c) => $c->where('nome', 'ilike', "%{$search}%"))
                  ->orWhere('numero', is_numeric($search) ? (int)$search : 0);
            });
        }

        return OrdemServicoResource::collection($query->orderBy('criado_em', 'desc')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $this->planLimit->verificarLimiteOsMensal();

        $validated = $request->validate([
            'cliente_id'              => ['required', 'string', 'exists:clientes,id'],
            'mecanico_id'             => ['nullable', 'string', 'exists:usuarios,id'],
            'veiculo_descricao'       => ['nullable', 'string', 'max:100'],
            'veiculo_placa'           => ['nullable', 'string', 'max:10'],
            'problema_relatado'       => ['nullable', 'string'],
            'status'                  => ['nullable', 'string'],
            'forma_pagamento'         => ['nullable', 'string'],
            'prazo_entrega'           => ['nullable', 'date'],
            'venda_a_prazo'           => ['nullable', 'boolean'],
            'prazo_pagamento_dias'    => ['nullable', 'integer', 'min:1', 'max:365'],
            'itens'                   => ['nullable', 'array'],
            'itens.*.tipo'            => ['required', 'in:SERVICO,PECA'],
            'itens.*.produto_id'      => ['nullable', 'string', 'exists:produtos,id'],
            'itens.*.descricao'       => ['required', 'string', 'max:200'],
            'itens.*.quantidade'      => ['required', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario'  => ['required', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($validated) {
            $osData = collect($validated)->except('itens')->toArray();

            // Calcular vencimento ao criar já como CONCLUIDA com prazo
            if (($osData['status'] ?? 'ABERTA') === 'CONCLUIDA'
                && !empty($osData['venda_a_prazo'])
                && !empty($osData['prazo_pagamento_dias'])) {
                $osData['data_vencimento_pagamento'] = now()->addDays($osData['prazo_pagamento_dias'])->toDateString();
            }

            $os = OrdemServico::create($osData);

            $total = 0;
            foreach ($validated['itens'] ?? [] as $item) {
                $os->itens()->create($item);
                $total += $item['quantidade'] * $item['valor_unitario'];
            }
            $os->update(['valor_total' => $total]);

            $this->clienteStatusService->recalcular($os->cliente_id);

            return (new OrdemServicoResource($os->load(['cliente', 'mecanico', 'itens'])))->response()->setStatusCode(201);
        });
    }

    public function show(string $id): OrdemServicoResource
    {
        return new OrdemServicoResource(
            OrdemServico::with(['cliente', 'mecanico', 'itens.produto'])->findOrFail($id)
        );
    }

    public function update(Request $request, string $id): OrdemServicoResource
    {
        $os = OrdemServico::with('itens')->findOrFail($id);
        $novoStatus = $request->status;

        $validated = $request->validate([
            'status'               => ['sometimes', 'string'],
            'mecanico_id'          => ['sometimes', 'nullable', 'string', 'exists:usuarios,id'],
            'valor_pago'           => ['sometimes', 'numeric', 'min:0'],
            'forma_pagamento'      => ['sometimes', 'nullable', 'string'],
            'prazo_entrega'        => ['sometimes', 'nullable', 'date'],
            'venda_a_prazo'        => ['sometimes', 'boolean'],
            'prazo_pagamento_dias' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return DB::transaction(function () use ($os, $validated, $novoStatus) {
            $wasNotConcluida = $os->status !== 'CONCLUIDA';

            if ($novoStatus === 'CONCLUIDA' && $wasNotConcluida) {
                $this->estoqueService->baixarEstoqueOs($os);
            }

            // Calcular data de vencimento ao concluir com prazo
            $vendaAPrazo  = $validated['venda_a_prazo'] ?? $os->venda_a_prazo;
            $prazoDias    = $validated['prazo_pagamento_dias'] ?? $os->prazo_pagamento_dias;

            if ($vendaAPrazo && $prazoDias) {
                if ($novoStatus === 'CONCLUIDA' && $wasNotConcluida) {
                    // Conclusão agora: vencimento a partir de hoje
                    $validated['data_vencimento_pagamento'] = now()->addDays($prazoDias)->toDateString();
                } elseif ($os->status === 'CONCLUIDA' && isset($validated['prazo_pagamento_dias'])) {
                    // Alteração do prazo numa OS já concluída: recalcula a partir de hoje
                    $validated['data_vencimento_pagamento'] = now()->addDays($prazoDias)->toDateString();
                }
            }

            $os->update($validated);
            $this->clienteStatusService->recalcular($os->cliente_id);

            // Dispatch NPS email 2 days after OS completion
            if ($novoStatus === 'CONCLUIDA' && $wasNotConcluida) {
                \App\Jobs\EnviarNpsCliente::dispatch($os->fresh())->delay(now()->addDays(2));
            }

            return new OrdemServicoResource($os->fresh()->load(['cliente', 'mecanico', 'itens']));
        });
    }

    public function addItem(Request $request, string $osId): JsonResponse
    {
        $os = OrdemServico::findOrFail($osId);

        if (in_array($os->status, ['CONCLUIDA', 'CANCELADA'], true)) {
            return response()->json(['message' => 'Não é possível editar itens de OS concluída ou cancelada.'], 422);
        }

        $validated = $request->validate([
            'tipo'           => ['required', 'in:SERVICO,PECA'],
            'produto_id'     => ['nullable', 'string', 'exists:produtos,id'],
            'descricao'      => ['required', 'string', 'max:200'],
            'quantidade'     => ['required', 'numeric', 'min:0.01'],
            'valor_unitario' => ['required', 'numeric', 'min:0'],
        ]);

        $item = $os->itens()->create($validated);

        $total = $os->itens()->sum(DB::raw('quantidade * valor_unitario'));
        $os->update(['valor_total' => $total]);

        return response()->json(['data' => $item], 201);
    }

    public function updateItem(Request $request, string $osId, string $itemId): JsonResponse
    {
        $os   = OrdemServico::findOrFail($osId);
        $item = $os->itens()->findOrFail($itemId);

        if (in_array($os->status, ['CONCLUIDA', 'CANCELADA'], true)) {
            return response()->json(['message' => 'Não é possível editar itens de OS concluída ou cancelada.'], 422);
        }

        $validated = $request->validate([
            'descricao'      => ['sometimes', 'string', 'max:200'],
            'quantidade'     => ['sometimes', 'numeric', 'min:0.01'],
            'valor_unitario' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $item->update($validated);

        $total = $os->itens()->sum(DB::raw('quantidade * valor_unitario'));
        $os->update(['valor_total' => $total]);

        return response()->json(['data' => $item->fresh()]);
    }

    public function removeItem(Request $request, string $osId, string $itemId): JsonResponse
    {
        $os   = OrdemServico::findOrFail($osId);
        $item = $os->itens()->findOrFail($itemId);

        if (in_array($os->status, ['CONCLUIDA', 'CANCELADA'], true)) {
            return response()->json(['message' => 'Não é possível remover itens de OS concluída ou cancelada.'], 422);
        }

        $item->delete();

        $total = $os->itens()->sum(DB::raw('quantidade * valor_unitario'));
        $os->update(['valor_total' => $total]);

        return response()->json(['message' => 'Item removido.']);
    }

    public function pdf(string $id): \Illuminate\Http\Response
    {
        $os = OrdemServico::with(['cliente', 'mecanico', 'itens'])->findOrFail($id);
        $os->mecanicoResponsavel = $os->mecanico;

        $empresa = \App\Models\Configuracao::first()?->toArray() ?? [];

        $pdf = Pdf::loadView('pdf.os', compact('os', 'empresa'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('OS-' . $os->numero . '.pdf');
    }

    public function recibo(string $id): \Illuminate\Http\Response
    {
        $os = OrdemServico::with(['cliente', 'mecanico'])->findOrFail($id);

        if ($os->valor_pago <= 0) {
            abort(422, 'Esta OS não possui pagamento registrado.');
        }

        $empresa = \App\Models\Configuracao::first()?->toArray() ?? [];

        $pdf = Pdf::loadView('pdf.recibo', compact('os', 'empresa'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('Recibo-OS-' . $os->numero . '.pdf');
    }
}
