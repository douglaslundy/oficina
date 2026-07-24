<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Notificacao;
use App\Models\NotificacaoVisualizacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacaoController extends Controller
{
    public function index(): JsonResponse
    {
        $data = Notificacao::orderByDesc('criado_em')->get()->map(function (Notificacao $n) {
            $arr = $n->toArray();
            $arr['total_visualizacoes'] = NotificacaoVisualizacao::where('notificacao_id', $n->id)->count();
            $arr['oficinas_distintas']  = NotificacaoVisualizacao::where('notificacao_id', $n->id)
                ->distinct()->count('oficina_id');
            return $arr;
        });

        return response()->json(['data' => $data]);
    }

    /** Log paginado de visualizações de uma notificação manual específica. */
    public function log(string $id): JsonResponse
    {
        Notificacao::findOrFail($id);

        $logs = NotificacaoVisualizacao::where('notificacao_id', $id)
            ->with(['oficina:id,nome', 'usuario:id,nome'])
            ->orderByDesc('visualizado_em')
            ->paginate(20);

        return response()->json($logs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $data['ativo'] = false;
        $notificacao = Notificacao::create($data);
        return response()->json(['message' => 'Notificação criada.', 'data' => $notificacao], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $notificacao = Notificacao::findOrFail($id);
        $notificacao->update($this->validatePayload($request));
        return response()->json(['message' => 'Notificação atualizada.', 'data' => $notificacao]);
    }

    public function destroy(string $id): JsonResponse
    {
        Notificacao::findOrFail($id)->delete();
        return response()->json(['message' => 'Notificação removida.']);
    }

    public function publicar(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['ativo' => ['required', 'boolean']]);
        $notificacao = Notificacao::findOrFail($id);
        $notificacao->update(['ativo' => $validated['ativo']]);
        return response()->json(['message' => 'Status atualizado.', 'data' => $notificacao]);
    }

    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'titulo'            => ['required', 'string', 'max:150'],
            'subtitulo'         => ['nullable', 'string', 'max:200'],
            'texto'             => ['required', 'string'],
            'imagem'            => ['nullable', 'string', 'max:2500000'], // data URL base64
            'alvo_tipo'         => ['required', 'in:TODOS,PLANO,OFICINAS'],
            'plano_id'          => ['nullable', 'required_if:alvo_tipo,PLANO', 'uuid'],
            'oficina_ids'       => ['array', 'required_if:alvo_tipo,OFICINAS'],
            'oficina_ids.*'     => ['uuid'],
            'vezes_dia'         => ['required', 'integer', 'min:1', 'max:50'],
            'intervalo_minutos' => ['required', 'integer', 'min:1', 'max:10080'],
            'data_inicio'       => ['nullable', 'date'],
            'data_fim'          => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'ativo'             => ['boolean'],
        ]);

        if ($validated['alvo_tipo'] !== 'PLANO')    $validated['plano_id'] = null;
        if ($validated['alvo_tipo'] !== 'OFICINAS') $validated['oficina_ids'] = [];

        return $validated;
    }
}
