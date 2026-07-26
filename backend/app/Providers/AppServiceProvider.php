<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    }
}
