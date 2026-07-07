<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Veiculo;
use App\Models\VeiculoProprietario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        if (!empty($validated['placa'])) {
            $normalizada = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $validated['placa']));
            $duplicado = Veiculo::where('ativo', true)
                ->whereRaw("REPLACE(REPLACE(UPPER(placa), '-', ''), ' ', '') = ?", [$normalizada])
                ->exists();

            if ($duplicado) {
                return response()->json([
                    'message' => 'Já existe um veículo cadastrado com esta placa. Use a opção Transferir no veículo existente para trocar o proprietário.',
                ], 422);
            }
        }

        $veiculo = DB::transaction(function () use ($validated, $clienteId) {
            $veiculo = Veiculo::create(array_merge($validated, [
                'cliente_id' => $clienteId,
            ]));

            VeiculoProprietario::create([
                'veiculo_id'  => $veiculo->id,
                'cliente_id'  => $clienteId,
                'data_inicio' => now(),
                'data_fim'    => null,
            ]);

            return $veiculo;
        });

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
