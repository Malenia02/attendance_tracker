<?php

use App\Http\Middleware\AuditApiActivity;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login'
        );
        $middleware->trustHosts(
            at: fn (): array => config('app.trusted_hosts', [])
        );
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'api.auth' => AuthenticateApiToken::class,
            'api.audit' => AuditApiActivity::class,
            'role' => EnsureUserRole::class,
            'session.active' => EnsureActiveSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })
    ->withCommands()
    ->create();
