<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ResetPasswordController extends Controller
{
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $tokenRecord = PasswordResetToken::where('token_hash', hash('sha256', $request->token))
            ->where('expires_at', '>', now())
            ->where('usado', false)
            ->with('usuario')
            ->first();

        if (! $tokenRecord) {
            return response()->json(['message' => 'Token inválido ou expirado.'], 400);
        }

        $tokenRecord->usuario->update(['senha_hash' => Hash::make($request->password)]);
        $tokenRecord->update(['usado' => true]);

        return response()->json(['message' => 'Senha atualizada com sucesso.']);
    }
}
