<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WhatsAppConfig;
use App\Services\AlertaDispatchService;
use App\Services\WhatsAppService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppConfigController extends Controller
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly AlertaDispatchService $alertaDispatch,
    ) {}

    public function show(): JsonResponse
    {
        $cfg = WhatsAppConfig::first();
        if (!$cfg) {
            return response()->json(['data' => null]);
        }
        return response()->json(['data' => $cfg->mascarado()]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'evolution_url'     => ['required', 'url', 'max:255'],
            'evolution_api_key' => ['required', 'string', 'min:6'],
            'instance_name'     => ['required', 'string', 'max:100'],
            'instance_token'    => ['nullable', 'string'],
            'ativo'             => ['boolean'],
        ]);

        $oficinaId = TenancyContext::get();

        $cfg = WhatsAppConfig::firstOrNew(['oficina_id' => $oficinaId]);

        // Só atualiza campos sensíveis se não forem mascarados
        if (!str_contains($validated['evolution_api_key'], '*')) {
            $cfg->evolution_api_key = $validated['evolution_api_key'];
        }
        if (!empty($validated['instance_token']) && !str_contains((string)$validated['instance_token'], '*')) {
            $cfg->instance_token = $validated['instance_token'];
        }

        $cfg->evolution_url  = $validated['evolution_url'];
        $cfg->instance_name  = $validated['instance_name'];
        $cfg->ativo          = (bool)($validated['ativo'] ?? false);
        $cfg->oficina_id     = $oficinaId;
        $cfg->save();

        // Garante alertas pré-definidos para este tenant
        $this->alertaDispatch->garantirAlertasPreDefinidos($oficinaId);

        return response()->json(['data' => $cfg->mascarado(), 'message' => 'Configuração salva.']);
    }

    public function testarConexao(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'evolution_url'     => ['required', 'url'],
            'evolution_api_key' => ['required', 'string'],
            'instance_name'     => ['required', 'string'],
        ]);

        // Se vier mascarado, usa o salvo no banco
        $cfg = WhatsAppConfig::first();
        $apiKey = str_contains($validated['evolution_api_key'], '*')
            ? ($cfg?->getRawOriginal('evolution_api_key') ?? '')
            : $validated['evolution_api_key'];

        $resultado = $this->whatsApp->testarConexao(
            $validated['evolution_url'],
            $apiKey,
            $validated['instance_name'],
        );

        return response()->json($resultado, $resultado['ok'] ? 200 : 422);
    }

    public function statusInstancia(): JsonResponse
    {
        return response()->json($this->whatsApp->statusInstancia());
    }

    public function qrCode(): JsonResponse
    {
        $qr = $this->whatsApp->qrCode();
        if (!$qr) {
            return response()->json(['message' => 'Não foi possível obter o QR code. Verifique a configuração.'], 422);
        }
        return response()->json(['qrcode' => $qr]);
    }
}
