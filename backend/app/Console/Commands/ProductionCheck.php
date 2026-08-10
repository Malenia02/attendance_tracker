<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProductionCheck extends Command
{
    protected $signature = 'production:check {--skip-database : Skip database connectivity and schema checks}';

    protected $description = 'Validate security, runtime, storage, build, and database production requirements';

    public function handle(): int
    {
        $errors = [];
        $warnings = [];

        $this->checkConfiguration($errors, $warnings);
        $this->checkRuntime($errors);
        $this->checkFiles($errors);

        if (! $this->option('skip-database')) {
            $this->checkDatabase($errors);
        }

        if ($errors === []) {
            $this->components->info('Production readiness checks passed.');
        } else {
            $this->components->error('Production readiness checks failed.');

            foreach ($errors as $error) {
                $this->line('  [ERROR] '.$error);
            }
        }

        foreach ($warnings as $warning) {
            $this->line('  [WARN]  '.$warning);
        }

        $this->newLine();
        $this->table(
            ['Check group', 'Result'],
            [
                ['Application security', $errors === [] ? 'PASS' : 'Review errors above'],
                ['PHP runtime', $this->extensionsAvailable() ? 'PASS' : 'FAIL'],
                ['Frontend deployment', $this->frontendReady() ? 'PASS' : 'FAIL'],
                ['Database', $this->option('skip-database') ? 'SKIPPED' : ($this->databaseReady() ? 'PASS' : 'FAIL')],
            ]
        );

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function checkConfiguration(array &$errors, array &$warnings): void
    {
        if (! app()->environment('production')) {
            $errors[] = 'APP_ENV must be production.';
        }

        if (config('app.debug')) {
            $errors[] = 'APP_DEBUG must be false.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $errors[] = 'APP_URL must use HTTPS.';
        }

        $this->checkFrontendConfiguration($errors);

        $appKey = (string) config('app.key');
        $dtrKey = (string) config('attendance.dtr_signing_key');
        $qrKey = (string) config('attendance.qr_signing_key');

        if (! $this->strongSecret($appKey)) {
            $errors[] = 'APP_KEY must contain at least 32 random bytes.';
        }

        if (! $this->strongSecret($dtrKey) || hash_equals($appKey, $dtrKey)) {
            $errors[] = 'DTR_SIGNING_KEY must be a dedicated random secret of at least 32 bytes.';
        }

        if (
            ! $this->strongSecret($qrKey)
            || hash_equals($appKey, $qrKey)
            || hash_equals($dtrKey, $qrKey)
        ) {
            $errors[] = 'QR_SIGNING_KEY must be a different random secret of at least 32 bytes.';
        }

        if (! config('session.secure')) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true.';
        }

        if (! config('session.http_only')) {
            $errors[] = 'SESSION_HTTP_ONLY must be true.';
        }

        if (! config('session.encrypt')) {
            $errors[] = 'SESSION_ENCRYPT must be true.';
        }

        if (! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $errors[] = 'SESSION_SAME_SITE must be lax or strict.';
        }

        if (config('app.timezone') !== 'Asia/Manila') {
            $errors[] = 'APP_TIMEZONE must be Asia/Manila for attendance calculations.';
        }

        if (! in_array(config('database.default'), ['mysql', 'mariadb'], true)) {
            $errors[] = 'DB_CONNECTION must be mysql or mariadb in production; SQLite is not supported by the production image.';
        }

        if (config('app.frontend_api_proxy')
            && ! $this->strongSecret((string) config('security.frontend_proxy_signing_secret'))) {
            $errors[] = 'FRONTEND_PROXY_SIGNING_SECRET must match Vercel and contain at least 32 random bytes.';
        }

        if (config('app.trusted_proxies') === '*' && ! config('app.frontend_api_proxy')) {
            $errors[] = 'TRUSTED_PROXIES=* requires the signed frontend API proxy to prevent client-IP spoofing.';
        }

        if (config('queue.default') !== 'sync') {
            $warnings[] = 'A persistent queue driver requires a supervised queue worker on the host.';
        }

        if (! app()->configurationIsCached()) {
            $warnings[] = 'Run php artisan config:cache after the final environment values are installed.';
        }

        if (! app()->routesAreCached()) {
            $warnings[] = 'Run php artisan route:cache during deployment.';
        }
    }

    private function checkRuntime(array &$errors): void
    {
        foreach ($this->requiredExtensions() as $extension) {
            if (! extension_loaded($extension)) {
                $errors[] = "Required PHP extension is missing: {$extension}.";
            }
        }
    }

    private function checkFiles(array &$errors): void
    {
        foreach ([storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')] as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $errors[] = "Directory must exist and be writable: {$path}.";
            }
        }

        if (! is_file(resource_path('templates/DTR-format-1.docx'))) {
            $errors[] = 'The official DTR Word template is missing.';
        }

        $mysqlSchema = database_path('schema/mysql-schema.sql');

        if (
            is_file($mysqlSchema)
            && str_contains((string) file_get_contents($mysqlSchema), 'DEFINER=``')
        ) {
            $errors[] = 'The MySQL schema contains an invalid empty view definer.';
        }

        if (
            config('app.frontend_deployment') === 'embedded'
            && ! is_file(public_path('app/index.html'))
        ) {
            $errors[] = 'The React production build is missing; run npm run build:laravel.';
        }
    }

    private function checkDatabase(array &$errors): void
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $errors[] = 'The configured database connection is unavailable.';

            return;
        }

        $requiredTables = [
            'system_users',
            'personnel',
            'departments',
            'holidays',
            'work_schedules',
            'personnel_schedules',
            'attendance_records',
            'attendance_change_logs',
            'attendance_correction_requests',
            'dtr_certifications',
            'dtr_certification_versions',
            'dtr_reopen_requests',
            'activity_logs',
            'attendance_qr_tokens',
            'qr_scan_logs',
            'office_networks',
            'time_logs',
        ];

        if (config('session.driver') === 'database') {
            $requiredTables[] = 'sessions';
        }

        if (config('cache.default') === 'database') {
            $requiredTables[] = 'cache';
        }

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $errors[] = "Required database table is missing: {$table}.";
            }
        }

        $requiredColumns = [
            'activity_logs' => ['request_id'],
            'qr_scan_logs' => ['scanned_by', 'qr_token_id', 'office_network_id', 'location_verification_method'],
            'holidays' => ['scope_department_key'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            if (Schema::hasTable($table) && ! Schema::hasColumns($table, $columns)) {
                $errors[] = "Security migration columns are missing from {$table}: "
                    .implode(', ', $columns).'.';
            }
        }

        $requiredIndexes = [
            'personnel' => 'uq_personnel_email',
            'time_logs' => 'uq_time_logs_attendance_type',
            'holidays' => 'uq_holiday_date_scope_type',
            'office_networks' => 'uq_office_network_department_ip',
        ];

        foreach ($requiredIndexes as $table => $index) {
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, $index)) {
                $errors[] = "Required database index is missing: {$index}.";
            }
        }
    }

    private function databaseReady(): bool
    {
        if ($this->option('skip-database')) {
            return false;
        }

        try {
            DB::select('select 1');

            return Schema::hasTable('system_users')
                && Schema::hasTable('attendance_records')
                && Schema::hasColumn('activity_logs', 'request_id')
                && Schema::hasIndex('time_logs', 'uq_time_logs_attendance_type');
        } catch (Throwable) {
            return false;
        }
    }

    private function extensionsAvailable(): bool
    {
        return collect($this->requiredExtensions())
            ->every(fn (string $extension) => extension_loaded($extension));
    }

    private function requiredExtensions(): array
    {
        $extensions = [
            'ctype',
            'dom',
            'fileinfo',
            'filter',
            'hash',
            'mbstring',
            'openssl',
            'pdo',
            'session',
            'tokenizer',
            'xml',
            'zip',
        ];

        $extensions[] = match (config('database.default')) {
            'pgsql' => 'pdo_pgsql',
            default => 'pdo_mysql',
        };

        return $extensions;
    }

    private function checkFrontendConfiguration(array &$errors): void
    {
        $deployment = (string) config('app.frontend_deployment');

        if (! in_array($deployment, ['embedded', 'external'], true)) {
            $errors[] = 'FRONTEND_DEPLOYMENT must be embedded or external.';

            return;
        }

        if ($deployment !== 'external') {
            return;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $frontendHost = parse_url($frontendUrl, PHP_URL_HOST);
        $apiHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! str_starts_with($frontendUrl, 'https://') || ! is_string($frontendHost)) {
            $errors[] = 'FRONTEND_URL must be a valid HTTPS URL for an external frontend.';

            return;
        }

        if (! in_array($frontendUrl, config('cors.allowed_origins', []), true)) {
            $errors[] = 'CORS_ALLOWED_ORIGINS must include the exact FRONTEND_URL.';
        }

        if (! in_array($frontendHost, config('sanctum.stateful', []), true)) {
            $errors[] = 'SANCTUM_STATEFUL_DOMAINS must include the frontend hostname.';
        }

        $cookieDomain = ltrim((string) config('session.domain'), '.');

        if (config('app.frontend_api_proxy')) {
            if ($cookieDomain !== '') {
                $errors[] = 'SESSION_DOMAIN must be empty when FRONTEND_API_PROXY is true so the proxy issues host-only cookies.';
            }

            return;
        }

        if (
            $cookieDomain === ''
            || ! is_string($apiHost)
            || ! $this->hostUsesCookieDomain($frontendHost, $cookieDomain)
            || ! $this->hostUsesCookieDomain($apiHost, $cookieDomain)
        ) {
            $errors[] = 'SESSION_DOMAIN must be a shared parent domain of FRONTEND_URL and APP_URL.';
        }
    }

    private function hostUsesCookieDomain(string $host, string $cookieDomain): bool
    {
        return $host === $cookieDomain
            || str_ends_with($host, '.'.$cookieDomain);
    }

    private function frontendReady(): bool
    {
        return config('app.frontend_deployment') === 'external'
            ? str_starts_with((string) config('app.frontend_url'), 'https://')
            : is_file(public_path('app/index.html'));
    }

    private function strongSecret(string $secret): bool
    {
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            return is_string($decoded)
                && strlen($decoded) >= 32
                && count(array_unique(str_split($decoded))) >= 12;
        }

        return strlen($secret) >= 32
            && count(array_unique(str_split($secret))) >= 12;
    }
}
