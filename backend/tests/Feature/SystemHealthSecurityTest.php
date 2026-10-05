<?php

namespace Tests\Feature;

use App\Models\SystemHealthCheck;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemHealthSecurityTest extends TestCase
{
    private string $backupSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupSecret = str_repeat('b', 64);
        config()->set('health.backup_signing_secret', $this->backupSecret);
        config()->set('health.backup_signature_ttl_seconds', 300);
        config()->set('app.frontend_api_proxy', false);

        Schema::create('personnel', function (Blueprint $table): void {
            $table->id('personnel_id');
            $table->date('employment_end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->unsignedBigInteger('personnel_id')->nullable();
            $table->string('username')->unique();
            $table->string('password_hash')->default('test');
            $table->string('user_role');
            $table->string('status')->default('Active');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('system_health_checks', function (Blueprint $table): void {
            $table->string('check_key', 50)->primary();
            $table->string('status', 20)->default('healthy');
            $table->timestamp('last_success_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('system_health_checks');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');

        parent::tearDown();
    }

    public function test_only_an_administrator_can_view_sanitized_system_health(): void
    {
        $administrator = $this->createUser('health-admin', 'Administrator');
        $hr = $this->createUser('health-hr', 'HR');

        SystemHealthCheck::recordSuccess('scheduler');
        SystemHealthCheck::recordSuccess('backup', [
            'run_id' => '12345',
            'commit_sha' => 'abcdef123456',
            'repository' => 'example/attendance',
            'private_value' => 'must-not-leak',
        ]);

        $response = $this->actingAs($administrator)
            ->getJson('/api/system-health')
            ->assertOk()
            ->assertJsonPath('overall_status', 'healthy')
            ->assertJsonPath('summary.critical', 0)
            ->assertJsonPath('checks.0.key', 'api')
            ->assertJsonPath('checks.2.key', 'scheduler')
            ->assertJsonPath('checks.3.key', 'backup')
            ->assertJsonPath('checks.3.details.run_id', '12345')
            ->assertJsonMissingPath('checks.3.details.private_value');

        $payload = $response->getContent();
        $this->assertStringNotContainsString($this->backupSecret, $payload);
        $this->assertStringNotContainsString('must-not-leak', $payload);

        $this->actingAs($hr)
            ->getJson('/api/system-health')
            ->assertForbidden();
    }

    public function test_scheduler_command_records_a_persistent_heartbeat(): void
    {
        $this->artisan('system:heartbeat')->assertSuccessful();

        $this->assertDatabaseHas('system_health_checks', [
            'check_key' => 'scheduler',
            'status' => 'healthy',
        ]);
    }

    public function test_scheduler_heartbeat_cannot_be_blocked_by_a_stale_overlap_lock(): void
    {
        $heartbeat = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'system:heartbeat'));

        $this->assertNotNull($heartbeat);
        $this->assertFalse($heartbeat->withoutOverlapping);
    }

    public function test_backup_heartbeat_requires_a_recent_valid_hmac_signature(): void
    {
        $body = json_encode([
            'run_id' => '67890',
            'commit_sha' => 'abcdef1234567890abcdef1234567890abcdef12',
            'repository' => 'Malenia02/attendance_tracker',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        $this->heartbeatRequest($body, $timestamp, str_repeat('0', 64))
            ->assertForbidden();

        $signature = hash_hmac('sha256', $timestamp."\n".$body, $this->backupSecret);

        $this->heartbeatRequest($body, $timestamp, $signature)
            ->assertStatus(202)
            ->assertJsonPath('status', 'recorded');

        $this->assertDatabaseHas('system_health_checks', [
            'check_key' => 'backup',
            'status' => 'healthy',
        ]);
        $this->assertSame(
            '67890',
            SystemHealthCheck::query()->findOrFail('backup')->metadata['run_id']
        );
    }

    public function test_expired_backup_heartbeat_is_rejected(): void
    {
        $body = json_encode([
            'run_id' => 'expired-run',
            'commit_sha' => 'abcdef123456',
            'repository' => 'Malenia02/attendance_tracker',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->subMinutes(10)->timestamp;
        $signature = hash_hmac('sha256', $timestamp."\n".$body, $this->backupSecret);

        $this->heartbeatRequest($body, $timestamp, $signature)
            ->assertForbidden();

        $this->assertDatabaseMissing('system_health_checks', [
            'check_key' => 'backup',
        ]);
    }

    private function heartbeatRequest(string $body, string $timestamp, string $signature)
    {
        return $this->call(
            'POST',
            '/health/backup-heartbeat',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_DILG_BACKUP_TIMESTAMP' => $timestamp,
                'HTTP_X_DILG_BACKUP_SIGNATURE' => $signature,
            ],
            $body
        );
    }

    private function createUser(string $username, string $role): User
    {
        return User::create([
            'username' => $username,
            'password_hash' => 'not-used',
            'user_role' => $role,
            'status' => 'Active',
        ]);
    }
}
