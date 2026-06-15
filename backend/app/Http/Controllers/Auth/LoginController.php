<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        RateLimiter::clear($key);
        $usuario->update(['ultimo_acesso' => now()]);

        $token = $usuario->createToken('auth-token')->plainTextToken;

        $oficina_slug = $usuario->oficina_id
            ? \App\Models\Oficina::where('id', $usuario->oficina_id)->value('slug')
            : null;

        return response()->json([
            'token'        => $token,
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
        $request->user()->currentAccessToken()->delete();
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
}
