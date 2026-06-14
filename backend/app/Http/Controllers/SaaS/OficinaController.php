<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Oficina;
use App\Models\OrdemServico;
use App\Models\Usuario;
use App\Services\TenantProvisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OficinaController extends Controller
{
    public function __construct(private TenantProvisionService $provisionService) {}

    public function index(Request $request): JsonResponse
    {
        $oficinas = Oficina::with('plano')
            ->orderBy('criado_em', 'desc')
            ->paginate(15);

        $inicioMes = Carbon::now()->startOfMonth();

        $items = $oficinas->getCollection()->map(function (Oficina $oficina) use ($inicioMes) {
            return $this->formatOficina($oficina, $inicioMes);
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $oficinas->total(),
                'per_page'     => $oficinas->perPage(),
                'current_page' => $oficinas->currentPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'        => 'required|string|max:150',
            'cnpj'        => ['required', 'string', new \App\Rules\Cnpj],
            'slug'        => 'required|string|max:60|unique:oficinas,slug|regex:/^[a-z0-9-]+$/',
            'plano_id'    => 'required|uuid|exists:planos,id',
            'admin_nome'  => 'required|string|max:120',
            'admin_email' => 'required|email|max:120',
            'admin_cpf'   => ['required', 'string', new \App\Rules\Cpf],
            'admin_senha' => 'nullable|string|min:8',
        ]);

        $oficina = $this->provisionService->provisionar($validated);

        $inicioMes = Carbon::now()->startOfMonth();

        return response()->json([
            'message' => 'Oficina provisionada com sucesso.',
            'data'    => $this->formatOficina($oficina, $inicioMes),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $oficina = Oficina::with('plano')->findOrFail($id);

        $inicioMes = Carbon::now()->startOfMonth();

        return response()->json([
            'data' => $this->formatOficina($oficina, $inicioMes),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);

        $validated = $request->validate([
            'nome'         => 'sometimes|string|max:150',
            'plano_id'     => 'sometimes|uuid|exists:planos,id',
            'status'       => 'sometimes|string|in:ATIVA,SUSPENSA,CANCELADA',
            'admin_email'  => 'sometimes|email|max:120',
        ]);

        $oficina->update($validated);
        $oficina->load('plano');

        $inicioMes = Carbon::now()->startOfMonth();

        return response()->json([
            'message' => 'Oficina atualizada com sucesso.',
            'data'    => $this->formatOficina($oficina, $inicioMes),
        ]);
    }

    public function suspender(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $oficina->update(['status' => 'SUSPENSA']);

        return response()->json([
            'message' => 'Oficina suspensa com sucesso.',
            'data'    => ['id' => $oficina->id, 'status' => 'SUSPENSA'],
        ]);
    }

    public function reativar(string $id): JsonResponse
    {
        $oficina = Oficina::findOrFail($id);
        $oficina->update(['status' => 'ATIVA']);

        return response()->json([
            'message' => 'Oficina reativada com sucesso.',
            'data'    => ['id' => $oficina->id, 'status' => 'ATIVA'],
        ]);
    }

    private function formatOficina(Oficina $oficina, Carbon $inicioMes): array
    {
        $usersCount = Usuario::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->count();

        $osMesCount = OrdemServico::withoutGlobalScopes()
            ->where('oficina_id', $oficina->id)
            ->where('criado_em', '>=', $inicioMes)
            ->count();

        return [
            'id'          => $oficina->id,
            'nome'        => $oficina->nome,
            'cnpj'        => $this->formatCnpj($oficina->cnpj),
            'slug'        => $oficina->slug,
            'status'      => $oficina->status,
            'plano'       => $oficina->plano ? [
                'id'           => $oficina->plano->id,
                'nome'         => $oficina->plano->nome,
                'preco_mensal' => number_format((float) $oficina->plano->preco_mensal, 2, '.', ''),
            ] : null,
            'users_count'  => $usersCount,
            'os_mes_count' => $osMesCount,
            'admin_email'  => $oficina->admin_email,
            'criado_em'    => $oficina->criado_em?->toIso8601String(),
        ];
    }

    private function formatCnpj(string $cnpj): string
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj);
        }
        return $cnpj;
    }
}
