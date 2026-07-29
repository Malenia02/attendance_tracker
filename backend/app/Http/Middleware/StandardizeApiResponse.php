<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class StandardizeApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = RequestId::for($request);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        if (! $request->is('api/*') || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload) || array_is_list($payload)) {
            $payload = ['data' => $payload];
        }

        $successful = $response->getStatusCode() < 400;
        $payload['success'] = $successful;
        $payload['request_id'] = $requestId;

        if (! $successful && ! isset($payload['error'])) {
            $payload['error'] = [
                'code' => $this->errorCode($response->getStatusCode()),
                'message' => (string) ($payload['message'] ?? 'The request could not be completed.'),
            ];

            if (! empty($payload['errors']) && is_array($payload['errors'])) {
                $payload['error']['details'] = $payload['errors'];
            }
        }

        $response->setData($payload);

        return $response;
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
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
    }
}
