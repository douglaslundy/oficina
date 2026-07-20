<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Cobranca;
use App\Models\Oficina;
use App\Services\AsaasService;
use App\Services\MercadoPagoService;
use App\Services\PagamentoReconciliacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrancaController extends Controller
{
    public function __construct(
        private readonly AsaasService $asaas,
        private readonly MercadoPagoService $mercadoPago,
        private readonly PagamentoReconciliacaoService $reconciliacao,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Cobranca::with('oficina')
            ->orderBy('vencimento', 'desc');

        if ($request->filled('oficina_id')) {
            $query->where('oficina_id', $request->query('oficina_id'));
        }

        if ($request->filled('mes')) {
            $query->where('mes_referencia', 'like', $request->query('mes') . '%');
        }

        $cobrancas = $query->paginate(15);

        $data = $cobrancas->getCollection()->map(fn (Cobranca $c) => $this->formatCobranca($c));

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $cobrancas->total(),
                'per_page'     => $cobrancas->perPage(),
                'current_page' => $cobrancas->currentPage(),
            ],
        ]);
    }

    public function byOficina(string $oficina_id): JsonResponse
    {
        Oficina::findOrFail($oficina_id);

        $cobrancas = Cobranca::with('oficina')
            ->where('oficina_id', $oficina_id)
            ->orderBy('vencimento', 'desc')
            ->paginate(15);

        $data = $cobrancas->getCollection()->map(fn (Cobranca $c) => $this->formatCobranca($c));

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $cobrancas->total(),
                'per_page'     => $cobrancas->perPage(),
                'current_page' => $cobrancas->currentPage(),
            ],
        ]);
    }

    public function cancelar(string $id): JsonResponse
    {
        $cobranca = Cobranca::findOrFail($id);

        if ($cobranca->status === 'PAGA') {
            return response()->json(['message' => 'Não é possível cancelar uma cobrança já paga. Use "Estornar" se o pagamento precisa ser desfeito.'], 422);
        }

        if ($cobranca->gateway === 'MERCADOPAGO') {
            // Cobrança avulsa no Mercado Pago é um link de pagamento (preference) sem
            // pagamento efetivado ainda — não há o que cancelar na API, só localmente.
        } elseif ($cobranca->asaas_payment_id) {
            try {
                $this->asaas->cancelarPagamento($cobranca->asaas_payment_id);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Falha ao cancelar no Asaas: ' . $e->getMessage()], 502);
            }
        }

        $cobranca->delete();

        return response()->json(['message' => 'Cobrança cancelada com sucesso.']);
    }

    /**
     * Estorna uma cobrança já paga direto no gateway. Não desfaz efeitos
     * locais automáticos (avanço de vencimento, reativação da oficina) —
     * são raros e o admin deve revisar manualmente o estado da assinatura
     * se necessário, já que desfazer com segurança exigiria saber se houve
     * pagamentos posteriores etc.
     */
    public function estornar(string $id): JsonResponse
    {
        $cobranca = Cobranca::findOrFail($id);

        if ($cobranca->status !== 'PAGA') {
            return response()->json(['message' => 'Só é possível estornar uma cobrança que já foi paga.'], 422);
        }

        $paymentId = $cobranca->gateway === 'MERCADOPAGO' ? $cobranca->mp_payment_id : $cobranca->asaas_payment_id;
        if (!$paymentId) {
            return response()->json(['message' => 'Cobrança sem ID de pagamento no gateway — não é possível estornar automaticamente.'], 422);
        }

        try {
            if ($cobranca->gateway === 'MERCADOPAGO') {
                $this->mercadoPago->estornarPagamento($paymentId);
            } else {
                $this->asaas->estornarPagamento($paymentId);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Falha ao estornar no gateway: ' . $e->getMessage()], 502);
        }

        $cobranca->update(['status' => 'ESTORNADA']);

        return response()->json([
            'message' => 'Pagamento estornado com sucesso. Se essa cobrança tinha avançado o vencimento ou reativado a oficina, revise manualmente em "Cobrança Recorrente" na tela da oficina.',
        ]);
    }

    /**
     * Concilia cobranças PENDENTE/VENCIDA que já têm um payment_id contra o
     * status real no gateway — não depende do webhook ter chegado (pode
     * atrasar, falhar, ou nunca ter sido configurado). Filtra por oficina se
     * `oficina_id` vier na query.
     */
    public function conciliar(Request $request): JsonResponse
    {
        $query = Cobranca::whereIn('status', ['PENDENTE', 'VENCIDA'])
            ->where(function ($q) {
                $q->whereNotNull('mp_payment_id')->orWhereNotNull('asaas_payment_id');
            });

        if ($request->filled('oficina_id')) {
            $query->where('oficina_id', $request->query('oficina_id'));
        }

        $cobrancas = $query->get();

        $verificadas = 0;
        $conciliadas = 0;

        foreach ($cobrancas as $cobranca) {
            $verificadas++;
            if ($this->reconciliacao->verificarEConciliar($cobranca)) {
                $conciliadas++;
            }
        }

        return response()->json([
            'message'     => "Conciliação concluída: {$conciliadas} de {$verificadas} cobrança(s) pendente(s) confirmada(s) como paga(s).",
            'verificadas' => $verificadas,
            'conciliadas' => $conciliadas,
        ]);
    }

    private function formatCobranca(Cobranca $cobranca): array
    {
        return [
            'id'               => $cobranca->id,
            'oficina'          => $cobranca->oficina ? [
                'id'   => $cobranca->oficina->id,
                'nome' => $cobranca->oficina->nome,
            ] : null,
            'mes_referencia'   => $cobranca->mes_referencia?->toDateString(),
            'valor'            => number_format((float) $cobranca->valor, 2, '.', ''),
            'status'           => $cobranca->status,
            'vencimento'       => $cobranca->vencimento?->toDateString(),
            'pago_em'          => $cobranca->pago_em?->toIso8601String(),
            'gateway'          => $cobranca->gateway,
            'asaas_payment_id' => $cobranca->asaas_payment_id,
            'mp_payment_id'    => $cobranca->mp_payment_id,
        ];
    }
}
