<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\OficinaServico;
use App\Models\SolicitacaoServico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SolicitacaoController extends Controller
{
    public function index(): JsonResponse
    {
        // Sem escopo de tenant — o admin vê todas; pendentes primeiro.
        $data = SolicitacaoServico::withoutGlobalScopes()
            ->with(['pacote', 'oficina:id,nome'])
            ->orderByRaw("CASE WHEN status = 'PENDENTE' THEN 0 ELSE 1 END")
            ->orderByDesc('criado_em')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function aprovar(string $id): JsonResponse
    {
        $solicitacao = SolicitacaoServico::withoutGlobalScopes()->with('pacote')->findOrFail($id);

        if ($solicitacao->status !== 'PENDENTE') {
            return response()->json(['message' => 'Solicitação já respondida.'], 422);
        }

        $pacote = $solicitacao->pacote;
        if (!$pacote) {
            return response()->json(['message' => 'Pacote não encontrado.'], 422);
        }

        DB::transaction(function () use ($solicitacao, $pacote) {
            OficinaServico::create([
                'oficina_id'      => $solicitacao->oficina_id,
                'servico'         => $pacote->servico,
                'pacote_id'       => $pacote->id,
                'quantidade'      => $pacote->quantidade,
                'valor_adicional' => $pacote->valor,
                'recorrente'      => $pacote->recorrente,
                'data_inicio'     => now()->toDateString(),
                'data_fim'        => $pacote->recorrente ? null : now()->addDays((int) ($pacote->periodo_dias ?: 30))->toDateString(),
                'ativo'           => true,
            ]);

            $solicitacao->update(['status' => 'APROVADA', 'respondido_em' => now()]);
        });

        return response()->json(['message' => 'Solicitação aprovada e serviço liberado.']);
    }

    public function recusar(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['observacao' => ['nullable', 'string', 'max:300']]);

        $solicitacao = SolicitacaoServico::withoutGlobalScopes()->findOrFail($id);
        if ($solicitacao->status !== 'PENDENTE') {
            return response()->json(['message' => 'Solicitação já respondida.'], 422);
        }

        $solicitacao->update([
            'status'        => 'RECUSADA',
            'observacao'    => $validated['observacao'] ?? null,
            'respondido_em' => now(),
        ]);

        return response()->json(['message' => 'Solicitação recusada.']);
    }
}
