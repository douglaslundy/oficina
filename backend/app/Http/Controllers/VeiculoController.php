<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Veiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VeiculoController extends Controller
{
    public function index(string $clienteId): JsonResponse
    {
        $veiculos = Veiculo::where('cliente_id', $clienteId)
            ->orderBy('criado_em')
            ->get();

        return response()->json($veiculos->map(fn($v) => $this->shape($v)));
    }

    public function store(Request $request, string $clienteId): JsonResponse
    {
        $validated = $request->validate([
            'modelo' => ['required', 'string', 'max:80'],
            'ano'    => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'placa'  => ['nullable', 'string', 'max:10'],
            'chassi' => ['nullable', 'string', 'max:20'],
        ]);

        $veiculo = Veiculo::create(array_merge($validated, [
            'cliente_id' => $clienteId,
        ]));

        return response()->json($this->shape($veiculo->fresh()), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $veiculo = Veiculo::findOrFail($id);

        $validated = $request->validate([
            'modelo' => ['sometimes', 'required', 'string', 'max:80'],
            'ano'    => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'placa'  => ['nullable', 'string', 'max:10'],
            'chassi' => ['nullable', 'string', 'max:20'],
            'ativo'  => ['nullable', 'boolean'],
        ]);

        $veiculo->update($validated);

        return response()->json($this->shape($veiculo->fresh()));
    }

    public function destroy(string $id): JsonResponse
    {
        Veiculo::findOrFail($id)->delete();

        return response()->json(['message' => 'Veículo removido.']);
    }

    private function shape(Veiculo $v): array
    {
        return [
            'id'        => $v->id,
            'modelo'    => $v->modelo,
            'ano'       => $v->ano,
            'placa'     => $v->placa,
            'chassi'    => $v->chassi,
            'ativo'     => $v->ativo,
            'criado_em' => $v->criado_em?->format('d/m/Y'),
        ];
    }
}
