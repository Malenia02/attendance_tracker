<?php

namespace App\Http\Controllers;

use App\Models\SystemHealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BackupHeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = trim((string) config('health.backup_signing_secret'));

        if (strlen($secret) < 32) {
            return response()->json([
                'message' => 'Backup heartbeat monitoring is unavailable.',
            ], 503);
        }

        $timestamp = (string) $request->header('X-DILG-Backup-Timestamp', '');
        $signature = strtolower((string) $request->header('X-DILG-Backup-Signature', ''));
        $ttl = max(60, min(900, (int) config('health.backup_signature_ttl_seconds', 300)));

        if (! ctype_digit($timestamp)
            || abs(now()->timestamp - (int) $timestamp) > $ttl
            || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return $this->reject();
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp."\n".$request->getContent(),
            $secret
        );

        if (! hash_equals($expected, $signature)) {
            return $this->reject();
        }

        $validated = $request->validate([
            'run_id' => ['required', 'string', 'max:100'],
            'commit_sha' => ['required', 'string', 'regex:/\A[a-f0-9]{7,40}\z/'],
            'repository' => ['required', 'string', 'max:200', 'regex:/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/'],
        ]);

        SystemHealthCheck::recordSuccess('backup', [
            'run_id' => $validated['run_id'],
            'commit_sha' => substr($validated['commit_sha'], 0, 12),
            'repository' => $validated['repository'],
        ]);

        return response()->json([
            'status' => 'recorded',
            'timestamp' => now()->toISOString(),
        ], 202);
    }

    private function reject(): JsonResponse
    {
        return response()->json([
            'message' => 'The backup heartbeat signature is invalid.',
        ], 403);
    }
}
