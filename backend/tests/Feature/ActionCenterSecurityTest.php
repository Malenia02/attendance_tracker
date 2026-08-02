<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActionCenterSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('departments', function (Blueprint $table): void {
            $table->id('department_id');
            $table->string('department_code');
            $table->string('department_name');
        });
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->id('schedule_id');
            $table->string('schedule_name');
            $table->time('morning_time_out_end')->nullable();
            $table->time('afternoon_time_out_end')->nullable();
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
            $table->date('qr_valid_until')->nullable();
            $table->string('status')->default('Active');
            $table->timestamps();
        });
        Schema::create('system_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->unsignedBigInteger('personnel_id')->nullable();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->string('user_role');
            $table->string('status');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });
        Schema::create('personnel_schedules', function (Blueprint $table): void {
            $table->id('personnel_schedule_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('schedule_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
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
            $table->string('record_source')->default('Web Portal');
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
        Schema::create('attendance_correction_requests', function (Blueprint $table): void {
            $table->id('attendance_correction_request_id');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('personnel_id');
            $table->date('attendance_date');
            $table->string('missing_field');
            $table->time('proposed_time');
            $table->string('reason', 500);
            $table->string('request_status')->default('Pending');
            $table->timestamps();
        });
        Schema::create('leave_records', function (Blueprint $table): void {
            $table->id('leave_id');
            $table->unsignedBigInteger('personnel_id');
            $table->string('leave_type');
            $table->date('date_from');
            $table->date('date_to');
            $table->string('approval_status')->default('Pending');
            $table->timestamps();
        });
        Schema::create('dtr_certifications', function (Blueprint $table): void {
            $table->id('dtr_certification_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedSmallInteger('dtr_year');
            $table->unsignedTinyInteger('dtr_month');
            $table->string('certification_status')->default('Draft');
            $table->string('remarks')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'dtr_certifications',
            'leave_records',
            'attendance_correction_requests',
            'attendance_records',
            'personnel_schedules',
            'system_users',
            'personnel',
            'work_schedules',
            'departments',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_administrator_receives_all_indexed_action_queues(): void
    {
        $departmentId = $this->department('DILG-ZSP', 'Zamboanga del Sur');
        $personnelId = $this->personnel('EMP-001', $departmentId, now()->addDays(10)->toDateString());
        $unassignedId = $this->personnel('EMP-002');
        $scheduleId = DB::table('work_schedules')->insertGetId([
            'schedule_name' => 'Compressed Week',
            'morning_time_out_end' => '12:30:00',
            'afternoon_time_out_end' => '18:30:00',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('personnel_schedules')->insert([
            'personnel_id' => $personnelId,
            'schedule_id' => $scheduleId,
            'effective_from' => now()->subMonth()->toDateString(),
            'created_at' => now(),
        ]);
        $verifiedQueueId = DB::table('attendance_records')->insertGetId([
            'personnel_id' => $personnelId,
            'schedule_id' => $scheduleId,
            'attendance_date' => now()->subDays(2)->toDateString(),
            'morning_time_in' => now()->subDays(2)->setTime(7, 0),
            'morning_time_out' => now()->subDays(2)->setTime(12, 0),
            'afternoon_time_in' => now()->subDays(2)->setTime(13, 0),
            'afternoon_time_out' => now()->subDays(2)->setTime(18, 0),
            'attendance_status' => 'Present',
            'record_source' => 'QR Code',
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $missingAttendanceId = DB::table('attendance_records')->insertGetId([
            'personnel_id' => $personnelId,
            'schedule_id' => $scheduleId,
            'attendance_date' => now()->subDay()->toDateString(),
            'morning_time_in' => now()->subDay()->setTime(7, 0),
            'attendance_status' => 'Incomplete',
            'record_source' => 'Web Portal',
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendance_correction_requests')->insert([
            'attendance_id' => $missingAttendanceId,
            'personnel_id' => $personnelId,
            'attendance_date' => now()->subDay()->toDateString(),
            'missing_field' => 'morning_time_out',
            'proposed_time' => '12:00:00',
            'reason' => 'Forgot to record the time-out after field work.',
            'request_status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('leave_records')->insert([
            'personnel_id' => $personnelId,
            'leave_type' => 'Vacation Leave',
            'date_from' => now()->addWeek()->toDateString(),
            'date_to' => now()->addWeek()->toDateString(),
            'approval_status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('dtr_certifications')->insert([
            'personnel_id' => $personnelId,
            'dtr_year' => now()->year,
            'dtr_month' => now()->month,
            'certification_status' => 'Returned',
            'remarks' => 'Review the incomplete date.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $administrator = $this->user('action-admin', 'Administrator');

        $response = $this->actingAs($administrator)
            ->getJson('/api/action-center?queue=attendance_verification')
            ->assertOk()
            ->assertJsonPath('scope', 'All offices')
            ->assertJsonPath('data.0.id', $verifiedQueueId)
            ->assertJsonPath('meta.pagination.total', 1);

        $counts = collect($response->json('summary'))->pluck('count', 'key');
        $this->assertSame(1, $counts['attendance_verification']);
        $this->assertSame(1, $counts['missing_time_outs']);
        $this->assertSame(1, $counts['correction_requests']);
        $this->assertSame(1, $counts['leave_requests']);
        $this->assertSame(1, $counts['returned_dtrs']);
        $this->assertSame(1, $counts['expiring_qr_cards']);
        $this->assertSame(1, $counts['workforce_gaps']);
        $this->assertSame($unassignedId, DB::table('personnel')->whereNull('department_id')->value('personnel_id'));
    }

    public function test_supervisor_is_department_scoped_and_cannot_open_hr_only_queues(): void
    {
        $departmentA = $this->department('DILG-A', 'Office A');
        $departmentB = $this->department('DILG-B', 'Office B');
        $supervisorPersonnel = $this->personnel('SUP-001', $departmentA);
        $personnelA = $this->personnel('A-001', $departmentA);
        $personnelB = $this->personnel('B-001', $departmentB);
        $this->attendance($personnelA);
        $this->attendance($personnelB);
        $supervisor = $this->user('office-supervisor', 'Supervisor', $supervisorPersonnel);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/action-center?queue=attendance_verification')
            ->assertOk()
            ->assertJsonPath('scope', 'Office A')
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.personnel_id', $personnelA);

        $keys = collect($response->json('summary'))->pluck('key');
        $this->assertEqualsCanonicalizing([
            'attendance_verification',
            'missing_time_outs',
            'leave_requests',
            'returned_dtrs',
        ], $keys->all());

        $this->actingAs($supervisor)
            ->getJson('/api/action-center?queue=correction_requests')
            ->assertForbidden()
            ->assertJsonPath('message', 'This Action Center queue is outside your role permissions.');
    }

    public function test_personnel_and_encoder_accounts_cannot_access_the_action_center(): void
    {
        foreach (['Personnel', 'Encoder'] as $role) {
            $user = $this->user(strtolower($role).'-action-denied', $role);

            $this->actingAs($user)
                ->getJson('/api/action-center')
                ->assertForbidden();
        }
    }

    public function test_action_queues_are_server_paginated_and_page_size_is_bounded(): void
    {
        $departmentId = $this->department('DILG-PAGE', 'Pagination Office');
        $personnelId = $this->personnel('PAGE-001', $departmentId);

        foreach (range(1, 12) as $number) {
            DB::table('leave_records')->insert([
                'personnel_id' => $personnelId,
                'leave_type' => 'Vacation Leave',
                'date_from' => now()->addDays($number)->toDateString(),
                'date_to' => now()->addDays($number)->toDateString(),
                'approval_status' => 'Pending',
                'created_at' => now()->addSeconds($number),
                'updated_at' => now(),
            ]);
        }

        $administrator = $this->user('pagination-admin', 'Administrator');

        $this->actingAs($administrator)
            ->getJson('/api/action-center?queue=leave_requests&per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.current_page', 2)
            ->assertJsonPath('meta.pagination.per_page', 10)
            ->assertJsonPath('meta.pagination.total', 12);

        $this->actingAs($administrator)
            ->getJson('/api/action-center?queue=leave_requests&per_page=100')
            ->assertUnprocessable();
    }

    private function department(string $code, string $name): int
    {
        return DB::table('departments')->insertGetId([
            'department_code' => $code,
            'department_name' => $name,
        ]);
    }

    private function personnel(string $employeeNumber, ?int $departmentId = null, ?string $qrValidUntil = null): int
    {
        return DB::table('personnel')->insertGetId([
            'department_id' => $departmentId,
            'employee_number' => $employeeNumber,
            'first_name' => 'Test',
            'last_name' => $employeeNumber,
            'personnel_type' => 'GIP',
            'qr_valid_until' => $qrValidUntil,
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(string $username, string $role, ?int $personnelId = null): User
    {
        return User::create([
            'personnel_id' => $personnelId,
            'username' => $username,
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => $role,
            'status' => 'Active',
        ]);
    }

    private function attendance(int $personnelId): int
    {
        return DB::table('attendance_records')->insertGetId([
            'personnel_id' => $personnelId,
            'attendance_date' => now()->subDay()->toDateString(),
            'morning_time_in' => now()->subDay()->setTime(7, 0),
            'morning_time_out' => now()->subDay()->setTime(12, 0),
            'afternoon_time_in' => now()->subDay()->setTime(13, 0),
            'afternoon_time_out' => now()->subDay()->setTime(18, 0),
            'attendance_status' => 'Present',
            'record_source' => 'Web Portal',
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
