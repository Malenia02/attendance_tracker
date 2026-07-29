<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class RequestId
{
    private const ATTRIBUTE = 'request_id';

    public static function for(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $incoming = trim((string) $request->header('X-Request-ID'));
        $requestId = preg_match('/\A[A-Za-z0-9._-]{8,100}\z/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }
}
