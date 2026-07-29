<?php

namespace App\Providers;

use App\Models\AttendanceRecord;
use App\Models\Personnel;
use App\Policies\AttendanceRecordPolicy;
use App\Policies\PersonnelPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(AttendanceRecord::class, AttendanceRecordPolicy::class);
        Gate::policy(Personnel::class, PersonnelPolicy::class);

        TrustProxies::at(config('app.trusted_proxies'));

        RateLimiter::for('login', function (Request $request): array {
            $username = Str::lower(trim((string) $request->input('username')));
            $usernameHash = hash('sha256', $username);

            return [
                Limit::perMinute(5)->by(
                    'login-user:'.$usernameHash.'|'.$request->ip()
                ),
                Limit::perMinute(60)->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            'api:'.($request->user()?->getAuthIdentifier() ?? $request->ip())
        )
        );

        RateLimiter::for('qr-scan', fn (Request $request): array => [
            Limit::perMinute(30)->by('qr-user:'.$request->user()->getAuthIdentifier()),
            Limit::perMinute(60)->by('qr-ip:'.$request->ip()),
        ]);

        RateLimiter::for('qr-challenge', fn (Request $request): array => [
            Limit::perMinute(60)->by('qr-challenge-user:'.$request->user()->getAuthIdentifier()),
            Limit::perMinute(120)->by('qr-challenge-ip:'.$request->ip()),
        ]);
    }
}
