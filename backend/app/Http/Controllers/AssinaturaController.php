<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Oficina;
use App\Services\AssinaturaAlertaService;
use App\Services\AssinaturaService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssinaturaController extends Controller
{
    public function __construct(
        private readonly AssinaturaAlertaService $alertaService,
        private readonly AssinaturaService $assinaturaService,
    ) {}

    public function alerta(): JsonResponse
    {
        $oficina = Oficina::find(TenancyContext::get());
        if (!$oficina) {
            return response()->json(['show' => false]);
        }

        return response()->json($this->alertaService->status($oficina));
    }

    public function mudarCiclo(Request $request): JsonResponse
    {
        $validated = $request->validate(['ciclo' => 'required|in:MENSAL,ANUAL']);
        $oficina   = Oficina::findOrFail(TenancyContext::get());

        $this->assinaturaService->mudarCiclo($oficina, $validated['ciclo']);

        return response()->json(['message' => 'Ciclo de cobrança atualizado.', 'data' => [
            'ciclo_cobranca'     => $oficina->ciclo_cobranca,
            'proximo_vencimento' => $oficina->proximo_vencimento->toDateString(),
        ]]);
    }
}
