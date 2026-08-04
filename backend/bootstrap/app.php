<?php

use App\Http\Middleware\AuditApiActivity;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StandardizeApiResponse;
use App\Support\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $middleware->append(StandardizeApiResponse::class);
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

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Some fields are invalid.',
                'errors' => $exception->errors(),
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Some fields are invalid.',
                    'details' => $exception->errors(),
                ],
                'request_id' => RequestId::for($request),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required.',
                ],
                'request_id' => RequestId::for($request),
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Policy denial messages are deliberately written for the API user.
            // Preserve those messages while keeping manually thrown authorization
            // exceptions generic so internal details are never reflected blindly.
            $policyMessage = $exception->response()?->message();
            $message = is_string($policyMessage) && trim($policyMessage) !== ''
                ? $policyMessage
                : 'You do not have permission to perform this action.';
            $status = $exception->status() ?? 403;

            return response()->json([
                'success' => false,
                'message' => $message,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => $message,
                ],
                'request_id' => RequestId::for($request),
            ], $status);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'The requested resource was not found.',
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'The requested resource was not found.',
                ],
                'request_id' => RequestId::for($request),
            ], 404);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') || config('app.debug')) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;
            $headers = $exception instanceof HttpExceptionInterface
                ? $exception->getHeaders()
                : [];
            $safeMessages = [
                400 => 'The request is invalid.',
                405 => 'The HTTP method is not allowed for this endpoint.',
                409 => 'The request conflicts with the current resource state.',
                419 => 'Your secure session token has expired. Refresh the page and try again.',
                429 => 'Too many requests. Please wait and try again.',
            ];
            $message = $safeMessages[$status]
                ?? ($status >= 500
                    ? 'The request could not be completed.'
                    : 'The request was rejected.');
            $code = match ($status) {
                400 => 'BAD_REQUEST',
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                409 => 'CONFLICT',
                419 => 'CSRF_TOKEN_MISMATCH',
                422 => 'VALIDATION_FAILED',
                429 => 'TOO_MANY_REQUESTS',
                default => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
                'request_id' => RequestId::for($request),
            ], $status, $headers);
        });
    })
    ->withCommands()
    ->create();
