<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Cobranca;
use App\Models\Oficina;
use App\Services\AsaasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrancaController extends Controller
{
    public function __construct(private AsaasService $asaas) {}
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
            return response()->json(['message' => 'Não é possível cancelar uma cobrança já paga.'], 422);
        }

        if ($cobranca->asaas_payment_id) {
            try {
                $this->asaas->cancelarPagamento($cobranca->asaas_payment_id);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Falha ao cancelar no Asaas: ' . $e->getMessage()], 502);
            }
        }

        $cobranca->delete();

        return response()->json(['message' => 'Cobrança cancelada com sucesso.']);
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
            'asaas_payment_id' => $cobranca->asaas_payment_id,
        ];
    }
}
