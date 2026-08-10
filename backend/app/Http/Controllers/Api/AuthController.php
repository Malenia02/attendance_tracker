<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ClientIp;
use App\Support\RequestId;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            ->where(function ($query) use ($validated): void {
                $query
                    ->where('username', $validated['username'])
                    ->orWhereHas('personnel', fn ($query) => $query->where('email', $validated['username']));
            })
            ->first();

        $passwordHash = $user?->password_hash
            ?? '$2y$12$yHjb7Cp0rB7s3QygFC3YkOt9HqO8ePNZ5o43FKp1jVfSnXqGMZ52W';
        $passwordIsValid = Hash::check($validated['password'], $passwordHash);

        if (! $user || ! $passwordIsValid) {
            if (
                $user
                && $user->status === 'Active'
                && (! $user->locked_until || $user->locked_until->isPast())
            ) {
                $locked = $this->registerFailedAttempt($user);
                $this->logAuthentication(
                    $request,
                    $user,
                    $locked ? 'ACCOUNT_TEMPORARILY_LOCKED' : 'FAILED_LOGIN',
                    $locked
                        ? $user->username.' was temporarily locked after repeated failed login attempts.'
                        : 'A failed login attempt was recorded for '.$user->username.'.'
                );
            }

            return $this->invalidCredentials();
        }

        if ($user->status === 'Locked' && $user->locked_until?->isPast()) {
            $user->forceFill([
                'status' => 'Active',
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();
        }

        if (
            $user->status !== 'Active'
            || ($user->locked_until && $user->locked_until->isFuture())
        ) {
            $this->logAuthentication(
                $request,
                $user,
                'REJECTED_LOGIN',
                'A sign-in attempt was rejected because the account is not active.'
            );

            return $this->invalidCredentials();
        }

        $successfulLoginChanges = [
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ];

        if (Hash::needsRehash($user->password_hash)) {
            $successfulLoginChanges['password_hash'] = Hash::make($validated['password']);
        }

        $user->forceFill($successfulLoginChanges)->save();

        $remember = (bool) ($validated['remember'] ?? false);
        $rememberDuration = max(60, (int) config('auth.remember_duration', 21600));
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $guard->setRememberDuration($rememberDuration);
        $guard->login($user, $remember);
        $request->session()->regenerate();
        $user->accessTokens()->delete();

        $this->logAuthentication(
            $request,
            $user,
            'LOGIN',
            $user->username.' signed in successfully.'
        );

        return response()->json([
            'message' => 'Signed in successfully.',
            'user' => $this->formatUser($user),
            'remembered' => $remember,
            'remember_expires_at' => $remember
                ? now()->addMinutes($rememberDuration)->toISOString()
                : null,
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
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Signed out successfully.',
        ]);
    }

    private function invalidCredentials(): JsonResponse
    {
        return response()->json([
            'message' => 'Unable to sign in with those credentials.',
        ], 401);
    }

    private function registerFailedAttempt(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $lockedUser = User::query()
                ->whereKey($user->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUser->status !== 'Active') {
                return $lockedUser->status === 'Locked';
            }

            $attempts = $lockedUser->failed_login_attempts + 1;
            $changes = ['failed_login_attempts' => $attempts];
            $locked = $attempts >= self::MAX_ATTEMPTS;

            if ($locked) {
                $changes['status'] = 'Locked';
                $changes['locked_until'] = now()->addMinutes(self::LOCK_MINUTES);
            }

            $lockedUser->forceFill($changes)->save();
            $user->forceFill($changes);

            return $locked;
        });
    }

    private function logAuthentication(
        Request $request,
        User $user,
        string $activityType,
        string $description
    ): void {
        ActivityLog::create([
            'user_id' => $user->user_id,
            'activity_type' => $activityType,
            'description' => $description,
            'entity_type' => 'system_users',
            'entity_id' => $user->user_id,
            'ip_address' => ClientIp::for($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'request_id' => RequestId::for($request),
        ]);
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
                'photo_url' => $user->personnel->photo
                    ? route(
                        'personnel.photo',
                        ['personnel' => $user->personnel],
                        config('app.frontend_deployment') === 'external'
                            && ! config('app.frontend_api_proxy')
                    )
                        .'?v='.($user->personnel->updated_at?->timestamp ?? 0)
                    : null,
            ] : null,
        ];
    }
}
