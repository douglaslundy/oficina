<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\ProdutosFiscaisExport;
use App\Http\Resources\ProdutoResource;
use App\Models\Configuracao;
use App\Models\Produto;
use App\Models\ProdutoFiscalDivergencia;
use App\Services\Fiscal\ExportacaoProdutosFiscal;
use App\Services\Fiscal\ProdutoFiscalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProdutoFiscalController extends Controller
{
    /** Largura (%) de cada coluna no PDF, na ordem de ExportacaoProdutosFiscal::COLUNAS (soma 100). */
    private const LARGURAS_PDF = [
        'nome' => 20, 'sku' => 8, 'codigo_barras' => 11, 'categoria' => 8, 'ncm' => 8, 'cest' => 8,
        'origem' => 5, 'tributacao_icms' => 7, 'fiscal_fonte' => 6, 'revisado_em' => 8, 'situacao_fiscal' => 11,
    ];

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
                  ->orWhereIn('id', $comDivergencia)
                  // ST sem CEST bloqueia NF-e (CriarNotaFiscalService::criar(),
                  // achado real 2026-09-15, cStat=806) — precisa aparecer aqui
                  // mesmo quando NCM/fiscal_fonte já estão OK.
                  ->orWhere(function ($q2) {
                      $q2->where('tributacao_icms', 'ST')
                         ->where(function ($q3) {
                             $q3->whereNull('cest')->orWhere('cest', '');
                         });
                  });
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
     * Exporta TODOS os produtos ativos com os dados fiscais (não só as
     * pendências), no formato pedido: pdf, xml, json ou xlsx. Respeita o
     * filtro de categoria da tela. Produtos sem nenhum campo fiscal
     * preenchido vão por último (ver ExportacaoProdutosFiscal::linhas()).
     */
    public function exportar(Request $request, ExportacaoProdutosFiscal $exportacao): Response|BinaryFileResponse|JsonResponse
    {
        $formato = strtolower((string) $request->query('formato'));
        if (!in_array($formato, ['pdf', 'xml', 'json', 'xlsx'], true)) {
            return response()->json(['message' => 'Formato inválido. Use pdf, xml, json ou xlsx.'], 422);
        }

        $categoria = $request->filled('categoria') ? (string) $request->string('categoria') : null;

        $produtos = Produto::where('ativo', true)
            ->when($categoria !== null, fn ($q) => $q->where('categoria', $categoria))
            ->get();

        $idsComDivergencia = ProdutoFiscalDivergencia::whereNull('resolvido_em')
            ->pluck('produto_id')
            ->unique()
            ->values()
            ->all();

        $linhas   = $exportacao->linhas($produtos, $idsComDivergencia);
        $geradoEm = now()->format('d/m/Y H:i');
        $arquivo  = 'produtos-dados-fiscais-' . now()->format('Y-m-d') . '.' . $formato;

        return match ($formato) {
            'xlsx' => Excel::download(new ProdutosFiscaisExport($linhas), $arquivo),
            'json' => response($exportacao->paraJson($linhas, $geradoEm), 200, [
                'Content-Type'        => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $arquivo . '"',
            ]),
            'xml'  => response($exportacao->paraXml($linhas, $geradoEm), 200, [
                'Content-Type'        => 'application/xml; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $arquivo . '"',
            ]),
            'pdf'  => Pdf::loadView('pdf.produtos_fiscais', [
                'empresa'   => Configuracao::first()?->toArray() ?? [],
                'linhas'    => $linhas,
                'colunas'   => ExportacaoProdutosFiscal::COLUNAS,
                'larguras'  => self::LARGURAS_PDF,
                'geradoEm'  => $geradoEm,
                'categoria' => $categoria,
            ])->setPaper('a4', 'landscape')->download($arquivo),
        };
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
