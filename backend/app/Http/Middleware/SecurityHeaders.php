<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $headers->set(
            'Permissions-Policy',
            'camera=(self), geolocation=(self), microphone=()'
        );
        $headers->set(
            'Content-Security-Policy',
            "default-src 'self'; "
                ."base-uri 'self'; "
                ."connect-src 'self'; "
                ."font-src 'self' data:; "
                ."form-action 'self'; "
                ."frame-ancestors 'none'; "
                ."img-src 'self' data: blob:; "
                ."media-src 'self' blob:; "
                ."object-src 'none'; "
                ."script-src 'self'; "
                ."style-src 'self' 'unsafe-inline'; "
                ."worker-src 'self' blob:"
        );

        if ($request->is('api/*')) {
            $headers->set('Cache-Control', 'no-store, private');
            $headers->set('Pragma', 'no-cache');
        }

        if ($request->isSecure()) {
            $headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
