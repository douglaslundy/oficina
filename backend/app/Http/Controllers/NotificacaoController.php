<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notificacao;
use App\Models\Oficina;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;

class NotificacaoController extends Controller
{
    /** Notificações ativas direcionadas à oficina atual (para exibir no modal). */
    public function ativas(): JsonResponse
    {
        $oficina = Oficina::find(TenancyContext::get());
        if (!$oficina) {
            return response()->json(['data' => []]);
        }

        $hoje = now()->toDateString();

        $notificacoes = Notificacao::where('ativo', true)
            ->where(fn ($q) => $q->whereNull('data_inicio')->orWhere('data_inicio', '<=', $hoje))
            ->where(fn ($q) => $q->whereNull('data_fim')->orWhere('data_fim', '>=', $hoje))
            ->orderByDesc('criado_em')
            ->get()
            ->filter(function (Notificacao $n) use ($oficina) {
                return match ($n->alvo_tipo) {
                    'TODOS'    => true,
                    'PLANO'    => $n->plano_id === $oficina->plano_id,
                    'OFICINAS' => in_array($oficina->id, (array) $n->oficina_ids, true),
                    default    => false,
                };
            })
            ->map(fn (Notificacao $n) => [
                'id'                => $n->id,
                'titulo'            => $n->titulo,
                'subtitulo'         => $n->subtitulo,
                'texto'             => $n->texto,
                'imagem'            => $n->imagem,
                'vezes_dia'         => $n->vezes_dia,
                'intervalo_minutos' => $n->intervalo_minutos,
            ])
            ->values();

        return response()->json(['data' => $notificacoes]);
    }
}
