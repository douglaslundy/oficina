<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\OficinaServico;
use App\Models\PacoteServico;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OficinaServicoController extends Controller
{
    /** Lista os serviços avulsos liberados para a oficina. */
    public function index(string $oficinaId): JsonResponse
    {
        $data = OficinaServico::where('oficina_id', $oficinaId)
            ->orderByDesc('criado_em')->get();
        return response()->json(['data' => $data]);
    }

    /**
     * Libera um serviço avulso. Pode vir de um pacote (pacote_id) ou personalizado.
     */
    public function store(Request $request, string $oficinaId): JsonResponse
    {
        $validated = $request->validate([
            'pacote_id'       => ['nullable', 'uuid', 'exists:pacotes_servico,id'],
            'servico'         => ['required_without:pacote_id', Rule::in(EntitlementService::SERVICOS)],
            'quantidade'      => ['required_without:pacote_id', 'integer', 'min:-1'],
            'valor_adicional' => ['required_without:pacote_id', 'numeric', 'min:0'],
            'recorrente'      => ['boolean'],
            'periodo_dias'    => ['nullable', 'integer', 'min:1'],
        ]);

        // Se veio de um pacote, herda os parâmetros dele (admin pode ainda personalizar).
        if (!empty($validated['pacote_id'])) {
            $pacote = PacoteServico::findOrFail($validated['pacote_id']);
            $servico     = $validated['servico']         ?? $pacote->servico;
            $quantidade  = $validated['quantidade']      ?? $pacote->quantidade;
            $valor       = $validated['valor_adicional'] ?? (float) $pacote->valor;
            $recorrente  = $validated['recorrente']      ?? $pacote->recorrente;
            $periodoDias = $validated['periodo_dias']    ?? $pacote->periodo_dias;
        } else {
            $servico     = $validated['servico'];
            $quantidade  = $validated['quantidade'];
            $valor       = $validated['valor_adicional'];
            $recorrente  = $validated['recorrente'] ?? true;
            $periodoDias = $validated['periodo_dias'] ?? null;
        }

        $grant = OficinaServico::create([
            'oficina_id'      => $oficinaId,
            'servico'         => $servico,
            'pacote_id'       => $validated['pacote_id'] ?? null,
            'quantidade'      => $quantidade,
            'valor_adicional' => $valor,
            'recorrente'      => $recorrente,
            'data_inicio'     => now()->toDateString(),
            'data_fim'        => $recorrente ? null : now()->addDays((int) ($periodoDias ?: 30))->toDateString(),
            'ativo'           => true,
        ]);

        return response()->json(['message' => 'Serviço liberado para a oficina.', 'data' => $grant], 201);
    }

    public function destroy(string $oficinaId, string $id): JsonResponse
    {
        $grant = OficinaServico::where('oficina_id', $oficinaId)->findOrFail($id);
        $grant->delete();
        return response()->json(['message' => 'Serviço removido.']);
    }
}
