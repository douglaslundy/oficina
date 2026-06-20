<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Servico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServicoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Servico::query();

        if ($request->has('ativo')) {
            $query->where('ativo', filter_var($request->ativo, FILTER_VALIDATE_BOOLEAN));
        }

        $all     = $query->orderBy('nome')->get();
        $perPage = (int) ($request->per_page ?? 20);
        $page    = (int) ($request->page ?? 1);
        $total   = $all->count();
        $items   = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $items->map(fn($s) => [
                'id'           => $s->id,
                'nome'         => $s->nome,
                'valor_padrao' => (float) $s->valor_padrao,
                'ativo'        => $s->ativo,
            ]),
            'meta' => ['total' => $total, 'per_page' => $perPage, 'current_page' => $page],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'         => ['required', 'string', 'max:120'],
            'valor_padrao' => ['required', 'numeric', 'min:0'],
        ]);

        $servico = Servico::create($validated);

        return response()->json([
            'data' => [
                'id'           => $servico->id,
                'nome'         => $servico->nome,
                'valor_padrao' => (float) $servico->valor_padrao,
                'ativo'        => $servico->ativo,
            ],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $servico = Servico::findOrFail($id);

        $validated = $request->validate([
            'nome'         => ['sometimes', 'string', 'max:120'],
            'valor_padrao' => ['sometimes', 'numeric', 'min:0'],
            'ativo'        => ['sometimes', 'boolean'],
        ]);

        $servico->update($validated);

        return response()->json([
            'data' => [
                'id'           => $servico->id,
                'nome'         => $servico->nome,
                'valor_padrao' => (float) $servico->valor_padrao,
                'ativo'        => $servico->ativo,
            ],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $servico = Servico::findOrFail($id);
        $servico->update(['ativo' => false]);
        return response()->json(['message' => 'Serviço desativado.']);
    }
}
