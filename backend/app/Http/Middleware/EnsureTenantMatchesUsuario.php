<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenancyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FALHA DE SEGURANÇA REAL corrigida em 2026-09-14 (auditoria completa do
 * sistema): `InitializeTenancyByHeader` resolve a oficina só a partir do
 * header `X-Tenant` — enviado pelo CLIENTE — e nunca confere se o usuário
 * autenticado (`auth:sanctum`) realmente pertence a essa oficina. Como o
 * global scope de `HasTenantScope` confia cegamente no `TenancyContext`,
 * qualquer usuário autenticado de QUALQUER oficina podia trocar o header
 * `X-Tenant` pelo slug de outra oficina (slugs aparecem no subdomínio
 * público, ex. `stuntmotos.dlsistemas.com.br` — triviais de descobrir) e
 * ler/editar/apagar clientes, produtos, OS, notas fiscais e usuários de
 * QUALQUER oficina do sistema, sem precisar de nenhuma credencial dela.
 *
 * Este middleware roda DEPOIS de `auth:sanctum` (que já populou
 * `auth()->user()`) e depois de `tenant` (que já resolveu o
 * `TenancyContext` a partir do header) — é o único ponto que efetivamente
 * compara os dois e recusa a requisição se divergirem. Nunca confiar só no
 * header pra decidir de qual oficina são os dados.
 */
class EnsureTenantMatchesUsuario
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = auth()->user();

        // Sem usuário autenticado aqui não é esperado (auth:sanctum já
        // deveria ter bloqueado com 401) — mas se acontecer, não é este
        // middleware que decide isso; deixa passar pro guard responder.
        if ($usuario === null) {
            return $next($request);
        }

        // Usuário sem oficina_id (ex.: alguma conta especial futura) não é
        // um caso coberto por tenant scoping — não bloqueia aqui.
        if ($usuario->oficina_id === null) {
            return $next($request);
        }

        // Falha fechada nos dois sentidos: sem TenancyContext nenhum
        // (header X-Tenant ausente) o global scope de HasTenantScope
        // também não filtra nada — não é só o caso de header ERRADO que
        // precisa ser barrado, é também o de header AUSENTE.
        if (!TenancyContext::has() || TenancyContext::get() !== (string) $usuario->oficina_id) {
            return response()->json(['message' => 'Acesso negado: usuário não pertence a esta oficina.'], 403);
        }

        return $next($request);
    }
}
