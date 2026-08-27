<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RoleDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class DashboardController extends Controller
{
    public function index(Request $request, RoleDashboardService $dashboard): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('personnel.department');

        $cacheSeconds = max(0, (int) config('attendance.dashboard_cache_seconds', 30));
        $cacheKey = implode(':', [
            'dashboard',
            'v4',
            today()->toDateString(),
            'user',
            $user->user_id,
            'role',
            $user->user_role,
            'personnel',
            $user->personnel_id ?? 'none',
            'department',
            $user->personnel?->department_id ?? 'none',
        ]);
        $payload = $cacheSeconds > 0
            ? Cache::remember(
                $cacheKey,
                now()->addSeconds($cacheSeconds),
                fn (): array => $dashboard->for($user)
            )
            : $dashboard->for($user);

        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Cookie, Authorization, Origin',
        ]);
    }
}
