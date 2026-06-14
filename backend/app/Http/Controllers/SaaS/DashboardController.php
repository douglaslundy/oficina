<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\Oficina;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $totalOficinas  = Oficina::count();
        $ativas         = Oficina::where('status', 'ATIVA')->count();
        $inadimplentes  = Oficina::where('status', 'INADIMPLENTE')->count();
        $suspensas      = Oficina::where('status', 'SUSPENSA')->count();

        $mrr = Oficina::where('oficinas.status', 'ATIVA')
            ->join('planos', 'planos.id', '=', 'oficinas.plano_id')
            ->sum('planos.preco_mensal');

        $crescimentoMensal = $this->getCrescimentoMensal();

        return response()->json([
            'total_oficinas'     => $totalOficinas,
            'ativas'             => $ativas,
            'inadimplentes'      => $inadimplentes,
            'suspensas'          => $suspensas,
            'mrr'                => round((float) $mrr, 2),
            'crescimento_mensal' => $crescimentoMensal,
        ]);
    }

    private function getCrescimentoMensal(): array
    {
        $meses = collect(range(6, 0))->map(fn (int $i) => now()->subMonths($i)->format('Y-m'));

        $counts = DB::table('oficinas')
            ->selectRaw("to_char(criado_em, 'YYYY-MM') as mes, count(*) as total")
            ->whereRaw("criado_em >= ?", [now()->subMonths(6)->startOfMonth()])
            ->groupByRaw("to_char(criado_em, 'YYYY-MM')")
            ->pluck('total', 'mes');

        return $meses->map(fn (string $mes) => [
            'mes'   => $mes,
            'total' => (int) ($counts[$mes] ?? 0),
        ])->values()->all();
    }
}
