<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AlertaConfig;
use App\Services\AlertaDispatchService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertaConfigController extends Controller
{
    public function __construct(private readonly AlertaDispatchService $dispatch) {}

    public function index(): JsonResponse
    {
        $oficinaId = TenancyContext::get();

        // Garante que os pré-definidos existam
        $this->dispatch->garantirAlertasPreDefinidos($oficina_id);

        $alertas = AlertaConfig::orderByRaw("pre_definido DESC, criado_em ASC")->get();

        return response()->json(['data' => $alertas]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo'              => ['required', 'string', 'max:50'],
            'nome'              => ['required', 'string', 'max:120'],
            'template_mensagem' => ['required', 'string'],
            'destinatarios'     => ['array'],
            'destinatarios.*'   => ['string'],
            'enviar_cliente'    => ['boolean'],
            'enviar_mecanico'   => ['boolean'],
        ]);

        $alerta = AlertaConfig::create([
            ...$validated,
            'oficina_id'   => TenancyContext::get(),
            'pre_definido' => false,
            'ativo'        => true,
        ]);

        return response()->json(['data' => $alerta], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $alerta = AlertaConfig::findOrFail($id);

        $validated = $request->validate([
            'nome'              => ['sometimes', 'string', 'max:120'],
            'template_mensagem' => ['sometimes', 'string'],
            'destinatarios'     => ['sometimes', 'array'],
            'destinatarios.*'   => ['string'],
            'enviar_cliente'    => ['sometimes', 'boolean'],
            'enviar_mecanico'   => ['sometimes', 'boolean'],
        ]);

        $alerta->update($validated);

        return response()->json(['data' => $alerta]);
    }

    public function toggle(string $id): JsonResponse
    {
        $alerta = AlertaConfig::findOrFail($id);
        $alerta->update(['ativo' => !$alerta->ativo]);

        $estado = $alerta->ativo ? 'ativado' : 'desativado';
        return response()->json(['data' => $alerta, 'message' => "Alerta {$estado}."]);
    }

    public function destroy(string $id): JsonResponse
    {
        $alerta = AlertaConfig::findOrFail($id);

        if ($alerta->pre_definido) {
            return response()->json(['message' => 'Alertas pré-definidos não podem ser removidos.'], 400);
        }

        $alerta->delete();
        return response()->json(['message' => 'Alerta removido.']);
    }
}
