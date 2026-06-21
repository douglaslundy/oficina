<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Plano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanoController extends Controller
{
    public function index(): JsonResponse
    {
        $planos = Plano::withCount('oficinas')
            ->orderBy('preco_mensal')
            ->get();

        $data = $planos->map(fn (Plano $plano) => $this->formatPlano($plano));

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'                  => 'required|string|max:60',
            'preco_mensal'          => 'required|numeric|min:0',
            'limite_usuarios'       => 'required|integer|min:-1',
            'limite_os_mes'         => 'required|integer|min:-1',
            'limite_produtos'       => 'nullable|integer|min:-1',
            'limite_clientes'       => 'nullable|integer|min:-1',
            'limite_notas_mes'      => 'nullable|integer|min:-1',
            'preco_nota_excedente'  => 'nullable|numeric|min:0',
            'alerta_whatsapp'       => 'sometimes|boolean',
            'alerta_email'          => 'sometimes|boolean',
        ]);

        $plano = Plano::create($validated);
        $plano->loadCount('oficinas');

        return response()->json([
            'message' => 'Plano criado com sucesso.',
            'data'    => $this->formatPlano($plano),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $plano = Plano::findOrFail($id);

        $validated = $request->validate([
            'nome'                  => 'required|string|max:60',
            'preco_mensal'          => 'required|numeric|min:0',
            'limite_usuarios'       => 'required|integer|min:-1',
            'limite_os_mes'         => 'required|integer|min:-1',
            'limite_produtos'       => 'nullable|integer|min:-1',
            'limite_clientes'       => 'nullable|integer|min:-1',
            'limite_notas_mes'      => 'nullable|integer|min:-1',
            'preco_nota_excedente'  => 'nullable|numeric|min:0',
            'alerta_whatsapp'       => 'sometimes|boolean',
            'alerta_email'          => 'sometimes|boolean',
            'ativo'                 => 'sometimes|boolean',
        ]);

        $plano->update($validated);
        $plano->loadCount('oficinas');

        return response()->json([
            'message' => 'Plano atualizado com sucesso.',
            'data'    => $this->formatPlano($plano),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $plano = Plano::findOrFail($id);

        $activePlansCount = Plano::where('ativo', true)->count();
        if ($plano->ativo && $activePlansCount <= 1) {
            return response()->json([
                'message' => 'Não é possível desativar o único plano ativo.',
            ], 422);
        }

        $plano->update(['ativo' => false]);

        return response()->json([
            'message' => 'Plano desativado com sucesso.',
        ]);
    }

    private function formatPlano(Plano $plano): array
    {
        return [
            'id'                   => $plano->id,
            'nome'                 => $plano->nome,
            'preco_mensal'         => number_format((float) $plano->preco_mensal, 2, '.', ''),
            'limite_usuarios'      => $plano->limite_usuarios,
            'limite_os_mes'        => $plano->limite_os_mes,
            'limite_produtos'      => $plano->limite_produtos,
            'limite_clientes'      => $plano->limite_clientes,
            'limite_notas_mes'     => $plano->limite_notas_mes,
            'preco_nota_excedente' => number_format((float) $plano->preco_nota_excedente, 2, '.', ''),
            'alerta_whatsapp'      => (bool) $plano->alerta_whatsapp,
            'alerta_email'         => (bool) $plano->alerta_email,
            'ativo'                => $plano->ativo,
            'oficinas_count'       => $plano->oficinas_count ?? 0,
        ];
    }
}
