<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\SaasConfig;
use App\Services\WhatsAppService;
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
                'asaas_api_key'        => SaasConfig::mascarar($cfg->getRawOriginal('asaas_api_key')),
                'asaas_webhook_token'  => SaasConfig::mascarar($cfg->getRawOriginal('asaas_webhook_token')),
                'mp_access_token'      => SaasConfig::mascarar($cfg->getRawOriginal('mp_access_token')),
                'mp_public_key'        => SaasConfig::mascarar($cfg->getRawOriginal('mp_public_key')),
                'mp_webhook_secret'    => SaasConfig::mascarar($cfg->getRawOriginal('mp_webhook_secret')),
                'evolution_url'        => $cfg->evolution_url,
                'evolution_api_key'    => SaasConfig::mascarar($cfg->getRawOriginal('evolution_api_key')),
                'provedor_fiscal_padrao'         => $cfg->provedor_fiscal_padrao,
                'emissao_fiscal_modo_padrao'     => $cfg->emissao_fiscal_modo_padrao,
                'spedy_master_key_sandbox'       => SaasConfig::mascarar($cfg->getRawOriginal('spedy_master_key_sandbox')),
                'spedy_master_key_producao'      => SaasConfig::mascarar($cfg->getRawOriginal('spedy_master_key_producao')),
                'focus_master_token_homologacao' => SaasConfig::mascarar($cfg->getRawOriginal('focus_master_token_homologacao')),
                'focus_master_token_producao'    => SaasConfig::mascarar($cfg->getRawOriginal('focus_master_token_producao')),
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

    public function showEvolution(): JsonResponse
    {
        $cfg = SaasConfig::get();

        return response()->json([
            'data' => [
                'evolution_url'     => $cfg->evolution_url,
                'evolution_api_key' => SaasConfig::mascarar($cfg->getRawOriginal('evolution_api_key')),
            ],
        ]);
    }

    public function updateEvolution(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'evolution_url'     => ['required', 'url', 'max:255'],
            'evolution_api_key' => ['nullable', 'string', 'min:6', 'max:500'],
        ]);

        $cfg = SaasConfig::get();
        $cfg->evolution_url = $validated['evolution_url'];

        if (!empty($validated['evolution_api_key']) && !str_contains($validated['evolution_api_key'], '*')) {
            $cfg->evolution_api_key = $validated['evolution_api_key'];
        }

        $cfg->save();

        return response()->json(['message' => 'Configurações da Evolution API salvas.']);
    }

    public function testarEvolution(): JsonResponse
    {
        $cfg = SaasConfig::get();
        $url    = $cfg->getRawOriginal('evolution_url') ?? '';
        $apiKey = $cfg->getRawOriginal('evolution_api_key') ?? '';

        if (empty($url) || empty($apiKey)) {
            return response()->json(['ok' => false, 'error' => 'URL e API Key precisam ser configuradas antes de testar.'], 422);
        }

        $resultado = app(WhatsAppService::class)->testarConexao($url, $apiKey);

        return response()->json($resultado, 200);
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

    public function updateProvedorFiscal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provedor_fiscal_padrao'     => ['required', 'in:SPEDY,FOCUS'],
            'emissao_fiscal_modo_padrao' => ['required', 'in:MANUAL,AUTOMATICO'],
        ]);
        SaasConfig::get()->update($validated);
        return response()->json(['message' => 'Provedor fiscal padrão atualizado.', 'data' => $validated]);
    }

    public function updateSpedy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'spedy_master_key_sandbox'  => ['nullable', 'string', 'min:8'],
            'spedy_master_key_producao' => ['nullable', 'string', 'min:8'],
        ]);
        $this->salvarSegredos($validated, ['spedy_master_key_sandbox', 'spedy_master_key_producao']);
        return response()->json(['message' => 'Credenciais Spedy salvas.']);
    }

    public function updateFocus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'focus_master_token_homologacao' => ['nullable', 'string', 'min:8'],
            'focus_master_token_producao'    => ['nullable', 'string', 'min:8'],
        ]);
        $this->salvarSegredos($validated, ['focus_master_token_homologacao', 'focus_master_token_producao']);
        return response()->json(['message' => 'Credenciais Focus NFe salvas.']);
    }

    /** Cifra e salva segredos; ignora valores vazios ou ainda mascarados. */
    private function salvarSegredos(array $validated, array $campos): void
    {
        $cfg = SaasConfig::get();
        foreach ($campos as $campo) {
            $valor = $validated[$campo] ?? null;
            if (empty($valor) || str_contains($valor, '*')) {
                continue;
            }
            $cfg->{$campo} = \Illuminate\Support\Facades\Crypt::encryptString($valor);
        }
        $cfg->save();
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
