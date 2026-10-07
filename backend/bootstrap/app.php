<?php

use App\Http\Middleware\AuditApiActivity;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StandardizeApiResponse;
use App\Http\Middleware\VerifyFrontendProxy;
use App\Support\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
        $middleware->validateCsrfTokens(except: [
            'health/backup-heartbeat',
        ]);
        $middleware->append(StandardizeApiResponse::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(VerifyFrontendProxy::class);

        $middleware->alias([
            'api.auth' => AuthenticateApiToken::class,
            'api.audit' => AuditApiActivity::class,
            'role' => EnsureUserRole::class,
            'session.active' => EnsureActiveSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->context(fn (): array => app()->bound('request')
            ? ['request_id' => RequestId::for(request())]
            : []);

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
                : 'You do not have permission to do this. Contact your administrator if you need access.';
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

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Laravel prepares authorization failures as AccessDeniedHttpException
            // before rendering. Only trust a message attached to a deliberate
            // policy response; all other 403 exceptions retain the generic text.
            $authorization = $exception->getPrevious();
            $policyMessage = $authorization instanceof AuthorizationException
                ? $authorization->response()?->message()
                : null;
            $message = is_string($policyMessage) && trim($policyMessage) !== ''
                ? $policyMessage
                : 'You do not have permission to do this. Contact your administrator if you need access.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => $message,
                ],
                'request_id' => RequestId::for($request),
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'We could not find the requested item. Refresh the page and try again.',
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'We could not find the requested item. Refresh the page and try again.',
                ],
                'request_id' => RequestId::for($request),
            ], 404);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            $status = match (true) {
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => $exception->status() ?? 403,
                $exception instanceof ModelNotFoundException => 404,
                default => 500,
            };
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
            $headers = $exception instanceof HttpExceptionInterface
                ? $exception->getHeaders()
                : [];

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                [$title, $message] = match ($status) {
                    401 => ['Please sign in again', 'Your session has ended. Return to AttendanceHub to sign in.'],
                    403 => ['You cannot open this page', 'Your account does not have access. Contact your administrator if you think this is a mistake.'],
                    404 => ['We could not find that page', 'The link may be outdated. Return to AttendanceHub and try again.'],
                    422 => ['Please check your information', 'Some information could not be accepted. Return and review your entries.'],
                    default => ['Something did not load correctly', 'Please try again. If the problem continues, contact your DILG system administrator.'],
                };

                return response()->view('errors.friendly', [
                    'title' => $title,
                    'message' => $message,
                    'requestId' => RequestId::for($request),
                ], $status, $headers);
            }

            $safeMessages = [
                400 => 'We could not accept this request. Check your entries and try again.',
                401 => 'Your login session is no longer valid. Please sign in again.',
                403 => 'You do not have permission to do this. Contact your administrator if you need access.',
                404 => 'We could not find the requested item. Refresh the page and try again.',
                405 => 'This action is unavailable. Refresh the page and try again.',
                409 => 'This item changed while you were working. Refresh the page and try again.',
                419 => 'Your secure session token has expired. Refresh the page and try again.',
                429 => 'Too many requests. Please wait and try again.',
                500 => 'We could not complete your request. Please try again. If it continues, contact your administrator with the request ID.',
            ];
            $message = $safeMessages[$status]
                ?? ($status >= 500
                    ? $safeMessages[500]
                    : 'We could not complete this request. Check your entries and try again.');
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
