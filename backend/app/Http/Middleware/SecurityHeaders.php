<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers de segurança exigidos desde a especificação original do sistema
 * (CLAUDE.md — "Headers de segurança: X-Frame-Options: DENY,
 * X-Content-Type-Options: nosniff") mas nunca implementados. Sem
 * X-Frame-Options, a aplicação (incluindo as telas de pagamento) pode ser
 * embutida num iframe de terceiros para um ataque de clickjacking/UI-redress
 * contra usuários autenticados.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
