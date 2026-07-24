<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\NotificacaoVisualizacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacaoCobrancaLogController extends Controller
{
    /** Agrupa exibições do alerta de cobrança por (oficina, cobrança). */
    public function index(): JsonResponse
    {
        $grupos = NotificacaoVisualizacao::query()
            ->where('tipo', 'COBRANCA')
            ->select('oficina_id', 'cobranca_id')
            ->selectRaw('count(*) as total_exibicoes')
            ->selectRaw('max(visualizado_em) as ultima_exibicao_em')
            ->groupBy('oficina_id', 'cobranca_id')
            ->orderByDesc('ultima_exibicao_em')
            ->with(['oficina:id,nome', 'cobranca:id,valor,vencimento,status'])
            ->get()
            ->map(function ($g) {
                $g->total_exibicoes = (int) $g->total_exibicoes;
                return $g;
            });

        return response()->json(['data' => $grupos]);
    }

    /** Log paginado de visualizações de um grupo (oficina, cobrança) específico. */
    public function log(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'oficina_id'  => ['required', 'uuid'],
            'cobranca_id' => ['required', 'uuid'],
        ]);

        $logs = NotificacaoVisualizacao::where('tipo', 'COBRANCA')
            ->where('oficina_id', $validated['oficina_id'])
            ->where('cobranca_id', $validated['cobranca_id'])
            ->with('usuario:id,nome')
            ->orderByDesc('visualizado_em')
            ->paginate(20);

        return response()->json($logs);
    }
}
