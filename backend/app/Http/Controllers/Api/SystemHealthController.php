<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;

final class SystemHealthController extends Controller
{
    public function __invoke(SystemHealthService $health): JsonResponse
    {
        return response()->json($health->snapshot());
    }
}
