<?php

namespace Tests\Feature;

use App\Models\DtrCertification;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DtrReopeningSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('attendance.dtr_signing_key', 'test-dtr-signing-key');

        Schema::create('departments', function (Blueprint $table): void {
            $table->id('department_id');
            $table->string('department_code');
            $table->string('department_name');
            $table->timestamps();
        });

        Schema::create('personnel', function (Blueprint $table): void {
            $table->id('personnel_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('employee_number')->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->unsignedBigInteger('personnel_id')->nullable()->unique();
            $table->string('username')->unique();
            $table->string('password_hash')->default('test');
            $table->string('user_role')->default('Personnel');
            $table->string('status')->default('Active');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dtr_certifications', function (Blueprint $table): void {
            $table->id('dtr_certification_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedSmallInteger('dtr_year');
            $table->unsignedTinyInteger('dtr_month');
            $table->unsignedSmallInteger('version_number')->default(1);
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->unsignedBigInteger('certified_by')->nullable();
            $table->dateTime('prepared_at')->nullable();
            $table->dateTime('certified_at')->nullable();
            $table->string('certification_status')->default('Draft');
            $table->string('remarks')->nullable();
            $table->json('certified_snapshot')->nullable();
            $table->char('certified_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('dtr_status_logs', function (Blueprint $table): void {
            $table->id('dtr_status_log_id');
            $table->unsignedBigInteger('dtr_certification_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('remarks')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('dtr_certification_versions', function (Blueprint $table): void {
            $table->id('dtr_certification_version_id');
            $table->unsignedBigInteger('dtr_certification_id');
            $table->unsignedSmallInteger('version_number');
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->unsignedBigInteger('certified_by')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->dateTime('prepared_at')->nullable();
            $table->dateTime('certified_at');
            $table->dateTime('archived_at');
            $table->string('archive_reason', 1000);
            $table->json('certified_snapshot');
            $table->char('certified_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['dtr_certification_id', 'version_number']);
        });

        Schema::create('dtr_reopen_requests', function (Blueprint $table): void {
            $table->id('dtr_reopen_request_id');
            $table->unsignedBigInteger('dtr_certification_id');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reason', 1000);
            $table->json('affected_dates');
            $table->string('request_status')->default('Pending');
            $table->string('review_remarks', 1000)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('pending_key', 64)->nullable()->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->unique();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id('activity_log_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('activity_type', 100);
            $table->string('description', 500);
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('dtr_reopen_requests');
        Schema::dropIfExists('dtr_certification_versions');
        Schema::dropIfExists('dtr_status_logs');
        Schema::dropIfExists('dtr_certifications');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');
        Schema::dropIfExists('departments');

        parent::tearDown();
    }

    public function test_independent_administrator_approval_archives_the_signed_version(): void
    {
        [$personnel, $certification] = $this->createCertifiedDtr();
        $hr = $this->createUser('hr-requester', 'HR');
        $administrator = $this->createUser('admin-reviewer', 'Administrator');

        $requestId = $this->actingAs($hr)
            ->postJson("/api/dtr/{$personnel->personnel_id}/reopen-requests", [
                'month' => '2026-07',
                'reason' => 'The afternoon time-out on July 10 was certified incorrectly.',
                'affected_dates' => ['2026-07-10'],
            ])
            ->assertCreated()
            ->assertJsonPath('reopen_request.status', 'Pending')
            ->json('reopen_request.id');

        $this->actingAs($administrator)
            ->patchJson("/api/dtr/reopen-requests/{$requestId}/review", [
                'decision' => 'Approved',
            ])
            ->assertOk();

        $certification->refresh();
        $this->assertSame('Reopened', $certification->certification_status);
        $this->assertSame(2, $certification->version_number);
        $this->assertNull($certification->certified_snapshot);
        $this->assertNull($certification->certified_hash);
        $this->assertDatabaseHas('dtr_certification_versions', [
            'dtr_certification_id' => $certification->dtr_certification_id,
            'version_number' => 1,
            'archived_by' => $administrator->user_id,
        ]);
        $this->assertDatabaseHas('dtr_status_logs', [
            'dtr_certification_id' => $certification->dtr_certification_id,
            'from_status' => 'Certified',
            'to_status' => 'Reopened',
        ]);
        $this->assertDatabaseHas('dtr_reopen_requests', [
            'dtr_reopen_request_id' => $requestId,
            'request_status' => 'Approved',
            'reviewed_by' => $administrator->user_id,
            'pending_key' => null,
        ]);

        $this->actingAs($hr)
            ->postJson('/api/attendance/correction', [
                'personnel_id' => $personnel->personnel_id,
                'attendance_date' => '2026-07-11',
                'record_type' => 'Absent',
                'reason' => 'Attempting to change a date outside the approved reopening scope.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'This date was not included in the approved DTR reopening request.'
            );
    }

    public function test_requester_cannot_approve_their_own_reopening_request(): void
    {
        [$personnel, $certification] = $this->createCertifiedDtr();
        $administrator = $this->createUser('admin-requester', 'Administrator');

        $requestId = $this->actingAs($administrator)
            ->postJson("/api/dtr/{$personnel->personnel_id}/reopen-requests", [
                'month' => '2026-07',
                'reason' => 'The July 10 attendance record requires an authorized amendment.',
                'affected_dates' => ['2026-07-10'],
            ])
            ->assertCreated()
            ->json('reopen_request.id');

        $this->actingAs($administrator)
            ->patchJson("/api/dtr/reopen-requests/{$requestId}/review", [
                'decision' => 'Approved',
            ])
            ->assertForbidden();

        $this->assertSame('Certified', $certification->fresh()->certification_status);
        $this->assertDatabaseHas('dtr_reopen_requests', [
            'dtr_reopen_request_id' => $requestId,
            'request_status' => 'Pending',
        ]);
        $this->assertDatabaseCount('dtr_certification_versions', 0);
    }

    public function test_tampered_certified_snapshot_blocks_reopening(): void
    {
        [$personnel, $certification] = $this->createCertifiedDtr();
        $hr = $this->createUser('hr-tamper-request', 'HR');
        $administrator = $this->createUser('admin-integrity-review', 'Administrator');
        $certification->forceFill(['certified_hash' => str_repeat('0', 64)])->save();

        $requestId = $this->actingAs($hr)
            ->postJson("/api/dtr/{$personnel->personnel_id}/reopen-requests", [
                'month' => '2026-07',
                'reason' => 'The July 10 attendance record appears to require a correction.',
                'affected_dates' => ['2026-07-10'],
            ])
            ->assertCreated()
            ->json('reopen_request.id');

        $this->actingAs($administrator)
            ->patchJson("/api/dtr/reopen-requests/{$requestId}/review", [
                'decision' => 'Approved',
            ])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'The certified DTR integrity check failed. Reopening was blocked.'
            );

        $this->assertSame('Certified', $certification->fresh()->certification_status);
        $this->assertDatabaseCount('dtr_certification_versions', 0);
    }

    private function createCertifiedDtr(): array
    {
        $personnel = Personnel::create([
            'employee_number' => 'GIP-2026-001',
            'first_name' => 'Test',
            'last_name' => 'Personnel',
            'status' => 'Active',
        ]);
        $preparer = $this->createUser('dtr-preparer-'.uniqid(), 'HR');
        $certifier = $this->createUser('dtr-certifier-'.uniqid(), 'Administrator');
        $snapshot = [
            'personnel_id' => $personnel->personnel_id,
            'month_label' => 'July 2026',
            'daily_records' => [['date' => '2026-07-10', 'status' => 'Present']],
        ];
        $encoded = json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $certification = DtrCertification::create([
            'personnel_id' => $personnel->personnel_id,
            'dtr_year' => 2026,
            'dtr_month' => 7,
            'version_number' => 1,
            'prepared_by' => $preparer->user_id,
            'certified_by' => $certifier->user_id,
            'prepared_at' => now()->subDay(),
            'certified_at' => now()->subHour(),
            'certification_status' => 'Certified',
            'certified_snapshot' => $snapshot,
            'certified_hash' => hash_hmac('sha256', $encoded, 'test-dtr-signing-key'),
        ]);

        return [$personnel, $certification];
    }

    private function createUser(string $username, string $role): User
    {
        return User::create([
            'username' => $username,
            'password_hash' => 'test',
            'user_role' => $role,
            'status' => 'Active',
        ]);
    }
}
