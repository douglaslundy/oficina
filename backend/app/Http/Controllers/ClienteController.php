<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ClienteResource;
use App\Models\Cliente;
use App\Rules\Cnpj;
use App\Rules\Cpf;
use App\Services\ClienteStatusService;
use App\Services\PlanLimitService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClienteController extends Controller
{
    public function __construct(private readonly ClienteStatusService $clienteStatusService) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        // Recalcular clientes DEVEDOR que podem ter vencido desde o último acesso
        $devedores = Cliente::whereIn('status', ['DEVEDOR'])->pluck('id');
        foreach ($devedores as $id) {
            $this->clienteStatusService->recalcular($id);
        }
        $query = Cliente::with('veiculos');

        if ($request->has('status')) {
            $statuses = explode(',', (string)$request->status);
            $query->whereIn('status', $statuses);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nome', 'ilike', "%{$search}%")
                  ->orWhere('cpf_cnpj', 'like', "%{$search}%")
                  ->orWhere('veiculo_placa', 'ilike', "%{$search}%");
            });
        }
        if ($request->has('count')) {
            return response()->json(['total' => $query->count()]);
        }

        return ClienteResource::collection(
            $query->orderByRaw("status = 'DEVEDOR' DESC, nome ASC")->paginate(20)
        );
    }

    public function store(Request $request, PlanLimitService $planLimit): JsonResponse
    {
        $planLimit->verificarLimiteClientes();

        $cpfCnpj = preg_replace('/\D/', '', (string)($request->cpf_cnpj ?? ''));
        $rule = strlen($cpfCnpj) === 14 ? new Cnpj() : new Cpf();

        $validated = $request->validate([
            'nome'           => ['required', 'string', 'max:150'],
            'cpf_cnpj'       => ['required', 'string', 'unique:clientes,cpf_cnpj', $rule],
            'telefone'       => ['nullable', 'string', 'max:15'],
            'email'          => ['nullable', 'email', 'max:120'],
            'cep'            => ['nullable', 'string', 'max:9'],
            'endereco'       => ['nullable', 'string', 'max:200'],
            'bairro'         => ['nullable', 'string', 'max:80'],
            'cidade'         => ['nullable', 'string', 'max:80'],
            'uf'             => ['nullable', 'string', 'size:2'],
            'veiculo_modelo' => ['nullable', 'string', 'max:80'],
            'veiculo_ano'    => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'veiculo_placa'  => ['nullable', 'string', 'max:10'],
        ]);

        $cliente = Cliente::create($validated);
        return (new ClienteResource($cliente))->response()->setStatusCode(201);
    }

    public function show(string $id): ClienteResource
    {
        $cliente = Cliente::with('veiculos')->findOrFail($id);

        activity()
            ->performedOn($cliente)
            ->causedBy(auth()->user())
            ->event('viewed')
            ->useLog(TenancyContext::getSlug() ?? 'default')
            ->log('viewed');

        return new ClienteResource($cliente);
    }

    public function update(Request $request, string $id): ClienteResource
    {
        $cliente = Cliente::findOrFail($id);
        $cpfCnpj = preg_replace('/\D/', '', (string)($request->cpf_cnpj ?? ''));
        $rule = strlen($cpfCnpj) === 14 ? new Cnpj() : new Cpf();

        $validated = $request->validate([
            'nome'           => ['sometimes', 'required', 'string', 'max:150'],
            'cpf_cnpj'       => ['sometimes', 'required', 'string', "unique:clientes,cpf_cnpj,{$id}", $rule],
            'telefone'       => ['nullable', 'string', 'max:15'],
            'email'          => ['nullable', 'email', 'max:120'],
            'cep'            => ['nullable', 'string', 'max:9'],
            'endereco'       => ['nullable', 'string', 'max:200'],
            'bairro'         => ['nullable', 'string', 'max:80'],
            'cidade'         => ['nullable', 'string', 'max:80'],
            'uf'             => ['nullable', 'string', 'size:2'],
            'veiculo_modelo' => ['nullable', 'string', 'max:80'],
            'veiculo_ano'    => ['nullable', 'integer'],
            'veiculo_placa'  => ['nullable', 'string', 'max:10'],
        ]);

        $cliente->update($validated);
        return new ClienteResource($cliente->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        Cliente::findOrFail($id)->delete();
        return response()->json(['message' => 'Cliente removido.']);
    }
}
