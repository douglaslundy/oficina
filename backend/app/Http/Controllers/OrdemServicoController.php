<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OrdemServicoResource;
use App\Models\OrdemServico;
use App\Services\ClienteStatusService;
use App\Services\EstoqueService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class OrdemServicoController extends Controller
{
    public function __construct(
        private readonly EstoqueService $estoqueService,
        private readonly ClienteStatusService $clienteStatusService
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

        return OrdemServicoResource::collection($query->orderBy('criado_em', 'desc')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
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

    public function pdf(string $id): \Illuminate\Http\Response
    {
        $os = OrdemServico::with(['cliente', 'mecanico', 'itens'])->findOrFail($id);
        $os->mecanicoResponsavel = $os->mecanico;

        $empresa = \App\Models\Configuracao::first()?->toArray() ?? [];

        $pdf = Pdf::loadView('pdf.os', compact('os', 'empresa'))
            ->setPaper('a4', 'portrait');

        $filename = 'OS-' . $os->numero . '.pdf';

        return $pdf->download($filename);
    }
}
