<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::with('causer')
            ->orderBy('created_at', 'desc');

        // Filtrar por causer (usuário)
        if ($request->filled('usuario_id')) {
            $query->where('causer_id', $request->query('usuario_id'))
                  ->where('causer_type', \App\Models\Usuario::class);
        }

        // Filtrar por tipo de modelo (subject_type)
        if ($request->filled('modelo')) {
            $modelMap = [
                'cliente'       => \App\Models\Cliente::class,
                'produto'       => \App\Models\Produto::class,
                'os'            => \App\Models\OrdemServico::class,
                'nota_fiscal'   => \App\Models\NotaFiscal::class,
                'usuario'       => \App\Models\Usuario::class,
                'veiculo'       => \App\Models\Veiculo::class,
            ];
            $class = $modelMap[$request->query('modelo')] ?? null;
            if ($class) {
                $query->where('subject_type', $class);
            }
        }

        // Filtrar por evento (created, updated, deleted)
        if ($request->filled('evento')) {
            $query->where('event', $request->query('evento'));
        }

        // Filtrar por período
        if ($request->filled('data_inicio')) {
            $query->whereDate('created_at', '>=', $request->query('data_inicio'));
        }
        if ($request->filled('data_fim')) {
            $query->whereDate('created_at', '<=', $request->query('data_fim'));
        }

        // Filtrar pelo log_name (tenant slug via X-Tenant header)
        $tenantSlug = $request->header('X-Tenant');
        if ($tenantSlug) {
            $query->where('log_name', $tenantSlug);
        }

        $activities = $query->paginate(25);

        $data = $activities->getCollection()->map(fn (Activity $a) => $this->formatActivity($a));

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $activities->total(),
                'per_page'     => $activities->perPage(),
                'current_page' => $activities->currentPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $activity = Activity::with('causer')->findOrFail($id);

        return response()->json([
            'data' => $this->formatActivity($activity, full: true),
        ]);
    }

    private function formatActivity(Activity $a, bool $full = false): array
    {
        $subjectLabel = $this->subjectLabel($a->subject_type ?? '');
        $causerNome   = $a->causer?->nome ?? 'Sistema';

        $properties = $a->attribute_changes?->toArray() ?? [];
        $old        = $properties['old'] ?? [];
        $new        = $properties['attributes'] ?? [];

        $diff = [];
        foreach ($new as $campo => $novoValor) {
            $antigoValor = $old[$campo] ?? null;
            if ($antigoValor !== $novoValor) {
                $diff[] = [
                    'campo'  => $campo,
                    'antes'  => $antigoValor,
                    'depois' => $novoValor,
                ];
            }
        }

        $base = [
            'id'             => $a->id,
            'evento'         => $a->event,
            'descricao'      => $a->description,
            'modelo'         => $subjectLabel,
            'subject_type'   => $a->subject_type,
            'subject_id'     => $a->subject_id,
            'causer_nome'    => $causerNome,
            'causer_id'      => $a->causer_id,
            'campos_alterados' => count($diff),
            'criado_em'      => $a->created_at?->format('d/m/Y H:i:s'),
        ];

        if ($full) {
            $base['diff'] = $diff;
            $base['propriedades'] = $properties; // já lê de attribute_changes
        }

        return $base;
    }

    private function subjectLabel(string $class): string
    {
        return match ($class) {
            \App\Models\Cliente::class       => 'Cliente',
            \App\Models\Produto::class       => 'Produto',
            \App\Models\OrdemServico::class  => 'Ordem de Serviço',
            \App\Models\NotaFiscal::class    => 'Nota Fiscal',
            \App\Models\Usuario::class       => 'Usuário',
            \App\Models\Veiculo::class       => 'Veículo',
            default                          => class_basename($class),
        };
    }
}
