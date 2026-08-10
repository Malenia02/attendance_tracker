<?php

namespace App\Services;

use App\Models\OfficeNetwork;
use App\Support\ClientIp;
use Illuminate\Http\Request;

final class OfficeNetworkVerifier
{
    public function match(Request $request, int $departmentId): ?OfficeNetwork
    {
        return OfficeNetwork::query()
            ->where('department_id', $departmentId)
            ->where('ip_address', ClientIp::for($request))
            ->where('status', 'Active')
            ->where('verified_at', '<=', now())
            ->where('expires_at', '>', now())
            ->first();
    }
}
