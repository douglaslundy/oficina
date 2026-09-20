<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Backend nunca é exposto diretamente — só o Traefik tem porta
        // publicada (ver docker-compose.vps.yml/prod.yml) — necessário pra
        // $request->ip() refletir o IP real do usuário, não o do container
        // do proxy. Confiar em '*' permitiria qualquer cliente forjar
        // X-Forwarded-For (afeta o log de auditoria e o rate limit de
        // login); restrito às faixas privadas do Docker + loopback (usado
        // pelo cliente de teste do Laravel) — só uma conexão que já chega
        // por dentro da rede interna (ou seja, o próprio Traefik) é
        // tratada como proxy confiável.
        $middleware->trustProxies(at: [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '127.0.0.1',
        ]);

        // Falha de segurança grave corrigida em 2026-09-14: login guardava o
        // token em localStorage/document.cookie (legível por qualquer XSS),
        // contrariando a própria regra do CLAUDE.md ("Cookies httpOnly para
        // tokens (nunca localStorage)"). EnsureFrontendRequestsAreStateful
        // troca a autenticação por token Bearer por sessão via cookie
        // httpOnly (Sanctum SPA auth) pra requisições vindas do frontend
        // (Referer/Origin batendo com SANCTUM_STATEFUL_DOMAINS) — precisa
        // rodar logo depois do CORS, antes de qualquer coisa que dependa de
        // sessão/CSRF.
        $middleware->api(
            prepend: [
                \Illuminate\Http\Middleware\HandleCors::class,
                \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            ],
            append: [\App\Http\Middleware\SecurityHeaders::class],
        );

        // Bug real (2026-09-20): o EncryptCookies que o Sanctum stateful
        // aplica criptografava TODO cookie de resposta, inclusive
        // `oficina_role`/`oficina_logado` — cookies de PRESENÇA/UX lidos em
        // texto puro pelo proxy.ts do Next.js (não tem a APP_KEY). O proxy
        // recebia um blob `eyJpdi...` no lugar de "ADMIN", não achava o
        // role na lista e bloqueava até o ADMIN ("sem permissão"). Não são
        // credenciais (a sessão real é o cookie httpOnly, que segue
        // criptografado), então seguem de fora da criptografia.
        $middleware->encryptCookies(except: ['oficina_role', 'oficina_logado']);

        $middleware->alias([
            'tenant'        =>\App\Http\Middleware\InitializeTenancyByHeader::class,
            'role'          => \App\Http\Middleware\CheckRole::class,
            // Falha de segurança real corrigida em 2026-09-14: 'tenant'
            // resolve a oficina só pelo header X-Tenant (enviado pelo
            // cliente) sem nunca conferir se o usuário autenticado
            // pertence a ela — qualquer usuário podia trocar o header e
            // acessar dados de outra oficina. Este middleware roda DEPOIS
            // de 'auth:sanctum' em toda rota protegida (ver routes/api.php)
            // e recusa a requisição se o usuário não pertencer à oficina
            // resolvida pelo header.
            'tenant.verify' => \App\Http\Middleware\EnsureTenantMatchesUsuario::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
