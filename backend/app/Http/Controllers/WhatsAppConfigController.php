<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Oficina;
use App\Models\SaasConfig;
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

        return response()->json([
            'data' => $cfg ? [
                'instance_name'  => $cfg->instance_name,
                'ativo'          => $cfg->ativo,
            ] : null,
            'credenciais_ok' => $this->whatsApp->credenciaisConfiguradas(),
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ativo' => ['boolean'],
        ]);

        $oficinaId = TenancyContext::get();

        $cfg = WhatsAppConfig::firstOrNew(['oficina_id' => $oficinaId]);
        $cfg->ativo      = (bool)($validated['ativo'] ?? $cfg->ativo ?? false);
        $cfg->oficina_id = $oficinaId;

        if (empty($cfg->instance_name)) {
            $slug = Oficina::find($oficinaId)?->slug ?? substr($oficinaId ?? '', 0, 8);
            $cfg->instance_name = 'mec-' . $slug;
        }

        $cfg->save();

        $this->alertaDispatch->garantirAlertasPreDefinidos($oficinaId);

        return response()->json([
            'data'    => ['instance_name' => $cfg->instance_name, 'ativo' => $cfg->ativo],
            'message' => 'Configuração salva.',
        ]);
    }

    public function statusInstancia(): JsonResponse
    {
        return response()->json($this->whatsApp->statusInstancia());
    }

    public function qrCode(): JsonResponse
    {
        $r = $this->whatsApp->qrCode();
        if (empty($r['qrcode'])) {
            return response()->json([
                'message' => $r['error'] ?? 'Não foi possível obter o QR code.',
            ], 422);
        }
        return response()->json(['qrcode' => $r['qrcode']]);
    }

    public function desconectar(): JsonResponse
    {
        $r = $this->whatsApp->desconectar();
        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function enviarTeste(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telefone' => ['required', 'string', 'min:8', 'max:20'],
        ]);

        $r = $this->whatsApp->enviarTeste($validated['telefone']);
        return response()->json($r, $r['ok'] ? 200 : 422);
    }
}
