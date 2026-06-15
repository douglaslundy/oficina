<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AgendamentoResource;
use App\Models\Agendamento;
use App\Models\OrdemServico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AgendamentoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Agendamento::with(['cliente', 'mecanico']);

        // Filtrar por período (aceita inicio/fim ou data_inicio/data_fim)
        $inicio = $request->input('inicio', $request->input('data_inicio'));
        $fim    = $request->input('fim',    $request->input('data_fim'));
        if ($inicio && $fim) {
            $query->whereBetween('data_hora_inicio', [$inicio, $fim . ' 23:59:59']);
        }

        if ($request->has('status')) {
            $query->whereIn('status', explode(',', (string) $request->status));
        }

        return AgendamentoResource::collection(
            $query->orderBy('data_hora_inicio')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cliente_id'       => ['required', 'string', 'exists:clientes,id'],
            'mecanico_id'      => ['nullable', 'string', 'exists:usuarios,id'],
            'tipo_servico'     => ['required', 'string', 'max:100'],
            'observacoes'      => ['nullable', 'string'],
            'data_hora_inicio' => ['required', 'date'],
            'data_hora_fim'    => ['required', 'date', 'after:data_hora_inicio'],
            'status'           => ['nullable', 'in:AGENDADO,CONFIRMADO,CANCELADO,CONCLUIDO'],
        ]);

        $agendamento = Agendamento::create($validated);

        return (new AgendamentoResource($agendamento->load(['cliente', 'mecanico'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): AgendamentoResource
    {
        return new AgendamentoResource(
            Agendamento::with(['cliente', 'mecanico', 'ordemServico'])->findOrFail($id)
        );
    }

    public function update(Request $request, string $id): AgendamentoResource
    {
        $agendamento = Agendamento::findOrFail($id);

        $validated = $request->validate([
            'tipo_servico'     => ['sometimes', 'string', 'max:100'],
            'observacoes'      => ['nullable', 'string'],
            'data_hora_inicio' => ['sometimes', 'date'],
            'data_hora_fim'    => ['sometimes', 'date'],
            'status'           => ['sometimes', 'in:AGENDADO,CONFIRMADO,CANCELADO,CONCLUIDO'],
            'mecanico_id'      => ['nullable', 'string', 'exists:usuarios,id'],
        ]);

        $agendamento->update($validated);

        return new AgendamentoResource($agendamento->fresh()->load(['cliente', 'mecanico']));
    }

    public function confirmar(string $id): JsonResponse
    {
        $agendamento = Agendamento::with('cliente')->findOrFail($id);

        if ($agendamento->os_id) {
            return response()->json([
                'message' => 'OS já foi gerada para este agendamento.',
                'os_id'   => $agendamento->os_id,
            ]);
        }

        return DB::transaction(function () use ($agendamento): JsonResponse {
            // Criar rascunho de OS automaticamente
            $os = OrdemServico::create([
                'cliente_id'        => $agendamento->cliente_id,
                'mecanico_id'       => $agendamento->mecanico_id,
                'veiculo_descricao' => $agendamento->cliente->veiculo_modelo,
                'veiculo_placa'     => $agendamento->cliente->veiculo_placa,
                'problema_relatado' => $agendamento->tipo_servico . ($agendamento->observacoes ? "\n" . $agendamento->observacoes : ''),
                'status'            => 'ABERTA',
                'prazo_entrega'     => $agendamento->data_hora_fim?->toDateString(),
            ]);

            $agendamento->update([
                'status' => 'CONFIRMADO',
                'os_id'  => $os->id,
            ]);

            return response()->json([
                'message'   => 'Agendamento confirmado e OS #' . $os->numero . ' criada.',
                'os_id'     => $os->id,
                'os_numero' => $os->numero,
            ]);
        });
    }

    public function cancelar(string $id): JsonResponse
    {
        $agendamento = Agendamento::findOrFail($id);
        $agendamento->update(['status' => 'CANCELADO']);
        return response()->json(['message' => 'Agendamento cancelado.']);
    }

    public function destroy(string $id): JsonResponse
    {
        $agendamento = Agendamento::findOrFail($id);
        if ($agendamento->status === 'CONFIRMADO') {
            return response()->json(['message' => 'Não é possível excluir um agendamento já confirmado.'], 400);
        }
        $agendamento->delete();
        return response()->json(['message' => 'Agendamento removido.']);
    }
}
