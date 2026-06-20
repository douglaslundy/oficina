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
            ->where('valor_total', '>', 0)
            ->whereNotIn('status', ['CANCELADA'])
            ->sum(DB::raw('valor_total - valor_pago')) ?? 0);

        // Faturamento = valor efetivamente recebido (limitado ao valor da OS, ignora troco)
        $faturamentoMes = (float)(OrdemServico::where('status', 'CONCLUIDA')
            ->whereMonth('criado_em', now()->month)
            ->whereYear('criado_em', now()->year)
            ->sum(DB::raw('LEAST(valor_pago, valor_total)')) ?? 0);

        $nfEmitidas = NotaFiscal::where('status', 'AUTORIZADA')
            ->whereMonth('emitido_em', now()->month)
            ->count();

        // KPI — OS abertas/em andamento este mês
        $osMes = OrdemServico::where(fn($q) => $q->where('tipo', 'OS')->orWhereNull('tipo'))
            ->whereNotIn('status', ['CANCELADA'])
            ->whereMonth('criado_em', now()->month)
            ->whereYear('criado_em', now()->year)
            ->count();

        $osMesValor = (float)(OrdemServico::where(fn($q) => $q->where('tipo', 'OS')->orWhereNull('tipo'))
            ->whereNotIn('status', ['CANCELADA'])
            ->whereMonth('criado_em', now()->month)
            ->whereYear('criado_em', now()->year)
            ->sum('valor_total') ?? 0);

        // KPI — Vendas Balcão este mês
        $vendasMes = OrdemServico::where('tipo', 'VENDA_BALCAO')
            ->whereNotIn('status', ['CANCELADA'])
            ->whereMonth('criado_em', now()->month)
            ->whereYear('criado_em', now()->year)
            ->count();

        $vendasMesValor = (float)(OrdemServico::where('tipo', 'VENDA_BALCAO')
            ->whereNotIn('status', ['CANCELADA'])
            ->whereMonth('criado_em', now()->month)
            ->whereYear('criado_em', now()->year)
            ->sum('valor_total') ?? 0);

        // Faturamento mensal (últimos 7 meses) — OS + Vendas Balcão concluídas
        $faturamentoMensal = OrdemServico::where('status', 'CONCLUIDA')
            ->where('criado_em', '>=', now()->subMonths(6)->startOfMonth())
            ->select(
                DB::raw("TO_CHAR(criado_em, 'Mon/YY') as mes"),
                DB::raw('SUM(LEAST(valor_pago, valor_total)) as total')
            )
            ->groupBy('mes')
            ->orderByRaw("MIN(criado_em)")
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

        // Últimas 8 OS + Vendas Balcão
        $ultimasOs = OrdemServico::with('cliente')
            ->orderBy('criado_em', 'desc')
            ->limit(8)
            ->get()
            ->map(fn($o) => [
                'id'          => $o->id,
                'numero'      => $o->numero,
                'tipo'        => $o->tipo ?? 'OS',
                'cliente'     => $o->cliente?->nome,
                'status'      => $o->status,
                'valor_total' => $o->valor_total,
                'criado_em'   => $o->criado_em?->format('d/m/Y'),
            ]);

        return response()->json([
            'stats' => [
                'clientes_ativos'   => $clientesAtivos,
                'dividas_abertas'   => round($dividasAbertas, 2),
                'faturamento_mes'   => round($faturamentoMes, 2),
                'nf_emitidas_mes'   => $nfEmitidas,
                'os_mes'            => $osMes,
                'os_mes_valor'      => round($osMesValor, 2),
                'vendas_mes'        => $vendasMes,
                'vendas_mes_valor'  => round($vendasMesValor, 2),
            ],
            'faturamento_mensal' => $faturamentoMensal,
            'produtos_criticos'  => $produtosCriticos,
            'produtos_alerta'    => $allCritical,
            'ultimas_os'         => $ultimasOs,
        ]);
    }
}
