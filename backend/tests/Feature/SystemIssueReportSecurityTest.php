<?php

namespace Tests\Feature;

use App\Models\SystemIssueReport;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SystemIssueReportSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personnel', function (Blueprint $table): void {
            $table->id('personnel_id');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->date('employment_end_date')->nullable();
            $table->timestamps();
        });
        Schema::create('system_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->unsignedBigInteger('personnel_id')->nullable();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->string('user_role');
            $table->string('status')->default('Active');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });
        Schema::create('system_issue_reports', function (Blueprint $table): void {
            $table->id('issue_report_id');
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->string('reporter_name', 160);
            $table->string('category', 40);
            $table->string('subject', 120);
            $table->text('description');
            $table->string('page_url', 500)->nullable();
            $table->string('status', 20)->default('Open');
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id('activity_log_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('activity_type', 100);
            $table->string('description', 500);
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('system_issue_reports');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');

        parent::tearDown();
    }

    public function test_authenticated_user_can_submit_a_valid_issue_report(): void
    {
        $user = $this->user('issue-personnel', 'Personnel');

        $this->actingAs($user)
            ->postJson('/api/issue-reports', [
                'category' => 'Attendance',
                'subject' => 'Time entry button did not respond',
                'description' => 'The time entry button remained in a loading state after I selected it.',
                'page_url' => '/attendance?date=2026-10-29',
                'status' => 'Resolved',
                'reported_by' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('issue_report.status', 'Open')
            ->assertJsonPath('issue_report.reporter_name', 'issue-personnel');

        $this->assertDatabaseHas('system_issue_reports', [
            'reported_by' => $user->user_id,
            'status' => 'Open',
            'subject' => 'Time entry button did not respond',
        ]);
    }

    public function test_users_only_see_their_own_reports_while_administrator_sees_all(): void
    {
        $userA = $this->user('reporter-a', 'Personnel');
        $userB = $this->user('reporter-b', 'Personnel');
        $administrator = $this->user('report-admin', 'Administrator');
        $this->report($userA, 'First private report');
        $this->report($userB, 'Second private report');

        $this->actingAs($userA)
            ->getJson('/api/issue-reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'First private report')
            ->assertJsonPath('meta.can_review', false);

        $this->actingAs($administrator)
            ->getJson('/api/issue-reports')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.can_review', true);
    }

    public function test_only_administrator_can_change_report_status(): void
    {
        $reporter = $this->user('status-reporter', 'Personnel');
        $administrator = $this->user('status-admin', 'Administrator');
        $report = $this->report($reporter, 'Review this report');

        $this->actingAs($reporter)
            ->patchJson("/api/issue-reports/{$report->issue_report_id}", [
                'status' => 'Resolved',
            ])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->patchJson("/api/issue-reports/{$report->issue_report_id}", [
                'status' => 'In Progress',
                'admin_notes' => 'The administrator is checking the affected attendance flow.',
            ])
            ->assertOk()
            ->assertJsonPath('issue_report.status', 'In Progress');

        $this->assertDatabaseHas('system_issue_reports', [
            'issue_report_id' => $report->issue_report_id,
            'reviewed_by' => $administrator->user_id,
            'status' => 'In Progress',
        ]);
    }

    public function test_report_validation_rejects_external_urls_and_unbounded_page_sizes(): void
    {
        $user = $this->user('validation-reporter', 'Personnel');

        $this->actingAs($user)
            ->postJson('/api/issue-reports', [
                'category' => 'Other',
                'subject' => 'External page test',
                'description' => 'This report contains an invalid external page address.',
                'page_url' => 'https://example.com/unsafe',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page_url');

        $this->actingAs($user)
            ->getJson('/api/issue-reports?per_page=500')
            ->assertUnprocessable();
    }

    public function test_unauthenticated_issue_report_requests_are_rejected(): void
    {
        $this->getJson('/api/issue-reports')->assertUnauthorized();
        $this->postJson('/api/issue-reports', [])->assertUnauthorized();
    }

    private function user(string $username, string $role): User
    {
        return User::create([
            'username' => $username,
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => $role,
            'status' => 'Active',
        ]);
    }

    private function report(User $user, string $subject): SystemIssueReport
    {
        return SystemIssueReport::query()->create([
            'reported_by' => $user->user_id,
            'reporter_name' => $user->username,
            'category' => 'Other',
            'subject' => $subject,
            'description' => 'A sufficiently detailed description for the system issue report.',
            'page_url' => '/dashboard',
            'status' => 'Open',
        ]);
    }
}
