<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardRoleSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Schema::create('departments', function (Blueprint $table): void {
            $table->id('department_id');
            $table->string('department_code');
            $table->string('department_name');
            $table->string('status')->default('Active');
            $table->timestamps();
        });
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->id('schedule_id');
            $table->string('schedule_name');
            $table->time('morning_start')->nullable();
            $table->time('morning_end')->nullable();
            $table->time('afternoon_start')->nullable();
            $table->time('afternoon_end')->nullable();
            $table->unsignedInteger('required_minutes_per_day')->default(600);
            foreach (['monday', 'tuesday', 'wednesday', 'thursday'] as $day) {
                $table->boolean($day)->default(true);
            }
            foreach (['friday', 'saturday', 'sunday'] as $day) {
                $table->boolean($day)->default(false);
            }
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
            $table->string('status')->default('Active');
            $table->timestamps();
        });
        Schema::create('system_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->unsignedBigInteger('personnel_id')->nullable();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->string('user_role');
            $table->string('status')->default('Active');
            $table->unsignedInteger('failed_login_attempts')->default(0);
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
            $table->unsignedInteger('total_work_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
        Schema::create('attendance_correction_requests', function (Blueprint $table): void {
            $table->id('attendance_correction_request_id');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('personnel_id');
            $table->date('attendance_date');
            $table->string('request_status')->default('Pending');
            $table->timestamps();
        });
        Schema::create('leave_records', function (Blueprint $table): void {
            $table->id('leave_id');
            $table->unsignedBigInteger('personnel_id');
            $table->string('leave_type');
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('total_days')->default(1);
            $table->string('approval_status')->default('Pending');
            $table->timestamps();
        });
        Schema::create('dtr_certifications', function (Blueprint $table): void {
            $table->id('dtr_certification_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedSmallInteger('dtr_year');
            $table->unsignedTinyInteger('dtr_month');
            $table->unsignedSmallInteger('version_number')->default(1);
            $table->string('certification_status')->default('Draft');
            $table->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id('activity_log_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('activity_type');
            $table->string('description');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Cache::flush();

        foreach ([
            'activity_logs',
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

    public function test_personnel_dashboard_contains_only_the_linked_personnel_data(): void
    {
        $departmentId = $this->department('DILG-A');
        $ownPersonnel = $this->personnel('OWN-001', $departmentId, 'Own');
        $otherPersonnel = $this->personnel('OTHER-001', $departmentId, 'Other');
        $this->attendance($ownPersonnel, 600, 15, 20, 'Present');
        $this->attendance($otherPersonnel, 900, 90, 80, 'Present');
        $this->leave($ownPersonnel, 'Vacation Leave');
        $this->leave($otherPersonnel, 'Sick Leave');
        $this->dtr($ownPersonnel, 'Returned');
        $this->dtr($otherPersonnel, 'Certified');
        $user = $this->user('personal-dashboard', 'Personnel', $ownPersonnel);

        $response = $this->actingAs($user)->getJson(
            "/api/dashboard?personnel_id={$otherPersonnel}&role=Administrator"
        );

        $response->assertOk()
            ->assertJsonPath('view', 'personal')
            ->assertJsonPath('scope', 'My attendance only')
            ->assertJsonPath('profile.employee_number', 'OWN-001')
            ->assertJsonPath('metrics.work_minutes', 600)
            ->assertJsonPath('metrics.late_minutes', 15)
            ->assertJsonPath('leave.pending', 1)
            ->assertJsonPath('dtr.status', 'Returned')
            ->assertJsonMissing(['employee_number' => 'OTHER-001'])
            ->assertJsonMissing(['security_events_24h' => 0]);
    }

    public function test_supervisor_dashboard_is_strictly_scoped_to_their_department(): void
    {
        $departmentA = $this->department('DILG-A');
        $departmentB = $this->department('DILG-B');
        $supervisorPersonnel = $this->personnel('SUP-001', $departmentA, 'Supervisor');
        $memberA = $this->personnel('MEMBER-A', $departmentA, 'MemberA');
        $memberB = $this->personnel('MEMBER-B', $departmentB, 'MemberB');
        $this->attendance($memberA, 600, 0, 0, 'Present');
        $this->attendance($memberB, 600, 0, 0, 'Present');
        $this->leave($memberA, 'Vacation Leave');
        $this->leave($memberB, 'Sick Leave');
        $this->dtr($memberA, 'Submitted');
        $this->dtr($memberB, 'Submitted');
        $user = $this->user('supervisor-dashboard', 'Supervisor', $supervisorPersonnel);

        $response = $this->actingAs($user)->getJson(
            "/api/dashboard?department_id={$departmentB}&role=Administrator"
        );

        $response->assertOk()
            ->assertJsonPath('view', 'supervisor')
            ->assertJsonPath('department.code', 'DILG-A')
            ->assertJsonPath('metrics.active_personnel', 2)
            ->assertJsonPath('metrics.present_today', 1)
            ->assertJsonPath('queues.attendance_verification', 1)
            ->assertJsonPath('queues.leave_requests', 1)
            ->assertJsonPath('queues.submitted_dtrs', 1)
            ->assertJsonMissing(['employee_number' => 'MEMBER-B']);
    }

    public function test_hr_receives_workflow_queues_but_not_administrator_security_data(): void
    {
        $departmentId = $this->department('DILG-HR');
        $personnelId = $this->personnel('HR-MEMBER', $departmentId, 'Member');
        $attendanceId = $this->attendance($personnelId, 600, 0, 0, 'Present');
        DB::table('attendance_correction_requests')->insert([
            'attendance_id' => $attendanceId,
            'personnel_id' => $personnelId,
            'attendance_date' => today()->toDateString(),
            'request_status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->leave($personnelId, 'Vacation Leave');
        $this->dtr($personnelId, 'Returned');
        $user = $this->user('hr-dashboard', 'HR');

        $response = $this->actingAs($user)->getJson('/api/dashboard?role=Administrator');

        $response->assertOk()
            ->assertJsonPath('view', 'hr')
            ->assertJsonPath('queues.attendance_verification', 1)
            ->assertJsonPath('queues.correction_requests', 1)
            ->assertJsonPath('queues.leave_requests', 1)
            ->assertJsonPath('queues.returned_dtrs', 1)
            ->assertJsonMissingPath('security')
            ->assertJsonMissingPath('operations');
    }

    public function test_administrator_receives_operations_and_security_aggregates(): void
    {
        $departmentId = $this->department('DILG-ADMIN');
        $personnelId = $this->personnel('ADMIN-MEMBER', $departmentId, 'Member');
        $this->personnel('UNASSIGNED', null, 'Unassigned');
        $this->attendance($personnelId, 600, 5, 0, 'Present');
        $administrator = $this->user('administrator-dashboard', 'Administrator');
        $this->user('locked-dashboard-user', 'Personnel', null, 'Locked', 5);
        DB::table('activity_logs')->insert([
            'user_id' => $administrator->user_id,
            'activity_type' => 'FAILED_LOGIN',
            'description' => 'A test failed login event.',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($administrator)->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('view', 'administrator')
            ->assertJsonPath('operations.active_personnel', 2)
            ->assertJsonPath('operations.active_departments', 1)
            ->assertJsonPath('operations.present_today', 1)
            ->assertJsonPath('operations.unassigned_personnel', 1)
            ->assertJsonPath('security.active_users', 1)
            ->assertJsonPath('security.locked_users', 1)
            ->assertJsonPath('security.security_events_24h', 1);
    }

    public function test_role_change_cannot_reuse_a_cached_administrator_dashboard(): void
    {
        $departmentId = $this->department('DILG-CACHE');
        $personnelId = $this->personnel('CACHE-001', $departmentId, 'Cache');
        $user = $this->user('role-cache-test', 'Administrator', $personnelId);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('view', 'administrator');

        $user->forceFill(['user_role' => 'Personnel'])->save();
        $this->app['auth']->forgetGuards();

        $this->actingAs($user->fresh())->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('view', 'personal')
            ->assertJsonMissingPath('security');
    }

    public function test_encoder_dashboard_is_self_only_not_department_wide(): void
    {
        $departmentId = $this->department('DILG-ENC');
        $encoderPersonnel = $this->personnel('ENCODER-001', $departmentId, 'Encoder');
        $otherPersonnel = $this->personnel('ENCODER-OTHER', $departmentId, 'Other');
        $this->attendance($encoderPersonnel, 480, 0, 0, 'Present');
        $this->attendance($otherPersonnel, 720, 45, 30, 'Present');
        $user = $this->user('encoder-dashboard', 'Encoder', $encoderPersonnel);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('view', 'personal')
            ->assertJsonPath('metrics.work_minutes', 480)
            ->assertJsonPath('metrics.late_minutes', 0)
            ->assertJsonMissing(['employee_number' => 'ENCODER-OTHER']);
    }

    public function test_unauthenticated_dashboard_request_is_rejected(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_unknown_role_fails_closed(): void
    {
        $user = $this->user('unknown-dashboard-role', 'Auditor');

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonMissingPath('operations')
            ->assertJsonMissingPath('security');
    }

    private function department(string $code): int
    {
        return DB::table('departments')->insertGetId([
            'department_code' => $code,
            'department_name' => $code.' Office',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function personnel(string $number, ?int $departmentId, string $firstName): int
    {
        return DB::table('personnel')->insertGetId([
            'department_id' => $departmentId,
            'employee_number' => $number,
            'first_name' => $firstName,
            'last_name' => 'Dashboard',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(
        string $username,
        string $role,
        ?int $personnelId = null,
        string $status = 'Active',
        int $failedAttempts = 0
    ): User {
        return User::create([
            'personnel_id' => $personnelId,
            'username' => $username,
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => $role,
            'status' => $status,
            'failed_login_attempts' => $failedAttempts,
        ]);
    }

    private function attendance(
        int $personnelId,
        int $minutes,
        int $late,
        int $undertime,
        string $status
    ): int {
        return DB::table('attendance_records')->insertGetId([
            'personnel_id' => $personnelId,
            'attendance_date' => today()->toDateString(),
            'morning_time_in' => today()->setTime(7, 0),
            'morning_time_out' => today()->setTime(12, 0),
            'afternoon_time_in' => today()->setTime(13, 0),
            'afternoon_time_out' => today()->setTime(18, 0),
            'attendance_status' => $status,
            'total_work_minutes' => $minutes,
            'late_minutes' => $late,
            'undertime_minutes' => $undertime,
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function leave(int $personnelId, string $type): void
    {
        DB::table('leave_records')->insert([
            'personnel_id' => $personnelId,
            'leave_type' => $type,
            'date_from' => today()->addWeek()->toDateString(),
            'date_to' => today()->addWeek()->toDateString(),
            'approval_status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dtr(int $personnelId, string $status): void
    {
        DB::table('dtr_certifications')->insert([
            'personnel_id' => $personnelId,
            'dtr_year' => today()->year,
            'dtr_month' => today()->month,
            'version_number' => 1,
            'certification_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
