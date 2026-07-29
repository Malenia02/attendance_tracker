<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditApiActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ! $request->user()
            || $request->is('api/activity-logs*')) {
            return $response;
        }

        $resource = $this->resource($request);
        $verb = $resource === 'logout'
            ? 'LOGOUT'
            : match ($request->method()) {
                'POST' => 'CREATE',
                'DELETE' => 'DELETE',
                default => 'UPDATE',
            };
        $routeParameter = collect($request->route()?->parameters() ?? [])->first();
        $entityId = is_object($routeParameter)
            ? $routeParameter->getKey()
            : (is_numeric($routeParameter) ? (int) $routeParameter : null);
        $successful = $response->getStatusCode() < 400;
        $username = $request->user()->username;
        $description = $successful
            ? ($verb === 'LOGOUT'
                ? "{$username} signed out successfully."
                : "{$username} performed {$verb} on {$resource}.")
            : "{$username}'s {$verb} request for {$resource} was rejected with status {$response->getStatusCode()}.";

        try {
            ActivityLog::create([
                'user_id' => $request->user()->user_id,
                'activity_type' => $verb === 'LOGOUT'
                    ? 'LOGOUT'
                    : Str::limit($verb.'_'.strtoupper($resource), 100, ''),
                'description' => Str::limit($description, 500, ''),
                'entity_type' => $resource,
                'entity_id' => $entityId,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'request_id' => RequestId::for($request),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $response;
    }

    private function resource(Request $request): string
    {
        $segments = $request->segments();
        $resource = $segments[1] ?? 'system';

        if ($resource === 'auth') {
            return $segments[2] ?? 'authentication';
        }

        return str_replace('-', '_', $resource);
    }
}
