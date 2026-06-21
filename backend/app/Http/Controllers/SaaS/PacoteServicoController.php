<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\PacoteServico;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PacoteServicoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => PacoteServico::orderBy('servico')->orderBy('nome')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $pacote = PacoteServico::create($this->validatePayload($request));
        return response()->json(['message' => 'Pacote criado.', 'data' => $pacote], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pacote = PacoteServico::findOrFail($id);
        $pacote->update($this->validatePayload($request));
        return response()->json(['message' => 'Pacote atualizado.', 'data' => $pacote]);
    }

    public function destroy(string $id): JsonResponse
    {
        PacoteServico::findOrFail($id)->delete();
        return response()->json(['message' => 'Pacote removido.']);
    }

    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'nome'         => ['required', 'string', 'max:100'],
            'servico'      => ['required', Rule::in(EntitlementService::SERVICOS)],
            'quantidade'   => ['required', 'integer', 'min:-1'],
            'valor'        => ['required', 'numeric', 'min:0'],
            'recorrente'   => ['boolean'],
            'periodo_dias' => ['nullable', 'integer', 'min:1', 'required_if:recorrente,false'],
            'ativo'        => ['boolean'],
        ]);

        if (!empty($validated['recorrente'])) {
            $validated['periodo_dias'] = null;
        }

        return $validated;
    }
}
