<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user?->loadMissing('personnel');
        $isLocked = $user?->locked_until?->isFuture() === true;
        $employmentEnded = $user?->personnel?->employment_end_date?->lt(today()) === true;

        if (! $user || $user->status !== 'Active' || $isLocked || $employmentEnded) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Your session is no longer active. Please sign in again.',
            ], 401);
        }

        return $next($request);
    }
}
