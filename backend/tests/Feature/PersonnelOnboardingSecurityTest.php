<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Personnel;
use App\Models\PersonnelActivationLog;
use App\Models\PersonnelSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class PersonnelOnboardingSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-04 09:00:00');

        Schema::create('departments', function (Blueprint $table): void {
            $table->id('department_id');
            $table->string('department_code')->unique();
            $table->string('department_name');
            $table->string('office_location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('allowed_radius_meters')->default(100);
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        Schema::create('personnel', function (Blueprint $table): void {
            $table->id('personnel_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('employee_number')->unique();
            $table->string('biometric_number')->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('sex')->nullable();
            $table->string('personnel_type')->default('GIP');
            $table->string('position_title')->nullable();
            $table->date('employment_start_date')->nullable();
            $table->date('employment_end_date')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_number')->nullable();
            $table->text('address')->nullable();
            $table->string('photo')->nullable();
            $table->string('signature')->nullable();
            $table->string('qr_login_code', 64)->nullable()->unique();
            $table->date('qr_valid_from')->nullable();
            $table->date('qr_valid_until')->nullable();
            $table->string('status')->default('Inactive');
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
            foreach ([
                'morning_start', 'morning_end', 'afternoon_start', 'afternoon_end',
                'morning_time_in_start', 'morning_time_in_end',
                'morning_time_out_start', 'morning_time_out_end',
                'afternoon_time_in_start', 'afternoon_time_in_end',
                'afternoon_time_out_start', 'afternoon_time_out_end',
            ] as $column) {
                $table->time($column)->nullable();
            }
            $table->unsignedSmallInteger('grace_period_minutes')->default(0);
            $table->unsignedSmallInteger('required_minutes_per_day')->default(600);
            foreach (['monday', 'tuesday', 'wednesday', 'thursday'] as $day) {
                $table->boolean($day)->default(true);
            }
            foreach (['friday', 'saturday', 'sunday'] as $day) {
                $table->boolean($day)->default(false);
            }
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
            $table->unique(['personnel_id', 'effective_from']);
        });

        Schema::create('personnel_activation_logs', function (Blueprint $table): void {
            $table->id('personnel_activation_log_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason', 500)->nullable();
            $table->json('readiness_snapshot');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
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
        Schema::dropIfExists('personnel_activation_logs');
        Schema::dropIfExists('personnel_schedules');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');
        Schema::dropIfExists('departments');
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_non_hr_roles_cannot_access_the_onboarding_registry(): void
    {
        $this->actingAs($this->user('Personnel'))
            ->getJson('/api/personnel-onboarding')
            ->assertForbidden();
    }

    public function test_incomplete_personnel_cannot_be_activated(): void
    {
        $personnel = $this->personnel('INCOMPLETE');

        $this->actingAs($this->user('HR'))
            ->patchJson('/api/personnel-onboarding/'.$personnel->personnel_id, [
                'target_status' => 'Active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('personnel_ids');

        $this->assertSame('Inactive', $personnel->fresh()->status);
        $this->assertDatabaseCount('personnel_activation_logs', 0);
    }

    public function test_ready_personnel_activation_is_atomic_and_audited(): void
    {
        $administrator = $this->user('Administrator');
        $personnel = $this->readyPersonnel('READY', $administrator);

        $this->actingAs($administrator)
            ->patchJson('/api/personnel-onboarding/'.$personnel->personnel_id, [
                'target_status' => 'Active',
                'reason' => 'All onboarding evidence reviewed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.readiness.state', 'Active');

        $this->assertSame('Active', $personnel->fresh()->status);
        $log = PersonnelActivationLog::query()->firstOrFail();
        $this->assertSame($administrator->user_id, $log->changed_by);
        $this->assertSame('Inactive', $log->from_status);
        $this->assertSame('Active', $log->to_status);
        $this->assertTrue($log->readiness_snapshot['is_ready']);

        $this->expectException(LogicException::class);
        $log->update(['reason' => 'Tampered']);
    }

    public function test_bulk_activation_rolls_back_when_one_record_is_incomplete(): void
    {
        $administrator = $this->user('Administrator');
        $ready = $this->readyPersonnel('BULK-READY', $administrator);
        $incomplete = $this->personnel('BULK-INCOMPLETE');

        $this->actingAs($administrator)
            ->patchJson('/api/personnel-onboarding/bulk', [
                'personnel_ids' => [$ready->personnel_id, $incomplete->personnel_id],
                'target_status' => 'Active',
            ])
            ->assertUnprocessable();

        $this->assertSame('Inactive', $ready->fresh()->status);
        $this->assertSame('Inactive', $incomplete->fresh()->status);
        $this->assertDatabaseCount('personnel_activation_logs', 0);
    }

    public function test_regular_personnel_update_cannot_bypass_activation(): void
    {
        $administrator = $this->user('Administrator');
        $personnel = $this->personnel('BYPASS');

        $this->actingAs($administrator)
            ->putJson('/api/personnel/'.$personnel->personnel_id, [
                'employee_number' => $personnel->employee_number,
                'auto_generate_employee_number' => false,
                'first_name' => $personnel->first_name,
                'last_name' => $personnel->last_name,
                'sex' => 'Male',
                'personnel_type' => 'Regular',
                'department_id' => null,
                'employment_start_date' => null,
                'employment_end_date' => null,
                'qr_valid_from' => '2026-01-01',
                'qr_valid_until' => '2026-12-31',
                'status' => 'Active',
            ])
            ->assertOk();

        $this->assertSame('Inactive', $personnel->fresh()->status);
    }

    public function test_incomplete_active_personnel_is_blocked_from_live_attendance(): void
    {
        $personnel = $this->personnel('LIVE-BLOCK');
        $personnel->forceFill(['status' => 'Active'])->save();
        $user = $this->user('Personnel', $personnel->personnel_id);

        $this->actingAs($user)
            ->postJson('/api/attendance/time-log', [
                'personnel_id' => $personnel->personnel_id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Attendance setup is incomplete for this personnel record. Required: Active department, Current employment period, Effective work schedule. Ask Administrator or HR to finish onboarding.'
            );
    }

    public function test_system_user_options_can_load_a_requested_inactive_personnel_record(): void
    {
        $administrator = $this->user('Administrator');
        $personnel = $this->personnel('ACCOUNT-LOOKUP');

        $this->actingAs($administrator)
            ->getJson('/api/system-users/options?personnel_id='.$personnel->personnel_id)
            ->assertOk()
            ->assertJsonPath('personnel.0.personnel_id', $personnel->personnel_id)
            ->assertJsonPath('personnel.0.full_name', $personnel->full_name);
    }

    private function user(string $role, ?int $personnelId = null): User
    {
        return User::create([
            'personnel_id' => $personnelId,
            'username' => strtolower($role).'-'.uniqid(),
            'password_hash' => 'test',
            'user_role' => $role,
            'status' => 'Active',
        ]);
    }

    private function personnel(string $suffix): Personnel
    {
        return Personnel::create([
            'employee_number' => 'EMP-'.$suffix,
            'first_name' => 'Test',
            'last_name' => $suffix,
            'personnel_type' => 'Regular',
            'status' => 'Inactive',
        ]);
    }

    private function readyPersonnel(string $suffix, User $creator): Personnel
    {
        $department = Department::create([
            'department_code' => 'OFF-'.$suffix,
            'department_name' => 'Office '.$suffix,
            'latitude' => 7.7845,
            'longitude' => 122.5868,
            'status' => 'Active',
        ]);
        $personnel = Personnel::create([
            'department_id' => $department->department_id,
            'employee_number' => 'EMP-'.$suffix,
            'first_name' => 'Ready',
            'last_name' => $suffix,
            'personnel_type' => 'Regular',
            'employment_start_date' => '2026-01-01',
            'qr_login_code' => hash('sha256', $suffix),
            'qr_valid_from' => '2026-01-01',
            'qr_valid_until' => '2026-12-31',
            'status' => 'Inactive',
        ]);
        $this->user('Personnel', $personnel->personnel_id);
        $schedule = WorkSchedule::create([
            'schedule_name' => 'Schedule '.$suffix,
            'status' => 'Active',
        ]);
        PersonnelSchedule::create([
            'personnel_id' => $personnel->personnel_id,
            'schedule_id' => $schedule->schedule_id,
            'effective_from' => '2026-01-01',
            'created_by' => $creator->user_id,
        ]);

        return $personnel;
    }
}
