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
                // SMTP — senha mascarada
                'smtp_host'            => $cfg->smtp_host,
                'smtp_port'            => $cfg->smtp_port,
                'smtp_username'        => $cfg->smtp_username,
                'smtp_password'        => SaasConfig::mascarar($cfg->getRawOriginal('smtp_password')),
                'smtp_encryption'      => $cfg->smtp_encryption,
                'smtp_from_address'    => $cfg->smtp_from_address,
                'smtp_from_name'       => $cfg->smtp_from_name,
                'smtp_ativo'           => (bool) $cfg->smtp_ativo,
            ],
        ]);
    }

    public function updateSmtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'smtp_host'         => ['required', 'string', 'max:150'],
            'smtp_port'         => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_username'     => ['nullable', 'string', 'max:150'],
            'smtp_password'     => ['nullable', 'string', 'max:255'],
            'smtp_encryption'   => ['nullable', 'in:tls,ssl'],
            'smtp_from_address' => ['required', 'email', 'max:150'],
            'smtp_from_name'    => ['required', 'string', 'max:100'],
            'smtp_ativo'        => ['boolean'],
        ]);

        // Só sobrescreve a senha se não vier mascarada/vazia
        if (empty($validated['smtp_password']) || str_contains($validated['smtp_password'], '*')) {
            unset($validated['smtp_password']);
        }

        SaasConfig::get()->update($validated);

        return response()->json(['message' => 'Configurações SMTP salvas.']);
    }

    public function testarSmtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destinatario' => ['required', 'email'],
        ]);

        $resultado = app(\App\Services\EmailService::class)->enviarTeste($validated['destinatario']);

        return response()->json($resultado, $resultado['ok'] ? 200 : 422);
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
