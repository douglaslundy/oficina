<?php
declare(strict_types=1);

namespace App\Http\Controllers\SaaS;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
}
