<?php

namespace Tests\Feature;

use App\Models\AttendanceChangeLog;
use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceCorrectionRequestSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->id('schedule_id');
            $table->string('schedule_name');
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

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id('attendance_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('schedule_id')->nullable();
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

        Schema::create('time_logs', function (Blueprint $table): void {
            $table->id('time_log_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('attendance_id')->nullable();
            $table->dateTime('log_datetime');
            $table->string('log_type');
            $table->string('log_source');
            $table->string('ip_address', 45)->nullable();
            $table->string('device_identifier')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->uuid('client_request_id')->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('attendance_change_logs', function (Blueprint $table): void {
            $table->id('attendance_change_log_id');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('action_type');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('attendance_correction_requests', function (Blueprint $table): void {
            $table->id('attendance_correction_request_id');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->date('attendance_date');
            $table->string('missing_field');
            $table->time('proposed_time');
            $table->string('reason', 500);
            $table->string('request_status')->default('Pending');
            $table->string('pending_key')->nullable()->unique();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_remarks', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('dtr_certifications', function (Blueprint $table): void {
            $table->id('dtr_certification_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedSmallInteger('dtr_year');
            $table->unsignedTinyInteger('dtr_month');
            $table->string('dtr_period')->default('full_month');
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('dtr_certifications');
        Schema::dropIfExists('attendance_correction_requests');
        Schema::dropIfExists('attendance_change_logs');
        Schema::dropIfExists('time_logs');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('personnel_schedules');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');
        Schema::dropIfExists('departments');

        parent::tearDown();
    }

    public function test_employee_can_submit_only_for_their_linked_attendance(): void
    {
        [$employee, $attendance, $date] = $this->createMissingTimeOutFixture();

        $this->actingAs($employee)
            ->postJson('/api/attendance/correction-requests', [
                'personnel_id' => 999999,
                'attendance_date' => $date,
                'missing_field' => 'morning_time_out',
                'proposed_time' => '12:05',
                'reason' => 'I was assisting with an urgent field report and missed the kiosk.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.personnel_id', $employee->personnel_id)
            ->assertJsonPath('data.attendance_id', $attendance->attendance_id)
            ->assertJsonPath('data.status', 'Pending');

        $this->assertDatabaseHas('attendance_correction_requests', [
            'attendance_id' => $attendance->attendance_id,
            'personnel_id' => $employee->personnel_id,
            'submitted_by' => $employee->user_id,
            'request_status' => 'Pending',
        ]);
    }

    public function test_duplicate_pending_request_is_rejected(): void
    {
        [$employee, $attendance, $date] = $this->createMissingTimeOutFixture();
        $payload = [
            'attendance_date' => $date,
            'missing_field' => 'morning_time_out',
            'proposed_time' => '12:05',
            'reason' => 'I was assisting with an urgent field report and missed the kiosk.',
        ];

        $this->actingAs($employee)->postJson('/api/attendance/correction-requests', $payload)
            ->assertCreated();
        $this->actingAs($employee)->postJson('/api/attendance/correction-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'A correction request for this missing time-out is already pending.'
            );

        $this->assertDatabaseCount('attendance_correction_requests', 1);
        $this->assertDatabaseHas('attendance_correction_requests', [
            'attendance_id' => $attendance->attendance_id,
        ]);
    }

    public function test_approval_applies_an_unverified_correction_and_requires_another_reviewer(): void
    {
        [$employee, $attendance, $date] = $this->createMissingTimeOutFixture();
        $requestId = $this->actingAs($employee)
            ->postJson('/api/attendance/correction-requests', [
                'attendance_date' => $date,
                'missing_field' => 'morning_time_out',
                'proposed_time' => '12:05',
                'reason' => 'I was assisting with an urgent field report and missed the kiosk.',
            ])
            ->assertCreated()
            ->json('data.request_id');
        $hr = $this->createUser('hr-reviewer', 'HR');

        $this->actingAs($hr)
            ->patchJson("/api/attendance/correction-requests/{$requestId}/review", [
                'action' => 'Approved',
                'review_remarks' => 'Confirmed with the employee supervisor.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved');

        $attendance->refresh();
        $this->assertSame('12:05', $attendance->morning_time_out->format('H:i'));
        $this->assertFalse($attendance->is_verified);
        $this->assertNull($attendance->verified_by);
        $this->assertDatabaseHas('attendance_change_logs', [
            'attendance_id' => $attendance->attendance_id,
            'changed_by' => $hr->user_id,
            'action_type' => 'Updated',
        ]);

        $this->actingAs($hr)
            ->patchJson("/api/attendance/{$attendance->attendance_id}/verify")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'A different authorized reviewer must verify this manual correction.'
            );

        $administrator = $this->createUser('independent-admin', 'Administrator');
        $this->actingAs($administrator)
            ->patchJson("/api/attendance/{$attendance->attendance_id}/verify")
            ->assertOk();

        $this->assertDatabaseHas('attendance_records', [
            'attendance_id' => $attendance->attendance_id,
            'is_verified' => true,
            'verified_by' => $administrator->user_id,
        ]);
    }

    public function test_hr_can_verify_another_personnel_attendance(): void
    {
        [, $attendance, $date] = $this->createMissingTimeOutFixture();
        $attendance->forceFill([
            'morning_time_out' => $date.' 12:00:00',
            'afternoon_time_in' => $date.' 13:00:00',
            'afternoon_time_out' => $date.' 17:00:00',
            'attendance_status' => 'Present',
        ])->save();
        $hr = $this->createUser('hr-attendance-reviewer', 'HR');

        $this->actingAs($hr)
            ->patchJson("/api/attendance/{$attendance->attendance_id}/verify")
            ->assertOk()
            ->assertJsonPath('data.is_verified', true);

        $this->assertDatabaseHas('attendance_records', [
            'attendance_id' => $attendance->attendance_id,
            'is_verified' => true,
            'verified_by' => $hr->user_id,
        ]);
    }

    public function test_hr_cannot_verify_own_attendance(): void
    {
        [$employee, $attendance] = $this->createMissingTimeOutFixture();
        $personnelId = $employee->personnel_id;
        $employee->delete();
        $hr = $this->createUser('hr-own-attendance', 'HR', $personnelId);

        $this->actingAs($hr)
            ->patchJson("/api/attendance/{$attendance->attendance_id}/verify")
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'You cannot verify your own attendance record. A different authorized reviewer is required.'
            );

        $this->assertDatabaseHas('attendance_records', [
            'attendance_id' => $attendance->attendance_id,
            'is_verified' => false,
            'verified_by' => null,
        ]);
    }

    public function test_administrator_can_verify_own_attendance(): void
    {
        [$employee, $attendance, $date] = $this->createMissingTimeOutFixture();
        $personnelId = $employee->personnel_id;
        $employee->delete();
        $administrator = $this->createUser(
            'admin-own-attendance',
            'Administrator',
            $personnelId
        );
        $attendance->forceFill([
            'morning_time_out' => $date.' 12:00:00',
            'afternoon_time_in' => $date.' 13:00:00',
            'afternoon_time_out' => $date.' 17:00:00',
            'attendance_status' => 'Present',
        ])->save();

        $this->actingAs($administrator)
            ->patchJson("/api/attendance/{$attendance->attendance_id}/verify")
            ->assertOk()
            ->assertJsonPath('data.is_verified', true);

        $this->assertDatabaseHas('attendance_records', [
            'attendance_id' => $attendance->attendance_id,
            'is_verified' => true,
            'verified_by' => $administrator->user_id,
        ]);
    }

    public function test_administrator_can_verify_a_manual_correction_they_made(): void
    {
        [$employee, $attendance, $date] = $this->createMissingTimeOutFixture();
        $personnelId = $employee->personnel_id;
        $employee->delete();
        $administrator = $this->createUser(
            'admin-own-manual-correction',
            'Administrator',
            $personnelId
        );
        $attendance->forceFill([
            'morning_time_out' => $date.' 12:00:00',
            'afternoon_time_in' => $date.' 13:00:00',
            'afternoon_time_out' => $date.' 17:00:00',
            'attendance_status' => 'Present',
            'record_source' => 'Manual',
        ])->save();
        AttendanceChangeLog::create([
            'attendance_id' => $attendance->attendance_id,
            'changed_by' => $administrator->user_id,
            'action_type' => 'Updated',
            'old_values' => [],
            'new_values' => ['record_source' => 'Manual'],
            'reason' => 'Administrator corrected their own attendance.',
        ]);

        $this->actingAs($administrator)
            ->patchJson("/api/attendance/{$attendance->attendance_id}/verify")
            ->assertOk()
            ->assertJsonPath('data.is_verified', true);

        $this->assertDatabaseHas('attendance_records', [
            'attendance_id' => $attendance->attendance_id,
            'is_verified' => true,
            'verified_by' => $administrator->user_id,
        ]);
    }

    public function test_administrator_can_bulk_verify_own_attendance(): void
    {
        [$employee, $attendance, $date] = $this->createMissingTimeOutFixture();
        $personnelId = $employee->personnel_id;
        $employee->delete();
        $administrator = $this->createUser(
            'admin-own-bulk-attendance',
            'Administrator',
            $personnelId
        );
        $attendance->forceFill([
            'morning_time_out' => $date.' 12:00:00',
            'afternoon_time_in' => $date.' 13:00:00',
            'afternoon_time_out' => $date.' 17:00:00',
            'attendance_status' => 'Present',
        ])->save();

        $this->actingAs($administrator)
            ->postJson('/api/attendance/verify-bulk', [
                'attendance_ids' => [$attendance->attendance_id],
            ])
            ->assertOk()
            ->assertJsonPath('verified_count', 1);

        $this->assertDatabaseHas('attendance_records', [
            'attendance_id' => $attendance->attendance_id,
            'is_verified' => true,
            'verified_by' => $administrator->user_id,
        ]);
    }

    public function test_hr_can_confirm_a_past_missing_duty_day_as_an_unverified_absence(): void
    {
        [$hr, $personnel, $date] = $this->createAbsenceFixture();

        $this->actingAs($hr)
            ->getJson('/api/attendance?date='.$date)
            ->assertOk()
            ->assertJsonPath('data.0.personnel_id', $personnel->personnel_id)
            ->assertJsonPath('data.0.display_status', 'Missing')
            ->assertJsonPath('data.0.attendance_id', null);

        $this->actingAs($hr)
            ->postJson('/api/attendance/correction', [
                'personnel_id' => $personnel->personnel_id,
                'attendance_date' => $date,
                'record_type' => 'Absent',
                'reason' => 'No attendance or approved absence was recorded for this duty day.',
            ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Absence confirmed and audited. A different authorized reviewer must verify it before DTR certification.'
            )
            ->assertJsonPath('data.display_status', 'Absent')
            ->assertJsonPath('data.is_verified', false);

        $attendanceId = AttendanceRecord::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->whereDate('attendance_date', $date)
            ->value('attendance_id');

        $this->assertDatabaseHas('attendance_records', [
            'attendance_id' => $attendanceId,
            'attendance_status' => 'Absent',
            'record_source' => 'Manual',
            'is_verified' => false,
            'verified_by' => null,
        ]);
        $this->assertDatabaseHas('attendance_change_logs', [
            'attendance_id' => $attendanceId,
            'changed_by' => $hr->user_id,
            'action_type' => 'Created',
        ]);
    }

    public function test_absence_confirmation_rejects_the_current_day_and_holidays(): void
    {
        [$hr, $personnel, $date] = $this->createAbsenceFixture();

        $this->actingAs($hr)
            ->postJson('/api/attendance/correction', [
                'personnel_id' => $personnel->personnel_id,
                'attendance_date' => now()->toDateString(),
                'record_type' => 'Absent',
                'reason' => 'Trying to close attendance before the duty day has ended.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'An absence can only be confirmed after the scheduled duty day has ended.'
            );

        Holiday::create([
            'holiday_date' => $date,
            'holiday_name' => 'Test Public Holiday',
            'holiday_type' => 'Regular Holiday',
            'scope' => 'National',
            'created_by' => $hr->user_id,
        ]);

        $this->actingAs($hr)
            ->postJson('/api/attendance/correction', [
                'personnel_id' => $personnel->personnel_id,
                'attendance_date' => $date,
                'record_type' => 'Absent',
                'reason' => 'Trying to mark a protected holiday as an absence.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'An absence cannot be confirmed on Test Public Holiday.'
            );

        $this->assertDatabaseMissing('attendance_records', [
            'personnel_id' => $personnel->personnel_id,
            'attendance_date' => $date,
        ]);
    }

    public function test_absence_confirmation_rejects_dates_without_schedule_coverage(): void
    {
        [$hr, $personnel, $date] = $this->createAbsenceFixture(false);

        $this->actingAs($hr)
            ->postJson('/api/attendance/correction', [
                'personnel_id' => $personnel->personnel_id,
                'attendance_date' => $date,
                'record_type' => 'Absent',
                'reason' => 'Trying to mark an uncovered date as an absence.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'An absence cannot be confirmed because no effective work schedule covers this date.'
            );
    }

    public function test_personnel_user_cannot_review_a_request(): void
    {
        [$employee, , $date] = $this->createMissingTimeOutFixture();
        $requestId = $this->actingAs($employee)
            ->postJson('/api/attendance/correction-requests', [
                'attendance_date' => $date,
                'missing_field' => 'morning_time_out',
                'proposed_time' => '12:05',
                'reason' => 'I was assisting with an urgent field report and missed the kiosk.',
            ])
            ->assertCreated()
            ->json('data.request_id');

        $this->actingAs($employee)
            ->patchJson("/api/attendance/correction-requests/{$requestId}/review", [
                'action' => 'Approved',
            ])
            ->assertForbidden();
    }

    public function test_only_an_authorized_reviewer_can_open_exact_request_details(): void
    {
        [$employee, $attendance, $date] = $this->createMissingTimeOutFixture();
        $requestId = $this->actingAs($employee)
            ->postJson('/api/attendance/correction-requests', [
                'attendance_date' => $date,
                'missing_field' => 'morning_time_out',
                'proposed_time' => '12:05',
                'reason' => 'I was assisting with an urgent field report and missed the kiosk.',
            ])
            ->assertCreated()
            ->json('data.request_id');
        $hr = $this->createUser('hr-detail-reviewer', 'HR');

        $this->actingAs($hr)
            ->getJson("/api/attendance/correction-requests/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.request_id', $requestId)
            ->assertJsonPath('data.attendance_id', $attendance->attendance_id)
            ->assertJsonPath('data.personnel.employee_number', 'TEST-0001')
            ->assertJsonPath('data.attendance.status', 'Incomplete')
            ->assertJsonPath('data.attendance.morning_time_in', '07:05')
            ->assertJsonPath('data.attendance.morning_time_out', null)
            ->assertJsonMissingPath('data.ip_address')
            ->assertJsonMissingPath('data.user_agent');

        $this->actingAs($employee)
            ->getJson("/api/attendance/correction-requests/{$requestId}")
            ->assertForbidden();

        $supervisor = $this->createUser('supervisor-detail-denied', 'Supervisor');
        $this->actingAs($supervisor)
            ->getJson("/api/attendance/correction-requests/{$requestId}")
            ->assertForbidden();
    }

    public function test_unlinked_system_user_gets_a_clear_time_log_error(): void
    {
        $personnel = Personnel::create([
            'employee_number' => 'TIME-0001',
            'first_name' => 'Time',
            'last_name' => 'Employee',
            'status' => 'Active',
        ]);
        $administrator = $this->createUser('unlinked-admin', 'Administrator');

        $this->actingAs($administrator)
            ->postJson('/api/attendance/time-log', [
                'personnel_id' => $personnel->personnel_id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Your account is not linked to a personnel record. Link this system user to your personnel profile, or use the authorized QR Attendance kiosk.'
            );
    }

    public function test_system_user_cannot_record_time_for_another_personnel_member(): void
    {
        $linkedPersonnel = Personnel::create([
            'employee_number' => 'TIME-0002',
            'first_name' => 'Linked',
            'last_name' => 'Employee',
            'status' => 'Active',
        ]);
        $otherPersonnel = Personnel::create([
            'employee_number' => 'TIME-0003',
            'first_name' => 'Other',
            'last_name' => 'Employee',
            'status' => 'Active',
        ]);
        $personnelUser = $this->createUser(
            'linked-personnel',
            'Personnel',
            $linkedPersonnel->personnel_id
        );

        $this->actingAs($personnelUser)
            ->postJson('/api/attendance/time-log', [
                'personnel_id' => $otherPersonnel->personnel_id,
            ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Attendance denied. You can only time in or time out using your own linked personnel account.'
            );
    }

    public function test_retried_time_entry_returns_the_original_log_without_creating_a_second_one(): void
    {
        $this->travelTo(now()->startOfWeek()->addDay()->setTime(7, 30));
        [, $personnel] = $this->createAbsenceFixture();
        $employee = $this->createUser('clock-retry-employee', 'Personnel', $personnel->personnel_id);
        $requestId = '1b0c925b-758b-4a58-8c87-221a2cd50799';
        $body = ['personnel_id' => $personnel->personnel_id, 'client_request_id' => $requestId];

        $this->actingAs($employee)
            ->getJson("/api/attendance/time-log-attempt/{$requestId}")
            ->assertNotFound();

        $first = $this->actingAs($employee)->postJson('/api/attendance/time-log', $body)
            ->assertOk()
            ->assertJsonPath('action', 'Morning In');
        $this->travel(2)->minutes();

        $this->actingAs($employee)->postJson('/api/attendance/time-log', $body)
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('data.attendance_id', $first->json('data.attendance_id'));
        $this->actingAs($employee)
            ->getJson("/api/attendance/time-log-attempt/{$requestId}")
            ->assertOk()
            ->assertJsonPath('action', 'Morning In');
        $this->assertDatabaseCount('time_logs', 1);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_time_entry_confirmation_is_private_to_the_employee_who_saved_it(): void
    {
        $this->travelTo(now()->startOfWeek()->addDay()->setTime(7, 30));
        [, $personnel] = $this->createAbsenceFixture();
        $employee = $this->createUser('clock-owner', 'Personnel', $personnel->personnel_id);
        $otherPersonnel = Personnel::create([
            'employee_number' => 'CLOCK-OTHER',
            'first_name' => 'Other',
            'last_name' => 'Employee',
            'status' => 'Active',
        ]);
        $other = $this->createUser('clock-other', 'Personnel', $otherPersonnel->personnel_id);
        $requestId = '4d787128-d4b1-458c-909b-c462274372b8';

        $this->actingAs($employee)->postJson('/api/attendance/time-log', [
            'personnel_id' => $personnel->personnel_id,
            'client_request_id' => $requestId,
        ])->assertOk();

        $this->actingAs($other)
            ->getJson("/api/attendance/time-log-attempt/{$requestId}")
            ->assertNotFound();
        $this->actingAs($other)->postJson('/api/attendance/time-log', [
            'personnel_id' => $otherPersonnel->personnel_id,
            'client_request_id' => $requestId,
        ])->assertStatus(409);
        $this->assertDatabaseCount('time_logs', 1);
    }

    private function createMissingTimeOutFixture(): array
    {
        $date = now()->subDay()->toDateString();
        $personnel = Personnel::create([
            'employee_number' => 'TEST-0001',
            'first_name' => 'Sample',
            'last_name' => 'Employee',
            'status' => 'Active',
        ]);
        $employee = $this->createUser(
            'employee-user',
            'Personnel',
            $personnel->personnel_id
        );
        $schedule = WorkSchedule::create([
            'schedule_name' => 'Compressed schedule',
            'morning_start' => '07:00:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:00:00',
            'afternoon_end' => '17:00:00',
            'morning_time_in_start' => '06:00:00',
            'morning_time_in_end' => '09:00:00',
            'morning_time_out_start' => '11:30:00',
            'morning_time_out_end' => '12:30:00',
            'afternoon_time_in_start' => '12:30:00',
            'afternoon_time_in_end' => '14:00:00',
            'afternoon_time_out_start' => '16:30:00',
            'afternoon_time_out_end' => '19:00:00',
            'required_minutes_per_day' => 600,
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => false,
            'saturday' => false,
            'sunday' => false,
            'status' => 'Active',
        ]);
        $attendance = AttendanceRecord::create([
            'personnel_id' => $personnel->personnel_id,
            'schedule_id' => $schedule->schedule_id,
            'attendance_date' => $date,
            'morning_time_in' => $date.' 07:05:00',
            'attendance_status' => 'Incomplete',
            'record_source' => 'QR Code',
            'created_by' => $employee->user_id,
        ]);

        return [$employee, $attendance, $date];
    }

    private function createAbsenceFixture(bool $assignSchedule = true): array
    {
        $date = now()->subDay()->toDateString();
        $personnel = Personnel::create([
            'employee_number' => 'ABSENCE-'.uniqid(),
            'first_name' => 'Missing',
            'last_name' => 'Employee',
            'status' => 'Active',
        ]);
        $hr = $this->createUser('absence-reviewer-'.uniqid(), 'HR');
        $schedule = WorkSchedule::create([
            'schedule_name' => 'Daily test schedule',
            'morning_start' => '07:00:00',
            'morning_end' => '12:00:00',
            'afternoon_start' => '13:00:00',
            'afternoon_end' => '17:00:00',
            'morning_time_in_start' => '06:00:00',
            'morning_time_in_end' => '09:00:00',
            'morning_time_out_start' => '11:30:00',
            'morning_time_out_end' => '12:30:00',
            'afternoon_time_in_start' => '12:30:00',
            'afternoon_time_in_end' => '14:00:00',
            'afternoon_time_out_start' => '16:30:00',
            'afternoon_time_out_end' => '19:00:00',
            'required_minutes_per_day' => 600,
            'monday' => true,
            'tuesday' => true,
            'wednesday' => true,
            'thursday' => true,
            'friday' => true,
            'saturday' => true,
            'sunday' => true,
            'status' => 'Active',
        ]);

        if ($assignSchedule) {
            PersonnelSchedule::create([
                'personnel_id' => $personnel->personnel_id,
                'schedule_id' => $schedule->schedule_id,
                'effective_from' => now()->subMonth()->toDateString(),
                'created_by' => $hr->user_id,
            ]);
        }

        return [$hr, $personnel, $date];
    }

    private function createUser(
        string $username,
        string $role,
        ?int $personnelId = null
    ): User {
        return User::create([
            'personnel_id' => $personnelId,
            'username' => $username,
            'password_hash' => 'not-used',
            'user_role' => $role,
            'status' => 'Active',
        ]);
    }
}
