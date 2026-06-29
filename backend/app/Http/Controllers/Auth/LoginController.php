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

        // Oficina suspensa/cancelada/inadimplente — bloqueia o acesso com mensagem clara.
        if ($usuario->oficina_id) {
            $oficina = \App\Models\Oficina::find($usuario->oficina_id);
            if ($oficina && in_array($oficina->status, ['SUSPENSA', 'CANCELADA', 'INADIMPLENTE'], true)) {
                return response()->json(['message' => 'Serviços suspensos, contate seu administrador.'], 403);
            }
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
