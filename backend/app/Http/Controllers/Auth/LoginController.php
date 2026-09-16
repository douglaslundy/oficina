<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'senha' => ['required', 'string'],
        ]);

        // Login precisa vir de uma origem "stateful" reconhecida (Referer/
        // Origin em SANCTUM_STATEFUL_DOMAINS) — sem isso,
        // EnsureFrontendRequestsAreStateful nunca inicia a sessão e
        // `Auth::guard('web')->login()`/`$request->session()` mais abaixo
        // dariam erro 500 em vez de uma mensagem clara. Único cliente real
        // desta rota é o próprio frontend SPA, que sempre manda Origin numa
        // requisição POST — isto é rede de segurança, não o caminho normal.
        if (! $request->hasSession()) {
            return response()->json(['message' => 'Requisição não reconhecida como vinda do aplicativo.'], 400);
        }

        $key = 'login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Muitas tentativas. Tente novamente em alguns minutos.'], 429);
        }

        $usuario = Usuario::where('email', $request->email)->first();

        if (! $usuario || ! Hash::check($request->senha, $usuario->senha_hash)) {
            RateLimiter::hit($key, 900);
            return response()->json(['message' => 'E-mail ou senha incorretos. Verifique e tente novamente.'], 401);
        }

        if ($usuario->status === 'INATIVO') {
            return response()->json(['message' => 'Usuário inativo. Contate o administrador.'], 403);
        }

        // Oficina suspensa/cancelada — bloqueia o acesso com mensagem clara.
        // INADIMPLENTE não bloqueia login: a oficina segue funcionando durante a
        // carência, só recebe o alerta de cobrança (ver InitializeTenancyByHeader).
        if ($usuario->oficina_id) {
            $oficina = \App\Models\Oficina::find($usuario->oficina_id);
            if ($oficina && in_array($oficina->status, ['SUSPENSA', 'CANCELADA'], true)) {
                return response()->json(['message' => 'Serviços suspensos, contate seu administrador.'], 403);
            }
        }

        RateLimiter::clear($key);
        $usuario->update(['ultimo_acesso' => now()]);

        // Falha de segurança grave corrigida em 2026-09-14: era um token
        // Bearer devolvido no corpo da resposta e guardado pelo frontend em
        // localStorage/document.cookie (legível por qualquer XSS). Agora é
        // sessão httpOnly via Sanctum SPA auth (EnsureFrontendRequestsAreStateful
        // no grupo de middleware `api`, ver bootstrap/app.php) — nenhum
        // segredo chega a existir no JavaScript do cliente.
        Auth::guard('web')->login($usuario);
        $request->session()->regenerate();

        $oficina_slug = $usuario->oficina_id
            ? \App\Models\Oficina::where('id', $usuario->oficina_id)->value('slug')
            : null;

        // Cookie NÃO-httpOnly, só de presença — não carrega nenhum segredo
        // (não autentica nada sozinho, só existe pra o middleware de rota do
        // Next.js/`proxy.ts` saber se deve redirecionar pra /login sem
        // precisar bater no backend a cada navegação).
        Cookie::queue(Cookie::make(
            'oficina_logado', '1', config('session.lifetime'), '/',
            config('session.domain'), (bool) config('session.secure'), false, false,
            config('session.same_site'),
        ));

        // Mesmo padrão/segurança do cookie acima — só o ROLE em texto puro,
        // não é credencial (não autentica nada sozinho). Existe só pra
        // `proxy.ts` decidir bloquear navegação pra tela restrita por papel
        // sem bater no backend a cada troca de rota. A autorização de
        // verdade continua 100% no servidor (middleware `role:` em cada
        // rota da API), este cookie é só uma otimização de UX.
        Cookie::queue(Cookie::make(
            'oficina_role', $usuario->role, config('session.lifetime'), '/',
            config('session.domain'), (bool) config('session.secure'), false, false,
            config('session.same_site'),
        ));

        return response()->json([
            'oficina_slug' => $oficina_slug,
            'user'         => [
                'id'    => $usuario->id,
                'nome'  => $usuario->nome,
                'email' => $usuario->email,
                'role'  => $usuario->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget('oficina_logado'));
        Cookie::queue(Cookie::forget('oficina_role'));

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    public function me(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json([
            'id'    => $u->id,
            'nome'  => $u->nome,
            'email' => $u->email,
            'role'  => $u->role,
        ]);
    }

    public function perfil(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json([
            'id'       => $u->id,
            'nome'     => $u->nome,
            'email'    => $u->email,
            'cpf'      => $u->cpf,
            'telefone' => $u->telefone,
            'role'     => $u->role,
            'status'   => $u->status,
        ]);
    }

    public function updatePerfil(Request $request): JsonResponse
    {
        $u = $request->user();

        $validated = $request->validate([
            'nome'     => ['sometimes', 'required', 'string', 'max:120'],
            'email'    => ['sometimes', 'required', 'email', "unique:usuarios,email,{$u->id}"],
            'telefone' => ['sometimes', 'nullable', 'string', 'max:15'],
            'senha'    => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);

        if (!empty($validated['senha'])) {
            $validated['senha_hash'] = Hash::make($validated['senha']);
            unset($validated['senha']);
        } else {
            unset($validated['senha']);
        }

        $u->update($validated);

        return response()->json([
            'message' => 'Dados atualizados com sucesso.',
            'data'    => [
                'id'       => $u->id,
                'nome'     => $u->nome,
                'email'    => $u->email,
                'cpf'      => $u->cpf,
                'telefone' => $u->telefone,
                'role'     => $u->role,
            ],
        ]);
    }
}
