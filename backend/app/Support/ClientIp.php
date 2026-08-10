<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ClientIp
{
    public static function for(Request $request): string
    {
        $verified = $request->attributes->get('verified_client_ip');

        if (is_string($verified) && filter_var($verified, FILTER_VALIDATE_IP)) {
            return self::canonical($verified);
        }

        return self::canonical((string) $request->ip());
    }

    public static function canonical(string $ip): string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false ? trim($ip) : (string) inet_ntop($packed);
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
