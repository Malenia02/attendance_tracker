<?php

namespace Tests\Feature;

use App\Models\LeaveRecord;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeaveRequestWorkflowSecurityTest extends TestCase
{
    private int $departmentId;

    private WorkSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-30 09:00:00');

        Schema::create('departments', function (Blueprint $table): void {
            $table->id('department_id');
            $table->string('department_code');
            $table->string('department_name');
            $table->string('status')->default('Active');
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
            $table->string('user_role');
            $table->string('status')->default('Active');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->increments('schedule_id');
            $table->string('schedule_name');
            $table->time('morning_start')->default('07:00');
            $table->time('morning_end')->default('12:00');
            $table->time('afternoon_start')->default('13:00');
            $table->time('afternoon_end')->default('18:00');
            $table->time('morning_time_in_start')->default('05:00');
            $table->time('morning_time_in_end')->default('11:00');
            $table->time('morning_time_out_start')->default('11:30');
            $table->time('morning_time_out_end')->default('12:30');
            $table->time('afternoon_time_in_start')->default('12:30');
            $table->time('afternoon_time_in_end')->default('14:00');
            $table->time('afternoon_time_out_start')->default('17:30');
            $table->time('afternoon_time_out_end')->default('20:00');
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
            $table->unsignedInteger('schedule_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('holidays', function (Blueprint $table): void {
            $table->id('holiday_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('holiday_name');
            $table->date('holiday_date');
            $table->string('holiday_type');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('leave_records', function (Blueprint $table): void {
            $table->id('leave_id');
            $table->string('request_number')->nullable()->unique();
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->string('leave_type');
            $table->string('day_part')->default('Full Day');
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('total_days', 5, 2)->default(1);
            $table->text('reason')->nullable();
            $table->string('supporting_document', 500)->nullable();
            $table->string('approval_status')->default('Pending');
            $table->string('review_remarks', 1000)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('leave_request_logs', function (Blueprint $table): void {
            $table->id('leave_request_log_id');
            $table->unsignedBigInteger('leave_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('remarks', 1000)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id('attendance_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedInteger('schedule_id')->nullable();
            $table->unsignedBigInteger('leave_record_id')->nullable();
            $table->date('attendance_date');
            $table->dateTime('morning_time_in')->nullable();
            $table->dateTime('morning_time_out')->nullable();
            $table->dateTime('afternoon_time_in')->nullable();
            $table->dateTime('afternoon_time_out')->nullable();
            $table->dateTime('overtime_time_in')->nullable();
            $table->dateTime('overtime_time_out')->nullable();
            $table->string('attendance_status')->default('Incomplete');
            $table->unsignedInteger('total_work_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('remarks')->nullable();
            $table->string('record_source')->default('Web Portal');
            $table->boolean('is_verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['personnel_id', 'attendance_date']);
        });
        Schema::create('dtr_certifications', function (Blueprint $table): void {
            $table->id('dtr_certification_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedSmallInteger('dtr_year');
            $table->unsignedTinyInteger('dtr_month');
            $table->string('certification_status')->default('Draft');
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
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->departmentId = DB::table('departments')->insertGetId([
            'department_code' => 'DILG-TEST',
            'department_name' => 'Test Office',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->schedule = WorkSchedule::create([
            'schedule_name' => 'Compressed Workweek',
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => false,
            'saturday' => false,
            'sunday' => false,
            'status' => 'Active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach ([
            'activity_logs',
            'dtr_certifications',
            'attendance_records',
            'leave_request_logs',
            'leave_records',
            'holidays',
            'personnel_schedules',
            'work_schedules',
            'system_users',
            'personnel',
            'departments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_personnel_can_submit_a_paginated_self_scoped_request(): void
    {
        [$personnel, $user] = $this->personnelUser('Personnel', 'submitter');

        $this->actingAs($user)
            ->postJson('/api/leave-requests', $this->requestPayload())
            ->assertCreated()
            ->assertJsonPath('data.personnel_id', $personnel->personnel_id)
            ->assertJsonPath('data.status', 'Pending')
            ->assertJsonPath('data.total_days', 2);

        $this->assertDatabaseHas('leave_request_logs', [
            'to_status' => 'Pending',
            'changed_by' => $user->user_id,
        ]);
        $this->actingAs($user)
            ->getJson('/api/leave-requests?page=1&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_supervisor_can_approve_department_request_and_attendance_is_created(): void
    {
        [$personnel, $employee] = $this->personnelUser('Personnel', 'employee');
        [, $supervisor] = $this->personnelUser('Supervisor', 'supervisor', false);
        $leaveId = $this->actingAs($employee)
            ->postJson('/api/leave-requests', $this->requestPayload())
            ->assertCreated()
            ->json('data.leave_id');

        $this->actingAs($supervisor)
            ->patchJson("/api/leave-requests/{$leaveId}/review", [
                'decision' => 'Approved',
                'remarks' => 'Approved for the stated purpose.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved');

        $this->assertSame(2, DB::table('attendance_records')
            ->where('personnel_id', $personnel->personnel_id)
            ->where('leave_record_id', $leaveId)
            ->where('attendance_status', 'Leave')
            ->where('is_verified', true)
            ->count());
    }

    public function test_non_reviewers_and_self_review_are_rejected(): void
    {
        [, $employee] = $this->personnelUser('Personnel', 'self-review');
        $leaveId = $this->actingAs($employee)
            ->postJson('/api/leave-requests', $this->requestPayload())
            ->assertCreated()
            ->json('data.leave_id');

        $this->actingAs($employee)
            ->patchJson("/api/leave-requests/{$leaveId}/review", [
                'decision' => 'Approved',
            ])
            ->assertForbidden();
    }

    public function test_overlap_is_blocked_and_cancellation_removes_only_generated_attendance(): void
    {
        [$personnel, $employee] = $this->personnelUser('Personnel', 'cancel');
        [, $administrator] = $this->personnelUser('Administrator', 'admin', false);
        $leaveId = $this->actingAs($employee)
            ->postJson('/api/leave-requests', $this->requestPayload())
            ->assertCreated()
            ->json('data.leave_id');

        $this->actingAs($employee)
            ->postJson('/api/leave-requests', $this->requestPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');

        $this->actingAs($administrator)
            ->patchJson("/api/leave-requests/{$leaveId}/review", [
                'decision' => 'Approved',
            ])
            ->assertOk();

        $this->actingAs($employee)
            ->patchJson("/api/leave-requests/{$leaveId}/cancel", [
                'reason' => 'The planned absence is no longer necessary.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Cancelled');

        $this->assertDatabaseMissing('attendance_records', [
            'personnel_id' => $personnel->personnel_id,
            'leave_record_id' => $leaveId,
        ]);
    }

    public function test_large_request_register_is_database_paginated(): void
    {
        [, $administrator] = $this->personnelUser('Administrator', 'page-admin');
        [$personnel] = $this->personnelUser('Personnel', 'page-personnel');

        foreach (range(1, 30) as $number) {
            LeaveRecord::create([
                'request_number' => 'LR-PAGE-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'personnel_id' => $personnel->personnel_id,
                'leave_type' => 'Vacation Leave',
                'day_part' => 'Full Day',
                'date_from' => '2026-08-03',
                'date_to' => '2026-08-03',
                'total_days' => 1,
                'reason' => 'Pagination regression request.',
                'approval_status' => 'Cancelled',
            ]);
        }

        $this->actingAs($administrator)
            ->getJson('/api/leave-requests?page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.pagination.current_page', 2)
            ->assertJsonPath('meta.pagination.total', 30)
            ->assertJsonPath('meta.pagination.last_page', 3);
    }

    private function personnelUser(
        string $role,
        string $key,
        bool $assignSchedule = true
    ): array {
        $personnel = Personnel::create([
            'department_id' => $this->departmentId,
            'employee_number' => strtoupper($key).'-001',
            'first_name' => ucfirst($key),
            'last_name' => 'User',
            'personnel_type' => 'GIP',
            'status' => 'Active',
        ]);
        $user = User::create([
            'personnel_id' => $personnel->personnel_id,
            'username' => $key,
            'password_hash' => 'test',
            'user_role' => $role,
            'status' => 'Active',
        ]);

        if ($assignSchedule) {
            PersonnelSchedule::create([
                'personnel_id' => $personnel->personnel_id,
                'schedule_id' => $this->schedule->schedule_id,
                'effective_from' => '2026-07-01',
                'created_by' => $user->user_id,
            ]);
        }

        return [$personnel, $user];
    }

    private function requestPayload(): array
    {
        return [
            'leave_type' => 'Vacation Leave',
            'day_part' => 'Full Day',
            'date_from' => '2026-08-03',
            'date_to' => '2026-08-04',
            'reason' => 'Personal leave requested for an important family matter.',
        ];
    }
}
