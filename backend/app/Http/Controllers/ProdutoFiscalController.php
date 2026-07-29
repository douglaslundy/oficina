<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Produto;
use App\Models\ProdutoFiscalDivergencia;
use App\Services\Fiscal\ProdutoFiscalService;
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
     *
     * Paginado: no dia do deploy desta migração, todo produto existente
     * tem ncm = null, então a lista sem paginação seria o catálogo
     * inteiro. Segue o mesmo padrão de paginação em memória usado em
     * ProdutoController::index().
     */
    public function pendencias(Request $request): JsonResponse
    {
        $comDivergencia = ProdutoFiscalDivergencia::whereNull('resolvido_em')
            ->pluck('produto_id')
            ->unique()
            ->all();

        $query = Produto::where('ativo', true)
            ->where(function ($q) use ($comDivergencia) {
                $q->whereNull('ncm')
                  ->orWhere('fiscal_fonte', 'PADRAO')
                  ->orWhereIn('id', $comDivergencia);
            });

        if ($request->filled('categoria')) {
            $query->where('categoria', (string) $request->string('categoria'));
        }

        $all = $query->orderBy('nome')->get();

        $perPage = (int) ($request->per_page ?? 20);
        $page    = (int) ($request->page ?? 1);
        $total   = $all->count();
        $items   = $all->slice(($page - 1) * $perPage, $perPage)->values();

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
            'data'         => ProdutoResource::collection($items)->resolve(),
            'divergencias' => $divergencias,
            'meta'         => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
            ],
        ]);
    }

    /**
     * Confirma que os dados fiscais atuais do produto estão corretos.
     * Ação explícita do usuário — ao contrário de update(), não depende de
     * o produto ter sido alterado: "revisei e está certo" também é uma
     * conclusão válida, e sem isso um produto marcado PADRAO nunca sai da
     * tela de pendências se o usuário concorda com o valor sugerido.
     */
    public function marcarRevisado(string $id): JsonResponse
    {
        $produto = Produto::findOrFail($id);

        if ($produto->ncm === null) {
            return response()->json([
                'message' => 'Não é possível marcar como revisado um produto sem NCM.',
            ], 422);
        }

        $produto->update([
            'fiscal_fonte'       => 'MANUAL',
            'fiscal_revisado_em' => now(),
        ]);

        return response()->json([
            'message' => 'Produto marcado como revisado.',
            'data'    => new ProdutoResource($produto),
        ]);
    }

    public function resolverDivergencia(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'resolucao' => ['required', 'string', 'in:MANTEVE,ACEITOU_XML'],
        ]);

        try {
            DB::transaction(function () use ($id, $validated) {
                $divergencia = ProdutoFiscalDivergencia::whereNull('resolvido_em')
                    ->lockForUpdate()
                    ->findOrFail($id);

                // $divergencia->campo é uma string persistida virando nome de
                // coluna numa mass assignment contra Produto. Hoje só
                // ProdutoFiscalService::CAMPOS grava divergências, mas o
                // guard trava a invariante no próprio ponto de uso, não só
                // na origem dos dados.
                if (!in_array($divergencia->campo, ProdutoFiscalService::CAMPOS, true)) {
                    throw new \RuntimeException("Campo fiscal inválido para divergência: {$divergencia->campo}");
                }

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
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Divergência resolvida.']);
    }
}
