<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');

            $ready = is_writable(storage_path('framework'))
                && is_writable(storage_path('logs'))
                && is_file(resource_path('templates/DTR-format-1.docx'))
                && (! app()->environment('production')
                    || config('app.frontend_deployment') === 'external'
                    || is_file(public_path('app/index.html')));

            return response()->json([
                'status' => $ready ? 'ready' : 'unavailable',
                'timestamp' => now()->toISOString(),
            ], $ready ? 200 : 503);
        } catch (Throwable $exception) {
            Log::warning('Production readiness probe failed.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'status' => 'unavailable',
                'timestamp' => now()->toISOString(),
            ], 503);
        }
    }
}
