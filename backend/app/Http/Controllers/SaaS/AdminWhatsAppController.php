<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\SaasConfig;
use App\Services\AdminWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp da PLATAFORMA — instância própria (não de uma oficina), usada só
 * pra notificar o admin do SaaS de eventos internos (ex: pagamento
 * recebido). Ver App\Services\AdminWhatsAppService.
 */
class AdminWhatsAppController extends Controller
{
    public function __construct(private readonly AdminWhatsAppService $whatsApp) {}

    public function show(): JsonResponse
    {
        $cfg = SaasConfig::get();

        return response()->json([
            'data' => [
                'numero'          => $cfg->whatsapp_admin_numero,
                'ativo'           => (bool) $cfg->whatsapp_admin_ativo,
                'instance_name'   => $cfg->whatsapp_admin_instance,
            ],
            'credenciais_ok' => $this->whatsApp->credenciaisConfiguradas(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'numero' => ['nullable', 'string', 'min:8', 'max:20'],
            'ativo'  => ['boolean'],
        ]);

        SaasConfig::get()->update([
            'whatsapp_admin_numero' => $validated['numero'] ?? null,
            'whatsapp_admin_ativo'  => (bool) ($validated['ativo'] ?? false),
        ]);

        return response()->json(['message' => 'Configuração de WhatsApp do admin salva.']);
    }

    public function statusInstancia(): JsonResponse
    {
        return response()->json($this->whatsApp->statusInstancia());
    }

    public function qrCode(): JsonResponse
    {
        $r = $this->whatsApp->qrCode();
        if (empty($r['qrcode'])) {
            return response()->json(['message' => $r['error'] ?? 'Não foi possível obter o QR code.'], 422);
        }
        return response()->json(['qrcode' => $r['qrcode']]);
    }

    public function desconectar(): JsonResponse
    {
        $r = $this->whatsApp->desconectar();
        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function enviarTeste(): JsonResponse
    {
        $r = $this->whatsApp->enviarTeste();
        return response()->json($r, $r['ok'] ? 200 : 422);
    }
}
