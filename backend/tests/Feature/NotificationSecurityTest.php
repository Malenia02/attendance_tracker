<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class NotificationSecurityTest extends TestCase
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
            $table->date('attendance_date');
            $table->dateTime('morning_time_in')->nullable();
            $table->dateTime('morning_time_out')->nullable();
            $table->dateTime('afternoon_time_in')->nullable();
            $table->dateTime('afternoon_time_out')->nullable();
            $table->string('attendance_status')->default('Incomplete');
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
        Schema::create('attendance_correction_requests', function (Blueprint $table): void {
            $table->id('attendance_correction_request_id');
            $table->unsignedBigInteger('personnel_id');
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
            $table->timestamps();
        });
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id('notification_id');
            $table->unsignedBigInteger('user_id');
            $table->string('notification_key', 191);
            $table->string('notification_type', 80);
            $table->string('title', 160);
            $table->string('message', 500);
            $table->string('severity', 20);
            $table->string('action_url', 255)->nullable();
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
            'user_notifications', 'dtr_certifications', 'leave_records',
            'attendance_correction_requests', 'attendance_records',
            'personnel_schedules', 'system_users', 'personnel',
            'work_schedules', 'departments',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_personnel_notifications_are_self_scoped_and_forged_filters_are_ignored(): void
    {
        $department = $this->department('DILG-A');
        $personnelA = $this->personnel('EMP-A', $department);
        $personnelB = $this->personnel('EMP-B', $department);
        $this->missingTimeOut($personnelA);
        $this->missingTimeOut($personnelB);
        $user = $this->user('personnel-a', 'Personnel', $personnelA);

        $response = $this->actingAs($user)
            ->getJson("/api/notifications?personnel_id={$personnelB}&role=Administrator")
            ->assertOk()
            ->assertJsonMissing(['user_id' => $user->user_id])
            ->assertJsonMissing(['metadata' => ['count' => 1]]);

        $missing = collect($response->json('data'))
            ->firstWhere('type', 'missing_time_outs');
        $this->assertNotNull($missing);
        $this->assertStringContainsString('1 of your attendance', $missing['message']);
    }

    public function test_supervisor_notification_counts_are_limited_to_their_department(): void
    {
        $departmentA = $this->department('DILG-A');
        $departmentB = $this->department('DILG-B');
        $supervisorPersonnel = $this->personnel('SUP-A', $departmentA);
        $personnelA = $this->personnel('A-001', $departmentA);
        $personnelB = $this->personnel('B-001', $departmentB);
        $this->completeAttendance($personnelA);
        $this->completeAttendance($personnelB);
        $supervisor = $this->user('supervisor-a', 'Supervisor', $supervisorPersonnel);

        $response = $this->actingAs($supervisor)
            ->getJson('/api/notifications/summary')
            ->assertOk();

        $notification = collect($response->json('data'))
            ->firstWhere('type', 'attendance_verification');
        $this->assertNotNull($notification);
        $this->assertStringStartsWith('1 attendance record is', $notification['message']);
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $userA = $this->user('notification-owner-a', 'Personnel');
        $userB = $this->user('notification-owner-b', 'Personnel');
        $own = $this->notification($userA, 'event:test:own');
        $foreign = $this->notification($userB, 'event:test:foreign');

        $this->actingAs($userA)
            ->patchJson("/api/notifications/{$foreign->notification_id}/read")
            ->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);

        $this->actingAs($userA)
            ->patchJson("/api/notifications/{$own->notification_id}/read")
            ->assertOk()
            ->assertJsonPath('notification.is_read', true);
        $this->assertNotNull($own->fresh()->read_at);
    }

    public function test_changed_notification_content_becomes_unread_again(): void
    {
        $department = $this->department('DILG-ALL');
        $personnel = $this->personnel('EMP-001', $department);
        $this->completeAttendance($personnel);
        $administrator = $this->user('notification-admin', 'Administrator');

        $first = $this->actingAs($administrator)
            ->getJson('/api/notifications/summary')
            ->assertOk();
        $notificationId = collect($first->json('data'))
            ->firstWhere('type', 'attendance_verification')['id'];

        $this->actingAs($administrator)
            ->patchJson("/api/notifications/{$notificationId}/read")
            ->assertOk();
        $this->completeAttendance($personnel);

        $second = $this->actingAs($administrator)
            ->getJson('/api/notifications/summary')
            ->assertOk();
        $notification = collect($second->json('data'))
            ->firstWhere('type', 'attendance_verification');
        $this->assertFalse($notification['is_read']);
        $this->assertStringStartsWith('2 attendance records are', $notification['message']);
    }

    public function test_notification_history_is_paginated_and_page_size_is_bounded(): void
    {
        $user = $this->user('notification-pages', 'Personnel');
        foreach (range(1, 12) as $number) {
            $this->notification($user, "event:test:{$number}", now()->addSeconds($number));
        }

        $this->actingAs($user)
            ->getJson('/api/notifications?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 12);

        $this->actingAs($user)
            ->getJson('/api/notifications?per_page=100')
            ->assertUnprocessable();
    }

    public function test_unauthenticated_notification_requests_are_rejected(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/summary')->assertUnauthorized();
    }

    private function department(string $code): int
    {
        return DB::table('departments')->insertGetId([
            'department_code' => $code,
            'department_name' => "{$code} Office",
        ]);
    }

    private function personnel(string $employeeNumber, ?int $departmentId = null): int
    {
        return DB::table('personnel')->insertGetId([
            'department_id' => $departmentId,
            'employee_number' => $employeeNumber,
            'first_name' => 'Test',
            'last_name' => $employeeNumber,
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

    private function missingTimeOut(int $personnelId): void
    {
        DB::table('attendance_records')->insert([
            'personnel_id' => $personnelId,
            'attendance_date' => now()->subDay()->toDateString(),
            'morning_time_in' => now()->subDay()->setTime(7, 0),
            'attendance_status' => 'Incomplete',
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function completeAttendance(int $personnelId): void
    {
        DB::table('attendance_records')->insert([
            'personnel_id' => $personnelId,
            'attendance_date' => now()->subDay()->toDateString(),
            'morning_time_in' => now()->subDay()->setTime(7, 0),
            'morning_time_out' => now()->subDay()->setTime(12, 0),
            'afternoon_time_in' => now()->subDay()->setTime(13, 0),
            'afternoon_time_out' => now()->subDay()->setTime(18, 0),
            'attendance_status' => 'Present',
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function notification(User $user, string $key, $createdAt = null): UserNotification
    {
        return UserNotification::create([
            'user_id' => $user->user_id,
            'notification_key' => $key,
            'notification_type' => 'test',
            'title' => 'Test notification',
            'message' => 'A safe test notification.',
            'severity' => 'Info',
            'action_url' => '/dashboard',
            'content_hash' => hash('sha256', $key),
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }
}
