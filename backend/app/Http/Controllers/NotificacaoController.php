<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notificacao;
use App\Models\NotificacaoVisualizacao;
use App\Models\Oficina;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacaoController extends Controller
{
    /** Notificações ativas e elegíveis para a oficina atual (para exibir no modal). */
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
            ->filter(fn (Notificacao $n) => $this->elegivelParaExibir($n, $oficina))
            ->map(fn (Notificacao $n) => [
                'id'        => $n->id,
                'titulo'    => $n->titulo,
                'subtitulo' => $n->subtitulo,
                'texto'     => $n->texto,
                'imagem'    => $n->imagem,
            ])
            ->values();

        return response()->json(['data' => $notificacoes]);
    }

    /** Registra que a oficina/usuário atual visualizou (fechou) a notificação. */
    public function visualizar(string $id): JsonResponse
    {
        $notificacao = Notificacao::findOrFail($id);
        $oficinaId = TenancyContext::get();

        NotificacaoVisualizacao::create([
            'tipo'           => 'MANUAL',
            'notificacao_id' => $notificacao->id,
            'titulo'         => $notificacao->titulo,
            'mensagem'       => $notificacao->texto,
            'oficina_id'     => $oficinaId,
            'usuario_id'     => auth()->id(),
            'ip'             => request()->ip(),
            'user_agent'     => request()->userAgent(),
        ]);

        return response()->json(['message' => 'Visualização registrada.'], 201);
    }

    /**
     * Elegibilidade server-side: no máximo `vezes_dia` exibições por dia,
     * respeitando `intervalo_minutos` desde a última exibição — throttle
     * por oficina (todos os usuários da equipe compartilham a mesma cota),
     * não por usuário individual.
     */
    private function elegivelParaExibir(Notificacao $n, Oficina $oficina): bool
    {
        $hoje = now()->toDateString();

        $countHoje = NotificacaoVisualizacao::where('notificacao_id', $n->id)
            ->where('oficina_id', $oficina->id)
            ->whereDate('visualizado_em', $hoje)
            ->count();

        if ($countHoje >= $n->vezes_dia) {
            return false;
        }

        $ultima = NotificacaoVisualizacao::where('notificacao_id', $n->id)
            ->where('oficina_id', $oficina->id)
            ->orderByDesc('visualizado_em')
            ->value('visualizado_em');

        if ($ultima && $ultima->diffInMinutes(now()) < $n->intervalo_minutos) {
            return false;
        }

        return true;
    }
}
