<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\OfficeNetwork;
use App\Support\ClientIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OfficeNetworkController extends Controller
{
    public function store(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'network_name' => ['required', 'string', 'max:80'],
            'ip_address' => ['prohibited'],
        ]);
        $ip = ClientIp::for($request);

        if (! config('security.allow_private_office_network_ips') && ! ClientIp::isPublic($ip)) {
            return response()->json([
                'message' => 'The server could not verify a public office IP address. Do not register localhost, a private address, or a proxy address.',
            ], 422);
        }

        $days = max(1, min(90, (int) config('security.office_network_validity_days', 30)));
        $network = DB::transaction(function () use ($department, $request, $validated, $ip, $days): OfficeNetwork {
            return OfficeNetwork::query()->updateOrCreate(
                [
                    'department_id' => $department->department_id,
                    'ip_address' => $ip,
                ],
                [
                    'network_name' => trim($validated['network_name']),
                    'verified_at' => now(),
                    'expires_at' => now()->addDays($days),
                    'status' => 'Active',
                    'created_by' => $request->user()->user_id,
                ]
            );
        });

        return response()->json([
            'message' => 'The current office network was securely registered for '.$days.' days.',
            'data' => $this->format($network),
        ], 201);
    }

    public function destroy(Request $request, Department $department, OfficeNetwork $officeNetwork): JsonResponse
    {
        if ((int) $officeNetwork->department_id !== (int) $department->department_id) {
            abort(404);
        }

        $officeNetwork->update(['status' => 'Inactive']);

        return response()->json(['message' => 'Office network access was revoked.']);
    }

    public static function formatNetwork(OfficeNetwork $network): array
    {
        return (new self)->format($network);
    }

    private function format(OfficeNetwork $network): array
    {
        return [
            'office_network_id' => $network->office_network_id,
            'network_name' => $network->network_name,
            'ip_address' => $network->ip_address,
            'verified_at' => $network->verified_at?->toISOString(),
            'expires_at' => $network->expires_at?->toISOString(),
            'status' => $network->status,
        ];
    }
}
