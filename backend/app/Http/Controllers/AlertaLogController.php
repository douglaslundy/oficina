<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AlertaLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertaLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AlertaLog::query()->orderBy('enviado_em', 'desc');

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->string('tipo'));
        }
        if ($request->filled('sucesso')) {
            $query->where('sucesso', filter_var($request->input('sucesso'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('de')) {
            $query->where('enviado_em', '>=', $request->input('de') . ' 00:00:00');
        }
        if ($request->filled('ate')) {
            $query->where('enviado_em', '<=', $request->input('ate') . ' 23:59:59');
        }

        $logs = $query->paginate(50);

        return response()->json($logs);
    }
}
