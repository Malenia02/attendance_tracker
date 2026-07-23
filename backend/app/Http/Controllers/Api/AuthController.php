<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::query()
            ->with('personnel')
            ->where('username', $validated['username'])
            ->first();

        if (! $user) {
            Hash::check($validated['password'], '$2y$12$yHjb7Cp0rB7s3QygFC3YkOt9HqO8ePNZ5o43FKp1jVfSnXqGMZ52W');

            return $this->invalidCredentials();
        }

        if ($user->status === 'Inactive') {
            return response()->json([
                'message' => 'This account is inactive. Contact your system administrator.',
            ], 403);
        }

        if ($user->status === 'Locked') {
            if ($user->locked_until?->isPast()) {
                $user->forceFill([
                    'status' => 'Active',
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();
            } else {
                return response()->json([
                    'message' => $user->locked_until
                        ? 'Too many failed attempts. Try again after '.$user->locked_until->format('g:i A').'.'
                        : 'This account is locked. Contact your system administrator.',
                    'locked_until' => $user->locked_until?->toISOString(),
                ], 423);
            }
        }

        if (! Hash::check($validated['password'], $user->password_hash)) {
            $attempts = $user->failed_login_attempts + 1;
            $changes = ['failed_login_attempts' => $attempts];

            if ($attempts >= self::MAX_ATTEMPTS) {
                $changes['status'] = 'Locked';
                $changes['locked_until'] = now()->addMinutes(self::LOCK_MINUTES);
            }

            $user->forceFill($changes)->save();

            if ($attempts >= self::MAX_ATTEMPTS) {
                return response()->json([
                    'message' => 'Too many failed attempts. This account is locked for 15 minutes.',
                    'locked_until' => $changes['locked_until']->toISOString(),
                ], 423);
            }

            return $this->invalidCredentials(self::MAX_ATTEMPTS - $attempts);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        UserAccessToken::query()
            ->where('user_id', $user->user_id)
            ->where('expires_at', '<=', now())
            ->delete();

        $plainToken = Str::random(80);
        $expiresAt = ($validated['remember'] ?? false)
            ? now()->addDays(30)
            : now()->addHours(8);

        UserAccessToken::create([
            'user_id' => $user->user_id,
            'token_hash' => hash('sha256', $plainToken),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'message' => 'Signed in successfully.',
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
            'user' => $this->formatUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('accessToken')?->delete();

        return response()->json([
            'message' => 'Signed out successfully.',
        ]);
    }

    private function invalidCredentials(?int $attemptsRemaining = null): JsonResponse
    {
        return response()->json([
            'message' => 'The username or password is incorrect.',
            'attempts_remaining' => $attemptsRemaining,
        ], 422);
    }

    private function formatUser(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'user_role' => $user->user_role,
            'status' => $user->status,
            'personnel' => $user->personnel ? [
                'personnel_id' => $user->personnel->personnel_id,
                'full_name' => $user->personnel->full_name,
                'employee_number' => $user->personnel->employee_number,
                'email' => $user->personnel->email,
            ] : null,
        ];
    }
}
