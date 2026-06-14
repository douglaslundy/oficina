<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarEmailRecuperacao;
use App\Models\PasswordResetToken;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $email = Str::lower(trim($request->email));
        $key   = 'forgot:' . $email;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            $minutes = (int) ceil($seconds / 60);

            return response()->json([
                'message' => "Muitas tentativas. Tente novamente em {$minutes} minuto(s).",
            ], 429);
        }

        RateLimiter::hit($key, 3600);

        $usuario = Usuario::where('email', $email)->first();

        if ($usuario) {
            $token = Str::uuid()->toString();

            PasswordResetToken::create([
                'usuario_id' => $usuario->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(30),
                'usado'      => false,
            ]);

            EnviarEmailRecuperacao::dispatch($usuario, $token);
        }

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá um link em breve.',
        ]);
    }
}
