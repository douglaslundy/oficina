<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CategoriaPadraoFiscal;
use App\Tenancy\TenancyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoriaPadraoFiscalController extends Controller
{
    public function index(): JsonResponse
    {
        $salvas = CategoriaPadraoFiscal::get()->keyBy('categoria');

        $data = array_map(static function (string $categoria) use ($salvas): array {
            $linha = $salvas->get($categoria);

            return [
                'categoria'       => $categoria,
                'ncm'             => $linha->ncm ?? null,
                'origem'          => $linha->origem ?? null,
                'tributacao_icms' => $linha->tributacao_icms ?? null,
            ];
        }, CategoriaPadraoFiscal::CATEGORIAS);

        return response()->json(['data' => $data]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categorias'                   => ['required', 'array', 'min:1'],
            'categorias.*.categoria'       => ['required', 'string', 'max:40'],
            'categorias.*.ncm'             => ['nullable', 'string', 'size:8'],
            'categorias.*.origem'          => ['nullable', 'integer', 'min:0', 'max:8'],
            'categorias.*.tributacao_icms' => ['nullable', 'string', 'in:NORMAL,ST'],
        ]);

        foreach ($validated['categorias'] as $linha) {
            CategoriaPadraoFiscal::updateOrCreate(
                [
                    'oficina_id' => TenancyContext::get(),
                    'categoria'  => $linha['categoria'],
                ],
                [
                    'ncm'             => $linha['ncm'] ?? null,
                    'origem'          => $linha['origem'] ?? null,
                    'tributacao_icms' => $linha['tributacao_icms'] ?? null,
                ],
            );
        }

        return $this->index();
    }
}
