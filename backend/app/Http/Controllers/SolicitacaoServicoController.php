<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PacoteServico;
use App\Models\SolicitacaoServico;
use App\Services\EntitlementService;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SolicitacaoServicoController extends Controller
{
    public function __construct(private readonly EntitlementService $ent) {}

    /** Pacotes disponíveis no catálogo (para a oficina solicitar). */
    public function pacotesDisponiveis(): JsonResponse
    {
        $oficinaId = (string) TenancyContext::get();

        $data = PacoteServico::where('ativo', true)
            ->orderBy('servico')->orderBy('valor')->get()
            ->map(fn (PacoteServico $p) => [
                'id'            => $p->id,
                'nome'          => $p->nome,
                'servico'       => $p->servico,
                'quantidade'    => $p->quantidade,
                'valor'         => $p->valor,
                'recorrente'    => $p->recorrente,
                'periodo_dias'  => $p->periodo_dias,
                'ja_disponivel' => $this->ent->disponivel($oficinaId, $p->servico),
            ]);

        return response()->json(['data' => $data]);
    }

    /** Solicitações da oficina atual. */
    public function index(): JsonResponse
    {
        $data = SolicitacaoServico::with('pacote')
            ->orderByDesc('criado_em')->get();
        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pacote_id' => ['required', 'uuid', 'exists:pacotes_servico,id'],
        ]);

        $oficinaId = (string) TenancyContext::get();

        $existe = SolicitacaoServico::where('oficina_id', $oficinaId)
            ->where('pacote_id', $validated['pacote_id'])
            ->where('status', 'PENDENTE')->exists();

        if ($existe) {
            return response()->json(['message' => 'Já existe uma solicitação pendente para este pacote.'], 422);
        }

        $solicitacao = SolicitacaoServico::create([
            'oficina_id' => $oficinaId,
            'pacote_id'  => $validated['pacote_id'],
            'status'     => 'PENDENTE',
        ]);

        return response()->json(['message' => 'Solicitação enviada! Aguarde a aprovação do administrador.', 'data' => $solicitacao], 201);
    }
}
