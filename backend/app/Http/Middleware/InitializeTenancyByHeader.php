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

            TenancyContext::set($oficina->id);
        }

        $response = $next($request);

        TenancyContext::clear(); // Always clear after request

        return $response;
    }
}
