<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Usuario::query();
        if ($request->has('role')) $query->where('role', $request->role);
        $users = $query->orderBy('nome')->get(['id', 'nome', 'email', 'cpf', 'role', 'status', 'ultimo_acesso']);
        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'unique:usuarios,email'],
            'cpf'      => ['required', 'string', 'size:11', 'unique:usuarios,cpf'],
            'telefone' => ['nullable', 'string', 'max:15'],
            'role'     => ['required', 'in:ADMIN,MECANICO,ATENDENTE,FINANCEIRO'],
            'senha'    => ['required', 'string', 'min:8'],
        ]);

        $usuario = Usuario::create([
            ...$validated,
            'senha_hash' => Hash::make($validated['senha']),
            'status'     => 'ATIVO',
        ]);

        return response()->json(['data' => $usuario->only(['id', 'nome', 'email', 'role', 'status'])], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);

        $validated = $request->validate([
            'nome'   => ['sometimes', 'required', 'string', 'max:120'],
            'email'  => ['sometimes', 'required', 'email', "unique:usuarios,email,{$id}"],
            'role'   => ['sometimes', 'required', 'in:ADMIN,MECANICO,ATENDENTE,FINANCEIRO'],
            'status' => ['sometimes', 'required', 'in:ATIVO,INATIVO'],
            'senha'  => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);

        if (!empty($validated['senha'])) {
            $validated['senha_hash'] = Hash::make($validated['senha']);
        }
        unset($validated['senha']);

        if (isset($validated['status']) && $validated['status'] === 'INATIVO' && $usuario->id === auth()->id()) {
            return response()->json(['message' => 'Você não pode desativar sua própria conta.'], 403);
        }

        $usuario->update($validated);
        return response()->json(['data' => $usuario->fresh()]);
    }
}
