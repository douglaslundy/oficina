<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    public function __construct(private PlanLimitService $planLimit) {}

    public function limites(): JsonResponse
    {
        return response()->json($this->planLimit->uso());
    }
}
