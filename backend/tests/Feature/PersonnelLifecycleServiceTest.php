<?php

namespace Tests\Feature;

use App\Models\Personnel;
use App\Models\User;
use App\Models\UserAccessToken;
use App\Services\PersonnelLifecycleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonnelLifecycleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->date('employment_start_date')->nullable();
            $table->date('employment_end_date')->nullable();
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
            $table->rememberToken();
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
        Schema::create('user_access_tokens', function (Blueprint $table): void {
            $table->id('token_id');
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->timestamps();
        });
        Schema::create('personnel_activation_logs', function (Blueprint $table): void {
            $table->id('personnel_activation_log_id');
            $table->unsignedBigInteger('personnel_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('from_status');
            $table->string('to_status');
            $table->string('reason')->nullable();
            $table->json('readiness_snapshot');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->nullable();
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
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id('notification_id');
            $table->unsignedBigInteger('user_id');
            $table->string('notification_key');
            $table->string('notification_type');
            $table->string('title');
            $table->string('message');
            $table->string('severity');
            $table->string('action_url')->nullable();
            $table->char('content_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'notification_key']);
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'user_notifications',
            'activity_logs',
            'personnel_activation_logs',
            'user_access_tokens',
            'personnel_schedules',
            'system_users',
            'personnel',
            'departments',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_expired_employment_is_securely_offboarded_once(): void
    {
        $departmentId = $this->department('DILG-LIFE');
        $personnel = $this->personnel('LIFE-001', $departmentId, today()->subDay()->toDateString());
        $linkedUser = $this->user('expired-personnel', 'Personnel', $personnel->personnel_id);
        $administrator = $this->user('lifecycle-admin', 'Administrator');
        $hr = $this->user('lifecycle-hr', 'HR');
        UserAccessToken::create([
            'user_id' => $linkedUser->user_id,
            'token_hash' => hash('sha256', 'expired-session'),
            'expires_at' => now()->addHour(),
        ]);
        DB::table('personnel_schedules')->insert([
            [
                'personnel_id' => $personnel->personnel_id,
                'schedule_id' => 1,
                'effective_from' => today()->subMonth()->toDateString(),
                'effective_to' => null,
                'created_at' => now(),
            ],
            [
                'personnel_id' => $personnel->personnel_id,
                'schedule_id' => 2,
                'effective_from' => today()->addWeek()->toDateString(),
                'effective_to' => null,
                'created_at' => now(),
            ],
        ]);

        $summary = app(PersonnelLifecycleService::class)->process(today());

        $this->assertSame(1, $summary['completed']);
        $this->assertSame('Completed', $personnel->fresh()->status);
        $this->assertSame('Inactive', $linkedUser->fresh()->status);
        $this->assertDatabaseMissing('user_access_tokens', ['user_id' => $linkedUser->user_id]);
        $this->assertDatabaseHas('personnel_schedules', [
            'personnel_id' => $personnel->personnel_id,
            'schedule_id' => 1,
            'effective_to' => today()->subDay()->toDateString(),
        ]);
        $this->assertDatabaseMissing('personnel_schedules', [
            'personnel_id' => $personnel->personnel_id,
            'schedule_id' => 2,
        ]);
        $this->assertDatabaseHas('personnel_activation_logs', [
            'personnel_id' => $personnel->personnel_id,
            'from_status' => 'Active',
            'to_status' => 'Completed',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'entity_id' => $personnel->personnel_id,
            'activity_type' => 'PERSONNEL_AUTO_OFFBOARDED',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $administrator->user_id,
            'notification_type' => 'PersonnelLifecycle',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $hr->user_id,
            'notification_type' => 'PersonnelLifecycle',
        ]);

        $second = app(PersonnelLifecycleService::class)->process(today());

        $this->assertSame(0, $second['completed']);
        $this->assertSame(1, DB::table('personnel_activation_logs')->count());
        $this->assertSame(1, DB::table('activity_logs')->count());
    }

    public function test_reminders_are_role_and_department_scoped(): void
    {
        $departmentA = $this->department('DILG-A');
        $departmentB = $this->department('DILG-B');
        $ending = $this->personnel('ENDING-001', $departmentA, today()->addDays(5)->toDateString());
        $supervisorA = $this->user(
            'supervisor-a',
            'Supervisor',
            $this->personnel('SUP-A', $departmentA)->personnel_id
        );
        $supervisorB = $this->user(
            'supervisor-b',
            'Supervisor',
            $this->personnel('SUP-B', $departmentB)->personnel_id
        );

        app(PersonnelLifecycleService::class)->process(today());

        $key = "lifecycle:employment-ending:{$ending->personnel_id}:{$ending->employment_end_date->toDateString()}";
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $supervisorA->user_id,
            'notification_key' => $key,
            'severity' => 'Warning',
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $supervisorB->user_id,
            'notification_key' => $key,
        ]);
    }

    private function department(string $code): int
    {
        return DB::table('departments')->insertGetId([
            'department_code' => $code,
            'department_name' => "{$code} Office",
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function personnel(string $number, ?int $departmentId, ?string $endDate = null): Personnel
    {
        return Personnel::create([
            'department_id' => $departmentId,
            'employee_number' => $number,
            'first_name' => $number,
            'last_name' => 'Lifecycle',
            'employment_start_date' => today()->subMonth()->toDateString(),
            'employment_end_date' => $endDate,
            'status' => 'Active',
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
}
