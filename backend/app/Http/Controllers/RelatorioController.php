<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ClientesExport;
use App\Exports\EstoqueExport;
use App\Exports\OsExport;
use App\Models\Cliente;
use App\Models\OrdemServico;
use App\Models\Produto;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RelatorioController extends Controller
{
    public function __construct(private readonly EstoqueService $estoqueService) {}

    public function os(Request $request): JsonResponse|BinaryFileResponse
    {
        $filters = $request->only(['status', 'data_inicio', 'data_fim']);

        if ($request->boolean('export')) {
            return Excel::download(new OsExport($filters), 'ordens-servico.xlsx');
        }

        $q = OrdemServico::with(['cliente', 'mecanico']);
        if (!empty($filters['status']))      $q->where('status', $filters['status']);
        if (!empty($filters['data_inicio'])) $q->whereDate('criado_em', '>=', $filters['data_inicio']);
        if (!empty($filters['data_fim']))    $q->whereDate('criado_em', '<=', $filters['data_fim']);

        $os = $q->orderBy('numero')->get();

        $totalFaturado = $os->sum('valor_total');
        $totalRecebido = $os->sum('valor_pago');

        return response()->json([
            'total_os'       => $os->count(),
            'total_faturado' => $totalFaturado,
            'total_recebido' => $totalRecebido,
            'total_devedor'  => $totalFaturado - $totalRecebido,
            'por_status'     => $os->groupBy('status')->map->count(),
        ]);
    }

    public function clientes(Request $request): JsonResponse|BinaryFileResponse
    {
        if ($request->boolean('export')) {
            return Excel::download(new ClientesExport(), 'clientes.xlsx');
        }

        return response()->json([
            'total'      => Cliente::count(),
            'por_status' => Cliente::selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]);
    }

    public function estoque(Request $request): JsonResponse|BinaryFileResponse
    {
        if ($request->boolean('export')) {
            return Excel::download(new EstoqueExport($this->estoqueService), 'estoque.xlsx');
        }

        $produtos = Produto::where('ativo', true)->get();
        $criticos = $produtos->filter(fn($p) => in_array(
            $this->estoqueService->getStatusEstoque($p->qty_atual, $p->qty_minima),
            ['CRITICO', 'SEM_ESTOQUE']
        ));
        $baixos = $produtos->filter(fn($p) => $this->estoqueService->getStatusEstoque($p->qty_atual, $p->qty_minima) === 'BAIXO');

        return response()->json([
            'total_produtos' => $produtos->count(),
            'criticos'       => $criticos->count(),
            'baixos'         => $baixos->count(),
            'normais'        => $produtos->count() - $criticos->count() - $baixos->count(),
        ]);
    }
}
