<?php

namespace App\Http\Middleware;

use App\Support\ClientIp;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyFrontendProxy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.frontend_api_proxy') || ! $request->is('api/*')) {
            return $next($request);
        }

        $secret = trim((string) config('security.frontend_proxy_signing_secret'));

        if (strlen($secret) < 32) {
            return $this->reject(
                'The secure frontend proxy is not configured.',
                'PROXY_NOT_CONFIGURED',
                503
            );
        }

        $clientIp = (string) $request->header('X-DILG-Client-IP', '');
        $signedPath = (string) $request->header('X-DILG-Proxy-Path', $request->getPathInfo());
        $timestamp = (string) $request->header('X-DILG-Proxy-Timestamp', '');
        $signature = (string) $request->header('X-DILG-Proxy-Signature', '');
        $ttl = max(30, min(300, (int) config(
            'security.frontend_proxy_signature_ttl_seconds',
            90
        )));

        if (! filter_var($clientIp, FILTER_VALIDATE_IP)
            || ! str_starts_with($signedPath, '/api/')
            || str_contains($signedPath, '..')
            || ! ctype_digit($timestamp)
            || abs(now()->timestamp - (int) $timestamp) > $ttl
            || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return $this->reject();
        }

        $payload = implode("\n", [
            $timestamp,
            strtoupper($request->method()),
            $signedPath,
            $clientIp,
        ]);
        $expected = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            return $this->reject();
        }

        $request->attributes->set('verified_client_ip', ClientIp::canonical($clientIp));

        return $next($request);
    }

    private function reject(
        string $message = 'The request did not pass secure proxy verification.',
        string $code = 'PROXY_VERIFICATION_FAILED',
        int $status = 403
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
