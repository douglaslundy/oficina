<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cobranca;
use App\Models\Oficina;
use App\Models\SaasConfig;
use App\Services\AssinaturaAlertaService;
use App\Services\AssinaturaService;
use App\Services\PagamentoReconciliacaoService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssinaturaController extends Controller
{
    public function __construct(
        private readonly AssinaturaAlertaService $alertaService,
        private readonly AssinaturaService $assinaturaService,
        private readonly PagamentoReconciliacaoService $reconciliacao,
    ) {}

    /**
     * Concilia ativamente as cobranças em aberto da oficina contra o
     * gateway antes de responder — não depende só do webhook ter chegado.
     * Sem isso, um pagamento feito fora da tela de checkout transparente
     * (ex.: PIX pago pelo app do banco depois de fechar a aba) só
     * atualizaria quando o webhook chegasse, o que pode nunca acontecer se
     * ele não estiver configurado corretamente.
     */
    private function conciliarEmAberto(Oficina $oficina): void
    {
        Cobranca::where('oficina_id', $oficina->id)
            ->whereIn('status', ['PENDENTE', 'VENCIDA'])
            ->where(function ($q) {
                $q->whereNotNull('mp_payment_id')->orWhereNotNull('asaas_payment_id');
            })
            ->get()
            ->each(fn (Cobranca $c) => $this->reconciliacao->verificarEConciliar($c));
    }

    public function alerta(): JsonResponse
    {
        $oficina = Oficina::find(TenancyContext::get());
        if (!$oficina) {
            return response()->json(['show' => false]);
        }

        $this->conciliarEmAberto($oficina);
        $oficina->refresh();

        return response()->json($this->alertaService->status($oficina));
    }

    public function mudarCiclo(Request $request): JsonResponse
    {
        $validated = $request->validate(['ciclo' => 'required|in:MENSAL,ANUAL']);
        $oficina   = Oficina::findOrFail(TenancyContext::get());

        $temCobrancaEmAberto = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->whereIn('status', ['PENDENTE', 'VENCIDA'])
            ->exists();

        if ($temCobrancaEmAberto) {
            return response()->json([
                'message' => 'Você tem uma fatura em aberto. Pague-a antes de mudar o ciclo de cobrança.',
            ], 422);
        }

        $this->assinaturaService->mudarCiclo($oficina, $validated['ciclo']);

        return response()->json(['message' => 'Ciclo de cobrança atualizado.', 'data' => [
            'ciclo_cobranca'     => $oficina->ciclo_cobranca,
            'proximo_vencimento' => $oficina->proximo_vencimento->toDateString(),
        ]]);
    }

    /** Lista todas as faturas (assinatura + avulsas) da oficina logada, mais recente primeiro. */
    public function faturas(): JsonResponse
    {
        $oficina = Oficina::find(TenancyContext::get());
        if (!$oficina) {
            return response()->json(['data' => []]);
        }

        $this->conciliarEmAberto($oficina);

        $cobrancas = Cobranca::where('oficina_id', $oficina->id)
            ->orderByDesc('vencimento')
            ->get();

        return response()->json([
            'data' => $cobrancas->map(fn (Cobranca $c) => [
                'id'             => $c->id,
                'tipo'           => $c->tipo,
                'tipo_label'     => $this->tipoLabel($c),
                'descricao'      => $c->descricao ?: ($c->tipo === 'ASSINATURA' ? 'Mensalidade/Anuidade' : 'Cobrança avulsa'),
                'valor'          => number_format((float) $c->valor, 2, '.', ''),
                'status'         => $c->status,
                'vencimento'     => $c->vencimento?->toDateString(),
                'pago_em'        => $c->pago_em?->toIso8601String(),
                'link_pagamento' => $c->link_pagamento,
                'gateway'        => $c->gateway,
                'id_pagamento'   => $c->asaas_payment_id ?? $c->mp_payment_id,
            ])->values(),
        ]);
    }

    /**
     * Rótulo do tipo de cobrança para exibição — nunca "Mensalidade/Anuidade"
     * junto, sempre um ou outro. Para ASSINATURA, deriva do texto de
     * `descricao` gravado na criação (CobrancaRecorrenteService), que já
     * reflete o ciclo real daquela cobrança específica — não o ciclo atual
     * da oficina, que pode ter mudado depois.
     */
    private function tipoLabel(Cobranca $c): string
    {
        if ($c->tipo !== 'ASSINATURA') {
            return 'Avulsa';
        }

        return str_contains(mb_strtolower((string) $c->descricao), 'anual') ? 'Anuidade' : 'Mensalidade';
    }

    public function statusBloqueio(): JsonResponse
    {
        $oficina = Oficina::find(TenancyContext::get());
        if (!$oficina) {
            return response()->json(['suspensa' => false, 'voto_confianca_disponivel' => false]);
        }

        $this->conciliarEmAberto($oficina);
        $oficina->refresh();

        return response()->json($this->alertaService->statusBloqueio($oficina));
    }

    public function votoConfianca(): JsonResponse
    {
        $oficina = Oficina::findOrFail(TenancyContext::get());

        if ($oficina->status !== 'SUSPENSA') {
            return response()->json(['message' => 'Oficina não está suspensa.'], 422);
        }

        $cobranca = Cobranca::where('oficina_id', $oficina->id)
            ->where('tipo', 'ASSINATURA')
            ->where('status', 'VENCIDA')
            ->orderByDesc('vencimento')
            ->first();

        if (!$cobranca) {
            return response()->json(['message' => 'Nenhuma fatura vencida encontrada.'], 422);
        }

        if ($cobranca->voto_confianca_usado_em !== null) {
            return response()->json(['message' => 'Voto de confiança já utilizado para esta fatura.'], 422);
        }

        $dias = SaasConfig::get()->voto_confianca_dias;

        $oficina->update([
            'status'             => 'ATIVA',
            'voto_confianca_ate' => now()->addDays($dias)->toDateString(),
        ]);
        $cobranca->update(['voto_confianca_usado_em' => now()]);

        return response()->json([
            'message'            => "Seu acesso foi liberado por {$dias} dias em voto de confiança.",
            'voto_confianca_ate' => $oficina->voto_confianca_ate->toDateString(),
        ]);
    }
}
