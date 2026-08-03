<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DtrScheduleEligibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-03 14:00:00', 'Asia/Manila'));

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
            $table->string('personnel_type')->default('GIP');
            $table->string('position_title')->nullable();
            $table->date('employment_start_date')->nullable();
            $table->date('employment_end_date')->nullable();
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

        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->id('schedule_id');
            $table->string('schedule_name')->unique();
            $table->time('morning_start');
            $table->time('morning_end');
            $table->time('afternoon_start');
            $table->time('afternoon_end');
            $table->time('morning_time_in_start');
            $table->time('morning_time_in_end');
            $table->time('morning_time_out_start');
            $table->time('morning_time_out_end');
            $table->time('afternoon_time_in_start');
            $table->time('afternoon_time_in_end');
            $table->time('afternoon_time_out_start');
            $table->time('afternoon_time_out_end');
            $table->unsignedSmallInteger('grace_period_minutes')->default(0);
            $table->unsignedSmallInteger('required_minutes_per_day')->default(600);
            $table->boolean('monday')->default(true);
            $table->boolean('tuesday')->default(true);
            $table->boolean('wednesday')->default(true);
            $table->boolean('thursday')->default(true);
            $table->boolean('friday')->default(false);
            $table->boolean('saturday')->default(false);
            $table->boolean('sunday')->default(false);
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        Schema::create('personnel_schedules', function (Blueprint $table): void {
            $table->id('personnel_schedule_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('schedule_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id('attendance_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->date('attendance_date');
            $table->dateTime('morning_time_in')->nullable();
            $table->dateTime('morning_time_out')->nullable();
            $table->dateTime('afternoon_time_in')->nullable();
            $table->dateTime('afternoon_time_out')->nullable();
            $table->string('attendance_status')->default('Incomplete');
            $table->unsignedSmallInteger('total_work_minutes')->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('undertime_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->string('record_source')->default('Manual');
            $table->boolean('is_verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table): void {
            $table->id('holiday_id');
            $table->date('holiday_date');
            $table->string('holiday_name');
            $table->string('holiday_type');
            $table->string('scope')->default('National');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
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
            $table->string('from_status');
            $table->string('to_status');
            $table->string('remarks')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('dtr_reopen_requests', function (Blueprint $table): void {
            $table->id('dtr_reopen_request_id');
            $table->unsignedBigInteger('dtr_certification_id');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reason');
            $table->json('affected_dates');
            $table->string('request_status')->default('Pending');
            $table->string('review_remarks')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('pending_key')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamps();
        });

        Schema::create('dtr_certification_versions', function (Blueprint $table): void {
            $table->id('dtr_certification_version_id');
            $table->unsignedBigInteger('dtr_certification_id');
            $table->unsignedSmallInteger('version_number');
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->dateTime('certified_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->string('archive_reason')->nullable();
            $table->json('certified_snapshot')->nullable();
            $table->char('certified_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('dtr_certification_versions');
        Schema::dropIfExists('dtr_reopen_requests');
        Schema::dropIfExists('dtr_status_logs');
        Schema::dropIfExists('dtr_certifications');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('personnel_schedules');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');
        Schema::dropIfExists('departments');

        parent::tearDown();
    }

    public function test_month_without_schedule_is_setup_required_instead_of_false_rest_days(): void
    {
        [$administrator] = $this->records();

        $this->actingAs($administrator)
            ->getJson('/api/dtr?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data.0.expected_days', 0)
            ->assertJsonPath('data.0.completion_percent', null)
            ->assertJsonPath('data.0.is_ready', false)
            ->assertJsonPath('data.0.dtr_eligibility.code', 'no_schedule')
            ->assertJsonPath('data.0.dtr_eligibility.can_prepare', false)
            ->assertJsonPath('data.0.daily_records', [])
            ->assertJsonPath('summary.schedule_setup_required', 1);
    }

    public function test_partial_schedule_marks_uncovered_dates_and_blocks_readiness(): void
    {
        [$administrator, $personnel] = $this->records();
        $schedule = WorkSchedule::create($this->schedulePayload());

        PersonnelSchedule::create([
            'personnel_id' => $personnel->personnel_id,
            'schedule_id' => $schedule->schedule_id,
            'effective_from' => '2026-08-03',
            'created_by' => $administrator->user_id,
        ]);

        $response = $this->actingAs($administrator)
            ->getJson('/api/dtr?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data.0.dtr_eligibility.code', 'partial_schedule')
            ->assertJsonPath('data.0.dtr_eligibility.can_prepare', false)
            ->assertJsonPath('data.0.dtr_eligibility.not_covered_days', 2)
            ->assertJsonPath('data.0.expected_days', 1)
            ->assertJsonPath('data.0.completion_percent', 0)
            ->assertJsonPath('data.0.is_ready', false);

        $this->assertSame(
            ['Not Covered', 'Not Covered', 'Missing'],
            collect($response->json('data.0.daily_records'))->pluck('status')->all()
        );
    }

    public function test_unscheduled_attendance_is_flagged_but_not_counted_as_dtr_work(): void
    {
        [$administrator, $personnel] = $this->records();

        AttendanceRecord::create([
            'personnel_id' => $personnel->personnel_id,
            'attendance_date' => '2026-08-03',
            'morning_time_in' => '2026-08-03 07:00:00',
            'morning_time_out' => '2026-08-03 12:00:00',
            'afternoon_time_in' => '2026-08-03 13:00:00',
            'afternoon_time_out' => '2026-08-03 18:00:00',
            'attendance_status' => 'Present',
            'total_work_minutes' => 600,
            'is_verified' => true,
        ]);

        $this->actingAs($administrator)
            ->getJson('/api/dtr?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data.0.total_work_minutes', 0)
            ->assertJsonPath('data.0.dtr_eligibility.unscheduled_records', 1)
            ->assertJsonPath('data.0.issues.unscheduled', 1)
            ->assertJsonPath('data.0.daily_records', []);
    }

    public function test_dtr_without_schedule_cannot_be_submitted_or_create_a_draft(): void
    {
        [$administrator, $personnel] = $this->records();

        $this->actingAs($administrator)
            ->patchJson("/api/dtr/{$personnel->personnel_id}/status", [
                'month' => '2026-08',
                'status' => 'Submitted',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'No effective work schedule covers August 2026. Assign a schedule with the correct effective date before preparing this DTR.'
            );

        $this->assertDatabaseCount('dtr_certifications', 0);
    }

    private function records(): array
    {
        $departmentId = Schema::getConnection()->table('departments')->insertGetId([
            'department_code' => 'DILG-ZSP',
            'department_name' => 'DILG Zamboanga Sibugay Provincial Office',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $personnel = Personnel::create([
            'department_id' => $departmentId,
            'employee_number' => 'GIP-DILG-ZSP-2026-0006',
            'first_name' => 'Aemon',
            'last_name' => 'Targaryen',
            'personnel_type' => 'GIP',
            'status' => 'Active',
        ]);
        $administrator = User::create([
            'username' => 'dtr-admin',
            'password_hash' => 'test',
            'user_role' => 'Administrator',
            'status' => 'Active',
        ]);

        return [$administrator, $personnel];
    }

    private function schedulePayload(): array
    {
        return [
            'schedule_name' => 'DILG GIP Compressed Workweek',
            'morning_start' => '07:00',
            'morning_end' => '12:00',
            'afternoon_start' => '13:00',
            'afternoon_end' => '18:00',
            'morning_time_in_start' => '05:00',
            'morning_time_in_end' => '11:59',
            'morning_time_out_start' => '11:30',
            'morning_time_out_end' => '12:59',
            'afternoon_time_in_start' => '12:00',
            'afternoon_time_in_end' => '17:59',
            'afternoon_time_out_start' => '17:30',
            'afternoon_time_out_end' => '23:59',
            'grace_period_minutes' => 0,
            'required_minutes_per_day' => 600,
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => false,
            'saturday' => false,
            'sunday' => false,
            'status' => 'Active',
        ];
    }
}
