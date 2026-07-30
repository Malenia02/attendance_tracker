<?php

namespace Tests\Feature;

use App\Models\Personnel;
use App\Models\PersonnelSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScheduleManagementSecurityTest extends TestCase
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
            $table->string('personnel_type')->default('GIP');
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
            $table->unique(['personnel_id', 'effective_from']);
        });

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id('attendance_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->date('attendance_date');
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
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('personnel_schedules');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');
        Schema::dropIfExists('departments');

        parent::tearDown();
    }

    public function test_hr_can_create_a_compressed_schedule(): void
    {
        $response = $this->actingAs($this->user('HR'))
            ->postJson('/api/schedules', $this->schedulePayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.morning_start', '07:00')
            ->assertJsonPath('data.required_minutes_per_day', 600)
            ->assertJsonPath('data.friday', false);

        $this->assertDatabaseHas('work_schedules', [
            'schedule_name' => 'DILG GIP Compressed Workweek',
            'morning_start' => '07:00',
            'required_minutes_per_day' => 600,
            'friday' => false,
        ]);
    }

    public function test_schedule_requires_at_least_one_working_day(): void
    {
        $payload = $this->schedulePayload();

        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $payload[$day] = false;
        }

        $this->actingAs($this->user('Administrator'))
            ->postJson('/api/schedules', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('working_days');
    }

    public function test_personnel_account_cannot_manage_schedules(): void
    {
        $this->actingAs($this->user('Personnel'))
            ->postJson('/api/schedules', $this->schedulePayload())
            ->assertForbidden();
    }

    public function test_new_assignment_closes_the_previous_assignment_and_preserves_history(): void
    {
        $administrator = $this->user('Administrator');
        $personnel = Personnel::create([
            'employee_number' => 'GIP-2026-001',
            'first_name' => 'Sample',
            'last_name' => 'Personnel',
            'personnel_type' => 'GIP',
            'status' => 'Active',
        ]);
        $oldSchedule = WorkSchedule::create([
            ...$this->schedulePayload(),
            'schedule_name' => 'Previous Schedule',
        ]);
        $newSchedule = WorkSchedule::create([
            ...$this->schedulePayload(),
            'schedule_name' => 'Updated Schedule',
        ]);
        $oldAssignment = PersonnelSchedule::create([
            'personnel_id' => $personnel->personnel_id,
            'schedule_id' => $oldSchedule->schedule_id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'created_by' => $administrator->user_id,
        ]);

        $this->actingAs($administrator)
            ->postJson('/api/schedules/assignments', [
                'schedule_id' => $newSchedule->schedule_id,
                'personnel_ids' => [$personnel->personnel_id],
                'effective_from' => '2026-07-01',
                'effective_to' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.0.schedule_id', $newSchedule->schedule_id);

        $this->assertDatabaseHas('personnel_schedules', [
            'personnel_schedule_id' => $oldAssignment->personnel_schedule_id,
            'effective_to' => '2026-06-30',
        ]);
        $newAssignment = PersonnelSchedule::query()
            ->where('personnel_id', $personnel->personnel_id)
            ->where('schedule_id', $newSchedule->schedule_id)
            ->firstOrFail();

        $this->assertSame('2026-07-01', $newAssignment->effective_from->format('Y-m-d'));
        $this->assertNull($newAssignment->effective_to);

        $this->actingAs($administrator)
            ->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonPath('summary.assigned_personnel', 1)
            ->assertJsonPath('summary.unassigned_personnel', 0)
            ->assertJsonPath('personnel.0.current_assignment.schedule_name', 'Updated Schedule');
    }

    public function test_inactive_schedule_is_not_reported_as_a_current_assignment(): void
    {
        $this->travelTo(Carbon::parse('2026-07-30 08:00:00', 'Asia/Manila'));

        $administrator = $this->user('Administrator');
        $personnel = Personnel::create([
            'employee_number' => 'GIP-2026-INACTIVE',
            'first_name' => 'Inactive',
            'last_name' => 'Schedule',
            'personnel_type' => 'GIP',
            'status' => 'Active',
        ]);
        $schedule = WorkSchedule::create([
            ...$this->schedulePayload(),
            'schedule_name' => 'Inactive Schedule',
            'status' => 'Inactive',
        ]);
        PersonnelSchedule::create([
            'personnel_id' => $personnel->personnel_id,
            'schedule_id' => $schedule->schedule_id,
            'effective_from' => '2026-07-01',
            'effective_to' => null,
            'created_by' => $administrator->user_id,
        ]);

        $this->actingAs($administrator)
            ->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonPath('summary.assigned_personnel', 0)
            ->assertJsonPath('summary.unassigned_personnel', 1)
            ->assertJsonPath('personnel.0.current_assignment', null);
    }

    public function test_personnel_assignments_are_paginated_before_formatting(): void
    {
        $administrator = $this->user('Administrator');

        foreach (range(1, 31) as $number) {
            Personnel::create([
                'employee_number' => 'PAGE-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'first_name' => 'Person',
                'last_name' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'personnel_type' => 'GIP',
                'status' => 'Active',
            ]);
        }

        $this->actingAs($administrator)
            ->getJson('/api/schedules?personnel_page=2&personnel_per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'personnel')
            ->assertJsonPath('meta.personnel_pagination.current_page', 2)
            ->assertJsonPath('meta.personnel_pagination.per_page', 10)
            ->assertJsonPath('meta.personnel_pagination.total', 31)
            ->assertJsonPath('meta.personnel_pagination.last_page', 4);
    }

    private function user(string $role): User
    {
        return User::create([
            'username' => strtolower($role).'-'.uniqid(),
            'password_hash' => 'test',
            'user_role' => $role,
            'status' => 'Active',
        ]);
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
            'morning_time_in_end' => '11:00',
            'morning_time_out_start' => '11:30',
            'morning_time_out_end' => '12:30',
            'afternoon_time_in_start' => '12:30',
            'afternoon_time_in_end' => '14:00',
            'afternoon_time_out_start' => '17:30',
            'afternoon_time_out_end' => '20:00',
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
