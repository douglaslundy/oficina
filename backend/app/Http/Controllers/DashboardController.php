<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\NotaFiscal;
use App\Models\OrdemServico;
use App\Models\Produto;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $clientesAtivos = Cliente::whereIn('status', ['REGULAR', 'DEVEDOR', 'OS_ABERTA'])->count();

        $dividasAbertas = (float)(OrdemServico::whereColumn('valor_pago', '<', 'valor_total')
            ->sum(DB::raw('valor_total - valor_pago')) ?? 0);

        $faturamentoMes = (float)(NotaFiscal::where('status', 'AUTORIZADA')
            ->whereMonth('emitido_em', now()->month)
            ->whereYear('emitido_em', now()->year)
            ->sum('valor_total') ?? 0);

        $nfEmitidas = NotaFiscal::where('status', 'AUTORIZADA')
            ->whereMonth('emitido_em', now()->month)
            ->count();

        // Faturamento últimos 7 meses
        $faturamentoMensal = NotaFiscal::where('status', 'AUTORIZADA')
            ->where('emitido_em', '>=', now()->subMonths(6)->startOfMonth())
            ->select(
                DB::raw("TO_CHAR(emitido_em, 'Mon/YY') as mes"),
                DB::raw('SUM(valor_total) as total')
            )
            ->groupBy('mes')
            ->orderByRaw("MIN(emitido_em)")
            ->get();

        // Produtos críticos (top 5)
        $produtosCriticos = Produto::where('ativo', true)
            ->where('qty_atual', '<', DB::raw('qty_minima'))
            ->orderByRaw('qty_atual::float / NULLIF(qty_minima, 1) ASC')
            ->limit(5)
            ->get(['id', 'nome', 'qty_atual', 'qty_minima', 'sku']);

        // Produtos com status calculado para o banner
        $allCritical = Produto::where('ativo', true)->get()
            ->filter(fn($p) => in_array($p->status_estoque, ['CRITICO', 'SEM_ESTOQUE']))
            ->values()
            ->map(fn($p) => ['id' => $p->id, 'nome' => $p->nome, 'qty_atual' => $p->qty_atual, 'status' => $p->status_estoque]);

        // Últimas 8 OS
        $ultimasOs = OrdemServico::with('cliente')
            ->orderBy('criado_em', 'desc')
            ->limit(8)
            ->get()
            ->map(fn($o) => [
                'id'          => $o->id,
                'numero'      => $o->numero,
                'cliente'     => $o->cliente?->nome,
                'status'      => $o->status,
                'valor_total' => $o->valor_total,
                'criado_em'   => $o->criado_em?->format('d/m/Y'),
            ]);

        return response()->json([
            'stats' => [
                'clientes_ativos' => $clientesAtivos,
                'dividas_abertas' => round($dividasAbertas, 2),
                'faturamento_mes' => round($faturamentoMes, 2),
                'nf_emitidas_mes' => $nfEmitidas,
            ],
            'faturamento_mensal' => $faturamentoMensal,
            'produtos_criticos'  => $produtosCriticos,
            'produtos_alerta'    => $allCritical,
            'ultimas_os'         => $ultimasOs,
        ]);
    }
}
