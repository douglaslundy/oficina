<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarEmailRecuperacaoSaas;
use App\Models\SuperAdmin;
use App\Models\SuperAdminPasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'senha' => 'required|string',
        ]);

        $admin = SuperAdmin::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->senha, $admin->senha_hash)) {
            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        $token = $admin->createToken('saas-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id'    => $admin->id,
                'nome'  => $admin->nome,
                'email' => $admin->email,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('saas')?->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logout realizado.']);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->user('saas');
        return response()->json([
            'id'    => $admin->id,
            'nome'  => $admin->nome,
            'email' => $admin->email,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $admin = $request->user('saas');

        $validated = $request->validate([
            'nome'  => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120', 'unique:super_admins,email,' . $admin->id],
        ]);

        $admin->update([
            'nome'  => $validated['nome'],
            'email' => $validated['email'],
        ]);

        return response()->json([
            'id'    => $admin->id,
            'nome'  => $admin->nome,
            'email' => $admin->email,
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $admin = $request->user('saas');

        $validated = $request->validate([
            'senha_atual'             => ['required', 'string'],
            'nova_senha'              => ['required', 'string', 'min:8', 'confirmed'],
            'nova_senha_confirmation' => ['required', 'string'],
        ]);

        if (!Hash::check($validated['senha_atual'], $admin->senha_hash)) {
            return response()->json(['message' => 'Senha atual incorreta.'], 422);
        }

        $admin->update(['senha_hash' => Hash::make($validated['nova_senha'])]);

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $email = Str::lower(trim($request->email));
        $key   = 'saas-forgot:' . $email;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            $minutes = (int) ceil($seconds / 60);
            return response()->json([
                'message' => "Muitas tentativas. Tente novamente em {$minutes} minuto(s).",
            ], 429);
        }

        RateLimiter::hit($key, 3600);

        $admin = SuperAdmin::where('email', $email)->first();

        if ($admin) {
            $token = Str::uuid()->toString();

            SuperAdminPasswordReset::create([
                'super_admin_id' => $admin->id,
                'token_hash'     => hash('sha256', $token),
                'expires_at'     => now()->addMinutes(30),
                'usado'          => false,
            ]);

            EnviarEmailRecuperacaoSaas::dispatch($admin, $token);
        }

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá um link em breve.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $tokenRecord = SuperAdminPasswordReset::where('token_hash', hash('sha256', $request->token))
            ->where('expires_at', '>', now())
            ->where('usado', false)
            ->with('superAdmin')
            ->first();

        if (!$tokenRecord) {
            return response()->json(['message' => 'Token inválido ou expirado.'], 400);
        }

        $tokenRecord->superAdmin->update(['senha_hash' => Hash::make($request->password)]);
        $tokenRecord->update(['usado' => true]);

        return response()->json(['message' => 'Senha atualizada com sucesso.']);
    }
}
