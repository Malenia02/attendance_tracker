<?php

namespace App\Services;

use App\Models\SystemHealthCheck;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SystemHealthService
{
    public function snapshot(): array
    {
        $checks = [
            $this->apiCheck(),
            $this->databaseCheck(),
            $this->schedulerCheck(),
            $this->backupCheck(),
            $this->proxyCheck(),
            $this->storageCheck(),
        ];
        $statuses = collect($checks)->pluck('status');
        $overall = match (true) {
            $statuses->contains('critical') => 'critical',
            $statuses->contains('warning') || $statuses->contains('unknown') => 'warning',
            default => 'healthy',
        };

        return [
            'overall_status' => $overall,
            'checked_at' => now()->toISOString(),
            'timezone' => config('app.timezone'),
            'environment' => app()->environment(),
            'release' => $this->release(),
            'checks' => $checks,
            'summary' => [
                'healthy' => $statuses->filter(fn (string $status): bool => $status === 'healthy')->count(),
                'warning' => $statuses->filter(fn (string $status): bool => in_array($status, ['warning', 'unknown'], true))->count(),
                'critical' => $statuses->filter(fn (string $status): bool => $status === 'critical')->count(),
            ],
        ];
    }

    private function apiCheck(): array
    {
        return $this->check(
            'api',
            'Application API',
            'healthy',
            'The authenticated API is responding normally.'
        );
    }

    private function databaseCheck(): array
    {
        $startedAt = hrtime(true);

        try {
            DB::select('select 1');
            $latency = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return $this->check(
                'database',
                'Database',
                $latency > 1000 ? 'warning' : 'healthy',
                $latency > 1000
                    ? 'The database is reachable, but the response is slower than expected.'
                    : 'The database connection is available.',
                null,
                ['latency_ms' => $latency, 'driver' => config('database.default')]
            );
        } catch (Throwable) {
            return $this->check(
                'database',
                'Database',
                'critical',
                'The database connectivity check failed.'
            );
        }
    }

    private function schedulerCheck(): array
    {
        $record = $this->heartbeat('scheduler');

        if (! $record?->last_success_at) {
            return $this->check(
                'scheduler',
                'Task scheduler',
                'warning',
                'No scheduler heartbeat has been recorded. Confirm that schedule:work is running.'
            );
        }

        $ageSeconds = max(0, $record->last_success_at->diffInSeconds(now()));
        $maximumAge = max(1, (int) config('health.scheduler_max_age_minutes', 3)) * 60;
        $status = match (true) {
            $ageSeconds <= $maximumAge => 'healthy',
            $ageSeconds <= $maximumAge * 3 => 'warning',
            default => 'critical',
        };

        return $this->check(
            'scheduler',
            'Task scheduler',
            $status,
            match ($status) {
                'healthy' => 'Scheduled commands are reporting on time.',
                'warning' => 'The scheduler heartbeat is delayed.',
                default => 'The scheduler heartbeat is stale. Scheduled maintenance may not be running.',
            },
            $record->last_success_at->toISOString(),
            ['age_seconds' => $ageSeconds]
        );
    }

    private function backupCheck(): array
    {
        $configured = strlen(trim((string) config('health.backup_signing_secret'))) >= 32;
        $record = $this->heartbeat('backup');

        if (! $configured) {
            return $this->check(
                'backup',
                'Encrypted database backup',
                'warning',
                'Backup monitoring is not configured. Add the shared heartbeat secret to Render and GitHub.'
            );
        }

        if (! $record?->last_success_at) {
            return $this->check(
                'backup',
                'Encrypted database backup',
                'warning',
                'No verified backup heartbeat has been received yet.'
            );
        }

        $ageSeconds = max(0, $record->last_success_at->diffInSeconds(now()));
        $maximumAge = max(1, (int) config('health.backup_max_age_hours', 36)) * 3600;
        $status = match (true) {
            $ageSeconds <= $maximumAge => 'healthy',
            $ageSeconds <= $maximumAge * 1.5 => 'warning',
            default => 'critical',
        };
        $metadata = collect($record->metadata ?? [])
            ->only(['run_id', 'commit_sha', 'repository'])
            ->all();
        $metadata['age_seconds'] = $ageSeconds;

        return $this->check(
            'backup',
            'Encrypted database backup',
            $status,
            match ($status) {
                'healthy' => 'The latest encrypted backup was restored and verified successfully.',
                'warning' => 'The last verified backup is approaching the allowed age.',
                default => 'The verified backup heartbeat is stale. Check the backup workflow immediately.',
            },
            $record->last_success_at->toISOString(),
            $metadata
        );
    }

    private function proxyCheck(): array
    {
        $enabled = (bool) config('app.frontend_api_proxy');
        $strongSecret = strlen(trim((string) config('security.frontend_proxy_signing_secret'))) >= 32;
        $production = app()->environment('production');
        $status = match (true) {
            $enabled && $strongSecret => 'healthy',
            $enabled => 'critical',
            $production => 'warning',
            default => 'healthy',
        };

        return $this->check(
            'secure_proxy',
            'Secure frontend proxy',
            $status,
            match (true) {
                $enabled && $strongSecret => 'Signed proxy verification is enabled.',
                $enabled => 'Proxy verification is enabled, but its signing secret is not configured securely.',
                $production => 'Signed proxy verification is disabled in production.',
                default => 'Proxy verification is not required in the local environment.',
            }
        );
    }

    private function storageCheck(): array
    {
        $required = [
            storage_path('framework'),
            storage_path('logs'),
            resource_path('templates/DTR-format-1.docx'),
            resource_path('templates/DTR-JO.docx'),
        ];
        $available = collect($required)->every(
            fn (string $path): bool => is_dir($path) ? is_writable($path) : is_file($path)
        );

        return $this->check(
            'storage',
            'Runtime storage and templates',
            $available ? 'healthy' : 'critical',
            $available
                ? 'Writable runtime storage and both DTR templates are available.'
                : 'Required runtime storage or a DTR template is unavailable.'
        );
    }

    private function heartbeat(string $key): ?SystemHealthCheck
    {
        try {
            return SystemHealthCheck::query()->find($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function check(
        string $key,
        string $label,
        string $status,
        string $message,
        ?string $lastSuccessAt = null,
        array $details = []
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'message' => $message,
            'last_success_at' => $lastSuccessAt,
            'details' => $details,
        ];
    }

    private function release(): ?string
    {
        $release = trim((string) config('health.release'));

        return $release === '' ? null : substr($release, 0, 12);
    }
}
