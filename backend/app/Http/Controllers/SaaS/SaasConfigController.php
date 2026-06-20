<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\SaasConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaasConfigController extends Controller
{
    public function show(): JsonResponse
    {
        $cfg = SaasConfig::get();

        return response()->json([
            'data' => [
                'gateway_preferido'    => $cfg->gateway_preferido,
                'mp_ambiente'          => $cfg->mp_ambiente,
                // Chaves mascaradas — nunca expõe o valor real
                'asaas_api_key'        => SaasConfig::mascarar($cfg->getRawOriginal('asaas_api_key')),
                'asaas_webhook_token'  => SaasConfig::mascarar($cfg->getRawOriginal('asaas_webhook_token')),
                'mp_access_token'      => SaasConfig::mascarar($cfg->getRawOriginal('mp_access_token')),
                'mp_public_key'        => SaasConfig::mascarar($cfg->getRawOriginal('mp_public_key')),
                'mp_webhook_secret'    => SaasConfig::mascarar($cfg->getRawOriginal('mp_webhook_secret')),
            ],
        ]);
    }

    public function updateGateway(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway_preferido' => ['required', 'in:ASAAS,MERCADOPAGO'],
        ]);

        SaasConfig::get()->update($validated);

        return response()->json(['message' => 'Gateway atualizado.', 'data' => ['gateway_preferido' => $validated['gateway_preferido']]]);
    }

    public function updateAsaas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asaas_api_key'       => ['required', 'string', 'min:10'],
            'asaas_webhook_token' => ['required', 'string', 'min:6'],
        ]);

        SaasConfig::get()->update($validated);

        return response()->json(['message' => 'Configurações Asaas salvas.']);
    }

    public function updateMercadoPago(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mp_access_token'   => ['required', 'string', 'min:10'],
            'mp_public_key'     => ['required', 'string', 'min:10'],
            'mp_webhook_secret' => ['required', 'string', 'min:6'],
            'mp_ambiente'       => ['required', 'in:sandbox,producao'],
        ]);

        SaasConfig::get()->update($validated);

        return response()->json(['message' => 'Configurações Mercado Pago salvas.']);
    }
}
