<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Oficina;
use App\Tenancy\TenancyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancyByHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Tenant');

        if ($slug) {
            $oficina = Oficina::where('slug', $slug)->first();

            if (!$oficina) {
                return response()->json(['message' => 'Oficina não encontrada.'], 404);
            }

            if ($oficina->status === 'CANCELADA') {
                return response()->json(['message' => 'Esta oficina foi cancelada.'], 403);
            }

            if ($oficina->status === 'SUSPENSA') {
                $rotasLiberadas = ['api/assinatura/status-bloqueio', 'api/assinatura/voto-confianca'];
                if (!in_array($request->path(), $rotasLiberadas, true)) {
                    return response()->json([
                        'message' => 'Esta oficina está suspensa. Entre em contato com o suporte.',
                        'code'    => 'OFICINA_SUSPENSA',
                    ], 403);
                }
            }

            TenancyContext::set($oficina->id, $oficina->slug);
        }

        $response = $next($request);

        TenancyContext::clear(); // Always clear after request

        return $response;
    }
}
