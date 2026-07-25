<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Produto;
use App\Models\ProdutoFiscalDivergencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProdutoFiscalController extends Controller
{
    /**
     * Produtos ativos que precisam de atenção fiscal: sem NCM, com valor
     * herdado do padrão da categoria (chute assistido), ou com divergência
     * aberta contra o XML de um fornecedor.
     *
     * NÃO inclui "fiscal_revisado_em is null": dado vindo do XML do
     * fornecedor é confiável e não exige conferência humana. Incluir essa
     * condição manteria todo produto preenchido por importação na lista
     * para sempre, esvaziando o sentido da tela.
     */
    public function pendencias(): JsonResponse
    {
        $comDivergencia = ProdutoFiscalDivergencia::whereNull('resolvido_em')
            ->pluck('produto_id')
            ->unique()
            ->all();

        $produtos = Produto::where('ativo', true)
            ->where(function ($q) use ($comDivergencia) {
                $q->whereNull('ncm')
                  ->orWhere('fiscal_fonte', 'PADRAO')
                  ->orWhereIn('id', $comDivergencia);
            })
            ->orderBy('nome')
            ->get();

        $divergencias = ProdutoFiscalDivergencia::with('produto:id,nome')
            ->whereNull('resolvido_em')
            ->orderByDesc('criado_em')
            ->get()
            ->map(fn (ProdutoFiscalDivergencia $d) => [
                'id'           => $d->id,
                'produto_id'   => $d->produto_id,
                'produto_nome' => $d->produto?->nome,
                'campo'        => $d->campo,
                'valor_atual'  => $d->valor_atual,
                'valor_xml'    => $d->valor_xml,
                'criado_em'    => $d->criado_em?->format('d/m/Y H:i'),
            ]);

        return response()->json([
            'data'         => ProdutoResource::collection($produtos)->resolve(),
            'divergencias' => $divergencias,
            'total'        => $produtos->count(),
        ]);
    }

    public function resolverDivergencia(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'resolucao' => ['required', 'string', 'in:MANTEVE,ACEITOU_XML'],
        ]);

        DB::transaction(function () use ($id, $validated) {
            $divergencia = ProdutoFiscalDivergencia::whereNull('resolvido_em')
                ->lockForUpdate()
                ->findOrFail($id);

            if ($validated['resolucao'] === 'ACEITOU_XML') {
                $divergencia->produto?->update([
                    $divergencia->campo  => $divergencia->valor_xml,
                    'fiscal_fonte'       => 'XML',
                    'fiscal_revisado_em' => now(),
                ]);
            } else {
                $divergencia->produto?->update(['fiscal_revisado_em' => now()]);
            }

            $divergencia->update([
                'resolvido_em' => now(),
                'resolucao'    => $validated['resolucao'],
            ]);
        });

        return response()->json(['message' => 'Divergência resolvida.']);
    }
}
