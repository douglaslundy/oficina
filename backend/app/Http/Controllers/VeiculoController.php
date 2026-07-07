<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OrdemServico;
use App\Models\Veiculo;
use App\Models\VeiculoProprietario;
use App\Tenancy\TenancyContext;
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

        try {
            $veiculo = DB::transaction(function () use ($validated, $clienteId) {
                if (!empty($validated['placa'])) {
                    $normalizada = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $validated['placa']));

                    // Lock por oficina + placa normalizada: serializa requisições
                    // concorrentes para a mesma placa dentro da mesma oficina, sem
                    // impedir que oficinas (tenants) diferentes usem a mesma placa
                    // simultaneamente. A chave do lock inclui o tenant justamente
                    // para que oficinas distintas nunca disputem o mesmo lock.
                    $oficinaId = TenancyContext::get() ?? 'sem-oficina';
                    DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32($oficinaId . '|' . $normalizada)]);

                    $duplicado = Veiculo::where('ativo', true)
                        ->whereRaw("REPLACE(REPLACE(UPPER(placa), '-', ''), ' ', '') = ?", [$normalizada])
                        ->exists();

                    if ($duplicado) {
                        throw new \RuntimeException('Já existe um veículo cadastrado com esta placa. Use a opção Transferir no veículo existente para trocar o proprietário.');
                    }
                }

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
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

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

    public function buscar(Request $request): JsonResponse
    {
        $placa = (string) $request->query('placa', '');
        $normalizada = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placa));

        if ($normalizada === '') {
            return response()->json([]);
        }

        $veiculos = Veiculo::with('cliente')
            ->whereRaw("REPLACE(REPLACE(UPPER(placa), '-', ''), ' ', '') LIKE ?", ["%{$normalizada}%"])
            ->orderBy('criado_em', 'desc')
            ->limit(10)
            ->get();

        return response()->json($veiculos->map(fn($v) => [
            'id'           => $v->id,
            'placa'        => $v->placa,
            'modelo'       => $v->modelo,
            'ano'          => $v->ano,
            'ativo'        => $v->ativo,
            'cliente_id'   => $v->cliente_id,
            'cliente_nome' => $v->cliente?->nome,
        ]));
    }

    public function show(string $id): JsonResponse
    {
        $veiculo = Veiculo::findOrFail($id);

        $proprietarioAtual = VeiculoProprietario::with('cliente')
            ->where('veiculo_id', $veiculo->id)
            ->whereNull('data_fim')
            ->first();

        $clienteAtual = $proprietarioAtual?->cliente ?? $veiculo->cliente;

        $historicoProprietarios = VeiculoProprietario::with('cliente')
            ->where('veiculo_id', $veiculo->id)
            ->orderBy('data_inicio', 'desc')
            ->get()
            ->map(fn($p) => [
                'cliente_id'   => $p->cliente_id,
                'cliente_nome' => $p->cliente?->nome,
                'data_inicio'  => $p->data_inicio?->format('d/m/Y'),
                'data_fim'     => $p->data_fim?->format('d/m/Y'),
            ]);

        $historicoOs = OrdemServico::with('mecanico')
            ->where('veiculo_id', $veiculo->id)
            ->where('status', '!=', 'CANCELADA')
            ->orderBy('criado_em', 'desc')
            ->get()
            ->map(fn($os) => [
                'id'          => $os->id,
                'numero'      => $os->numero,
                'tipo'        => $os->tipo,
                'status'      => $os->status,
                'valor_total' => $os->valor_total,
                'valor_pago'  => $os->valor_pago,
                'mecanico'    => $os->mecanico?->nome,
                'criado_em'   => $os->criado_em?->format('d/m/Y'),
            ]);

        return response()->json([
            'id'     => $veiculo->id,
            'modelo' => $veiculo->modelo,
            'ano'    => $veiculo->ano,
            'placa'  => $veiculo->placa,
            'chassi' => $veiculo->chassi,
            'ativo'  => $veiculo->ativo,
            'proprietario_atual' => $clienteAtual ? [
                'id'       => $clienteAtual->id,
                'nome'     => $clienteAtual->nome,
                'telefone' => $clienteAtual->telefone,
            ] : null,
            'historico_proprietarios' => $historicoProprietarios,
            'historico_os'            => $historicoOs,
            'resumo' => [
                'total_os'          => $historicoOs->count(),
                'valor_total_gasto' => $historicoOs->sum('valor_pago'),
                'ultima_visita'     => $historicoOs->first()['criado_em'] ?? null,
            ],
        ]);
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
