<?php

namespace App\Http\Middleware;

use App\Models\UserAccessToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

        if (! $plainToken) {
            return $this->unauthenticated();
        }

        $accessToken = UserAccessToken::query()
            ->with('user.personnel')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $accessToken || $accessToken->expires_at->isPast()) {
            $accessToken?->delete();

            return $this->unauthenticated('Your session has expired. Please sign in again.');
        }

        if ($accessToken->user->status !== 'Active') {
            $accessToken->delete();

            return $this->unauthenticated('This account is not currently active.');
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        $request->setUserResolver(fn () => $accessToken->user);
        $request->attributes->set('accessToken', $accessToken);

        return $next($request);
    }

    private function unauthenticated(string $message = 'Authentication is required.'): JsonResponse
    {
        return response()->json(['message' => $message], 401);
    }
}
