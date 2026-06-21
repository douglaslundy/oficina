<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AlertaConfig;
use App\Models\Oficina;
use App\Services\AlertaDispatchService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AlertaConfigController extends Controller
{
    public function __construct(private readonly AlertaDispatchService $dispatch) {}

    /** Canais disponíveis para a oficina atual (plano OU grant avulso). */
    private function canaisPermitidos(): array
    {
        $oficinaId = (string) TenancyContext::get();
        $ent = app(\App\Services\EntitlementService::class);

        return [
            'WHATSAPP' => $ent->disponivel($oficinaId, 'ALERTA_WHATSAPP'),
            'EMAIL'    => $ent->disponivel($oficinaId, 'ALERTA_EMAIL'),
        ];
    }

    /** Garante que todos os canais pedidos fazem parte do plano. */
    private function validarCanais(array $canais): void
    {
        $permitidos = $this->canaisPermitidos();
        foreach ($canais as $canal) {
            if (empty($permitidos[$canal])) {
                throw ValidationException::withMessages([
                    'canais' => 'Funcionalidade não faz parte do seu plano. Contate o administrador do seu plano e contrate.',
                ]);
            }
        }
    }

    public function index(): JsonResponse
    {
        $oficinaId = TenancyContext::get();

        // Garante que os pré-definidos existam
        $this->dispatch->garantirAlertasPreDefinidos($oficinaId);

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
            'emails'            => ['array'],
            'emails.*'          => ['email'],
            'canais'            => ['array', 'min:1'],
            'canais.*'          => ['in:WHATSAPP,EMAIL'],
            'enviar_cliente'    => ['boolean'],
            'enviar_mecanico'   => ['boolean'],
        ]);

        $canais = $validated['canais'] ?? ['WHATSAPP'];
        $this->validarCanais($canais);

        $alerta = AlertaConfig::create([
            ...$validated,
            'canais'       => $canais,
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
            'emails'            => ['sometimes', 'array'],
            'emails.*'          => ['email'],
            'canais'            => ['sometimes', 'array', 'min:1'],
            'canais.*'          => ['in:WHATSAPP,EMAIL'],
            'enviar_cliente'    => ['sometimes', 'boolean'],
            'enviar_mecanico'   => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['canais'])) {
            $this->validarCanais($validated['canais']);
        }

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
