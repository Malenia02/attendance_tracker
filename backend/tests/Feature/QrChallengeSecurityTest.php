<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QrChallengeSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personnel', function (Blueprint $table): void {
            $table->id('personnel_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('employee_number')->unique();
            $table->string('first_name')->default('Test');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->default('User');
            $table->string('suffix')->nullable();
            $table->string('personnel_type')->default('GIP');
            $table->string('position_title')->nullable();
            $table->date('employment_start_date')->nullable();
            $table->date('employment_end_date')->nullable();
            $table->string('photo')->nullable();
            $table->string('signature')->nullable();
            $table->string('qr_login_code')->nullable();
            $table->date('qr_valid_from')->nullable();
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

        Schema::create('user_access_tokens', function (Blueprint $table): void {
            $table->id('token_id');
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->timestamps();
        });

        Schema::create('attendance_qr_tokens', function (Blueprint $table): void {
            $table->id('qr_token_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('purpose');
            $table->dateTime('valid_from');
            $table->dateTime('expires_at');
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('maximum_uses')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('qr_scan_logs', function (Blueprint $table): void {
            $table->id('qr_scan_id');
            $table->unsignedBigInteger('qr_token_id')->nullable();
            $table->unsignedBigInteger('personnel_id')->nullable();
            $table->unsignedBigInteger('attendance_id')->nullable();
            $table->string('scan_action');
            $table->dateTime('scanned_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('location_accuracy_meters', 8, 2)->nullable();
            $table->dateTime('position_recorded_at')->nullable();
            $table->decimal('distance_from_office_meters', 10, 2)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_identifier')->nullable();
            $table->unsignedBigInteger('scanned_by')->nullable();
            $table->string('scan_status');
            $table->string('message')->nullable();
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
        Schema::dropIfExists('qr_scan_logs');
        Schema::dropIfExists('attendance_qr_tokens');
        Schema::dropIfExists('user_access_tokens');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');

        parent::tearDown();
    }

    public function test_a_kiosk_challenge_is_single_use_and_device_bound(): void
    {
        $user = User::create([
            'username' => 'qr-admin',
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => 'Administrator',
            'status' => 'Active',
        ]);
        $device = 'test-kiosk-device-001';

        $challenge = $this->actingAs($user)
            ->postJson('/api/qr-attendance/challenge', [
                'device_identifier' => $device,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('challenge');

        $payload = [
            'code' => 'DILGATTEND:v1:999:invalid-signature',
            'challenge' => $challenge,
            'device_identifier' => $device,
        ];

        $this->actingAs($user)
            ->postJson('/api/qr-attendance/scan', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The QR card is invalid or has been revoked.');

        $this->actingAs($user)
            ->postJson('/api/qr-attendance/scan', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->assertDatabaseHas('attendance_qr_tokens', [
            'created_by' => $user->user_id,
            'used_count' => 1,
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('qr_scan_logs', 1);
    }

    public function test_personnel_can_view_and_scan_only_their_own_qr_card(): void
    {
        $personnelId = DB::table('personnel')->insertGetId([
            'employee_number' => 'GIP-TEST-001',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'personnel_type' => 'GIP',
            'employment_start_date' => '2026-01-01',
            'employment_end_date' => '2027-12-31',
            'qr_login_code' => hash('sha256', 'personnel-test-card'),
            'qr_valid_from' => '2026-07-01',
            'qr_valid_until' => '2027-06-30',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::create([
            'personnel_id' => $personnelId,
            'username' => 'personnel-user',
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => 'Personnel',
            'status' => 'Active',
        ]);

        $this->actingAs($user)
            ->getJson('/api/qr-attendance')
            ->assertOk()
            ->assertJsonPath('can_scan', true)
            ->assertJsonPath('can_scan_others', false)
            ->assertJsonPath('scan_scope', 'self')
            ->assertJsonPath('can_view_cards', true)
            ->assertJsonPath('can_manage_codes', false)
            ->assertJsonCount(1, 'personnel')
            ->assertJsonPath('personnel.0.personnel_id', $personnelId)
            ->assertJsonPath('personnel.0.validity_label', '2026 - 2027');

        $this->actingAs($user)
            ->postJson('/api/qr-attendance/challenge', [
                'device_identifier' => 'personnel-device-001',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_supervisor_and_encoder_can_use_self_service_qr_scanning(): void
    {
        foreach (['Supervisor', 'Encoder'] as $role) {
            $personnelId = DB::table('personnel')->insertGetId([
                'employee_number' => strtoupper($role).'-QR-001',
                'first_name' => $role,
                'last_name' => 'Scanner',
                'personnel_type' => 'Regular',
                'qr_login_code' => hash('sha256', $role.'-scanner-card'),
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $user = User::create([
                'personnel_id' => $personnelId,
                'username' => strtolower($role).'-self-scanner',
                'password_hash' => bcrypt('ValidPassword!123'),
                'user_role' => $role,
                'status' => 'Active',
            ]);

            $this->actingAs($user)
                ->getJson('/api/qr-attendance')
                ->assertOk()
                ->assertJsonPath('can_scan', true)
                ->assertJsonPath('can_scan_others', false)
                ->assertJsonPath('can_manage_codes', false)
                ->assertJsonCount(1, 'personnel')
                ->assertJsonPath('personnel.0.personnel_id', $personnelId);

            $this->actingAs($user)
                ->postJson('/api/qr-attendance/challenge', [
                    'device_identifier' => strtolower($role).'-device-001',
                ])
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    public function test_hr_can_operate_the_qr_kiosk(): void
    {
        $hr = User::create([
            'username' => 'hr-kiosk-operator',
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => 'HR',
            'status' => 'Active',
        ]);

        $this->actingAs($hr)
            ->getJson('/api/qr-attendance')
            ->assertOk()
            ->assertJsonPath('can_scan', true)
            ->assertJsonPath('can_scan_others', true)
            ->assertJsonPath('scan_scope', 'all_personnel');

        $this->actingAs($hr)
            ->postJson('/api/qr-attendance/challenge', [
                'device_identifier' => 'hr-kiosk-device-001',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_non_admin_and_non_hr_accounts_cannot_scan_another_personnel_card(): void
    {
        $ownPersonnelId = DB::table('personnel')->insertGetId([
            'employee_number' => 'GIP-SELF-SCAN-001',
            'first_name' => 'Self',
            'last_name' => 'Scanner',
            'personnel_type' => 'GIP',
            'qr_login_code' => hash('sha256', 'self-scan-card'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCredential = hash('sha256', 'different-personnel-card');
        $otherPersonnelId = DB::table('personnel')->insertGetId([
            'employee_number' => 'GIP-OTHER-SCAN-001',
            'first_name' => 'Different',
            'last_name' => 'Personnel',
            'personnel_type' => 'GIP',
            'qr_login_code' => $otherCredential,
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $signature = hash_hmac(
            'sha256',
            "DILGATTEND|v1|{$otherPersonnelId}|{$otherCredential}",
            (string) config('attendance.qr_signing_key')
        );

        foreach (['Supervisor', 'Encoder', 'Personnel'] as $role) {
            $user = User::create([
                'personnel_id' => $ownPersonnelId,
                'username' => strtolower($role).'-other-card-blocked',
                'password_hash' => bcrypt('ValidPassword!123'),
                'user_role' => $role,
                'status' => 'Active',
            ]);
            $device = strtolower($role).'-self-only-device';
            $challenge = $this->actingAs($user)
                ->postJson('/api/qr-attendance/challenge', [
                    'device_identifier' => $device,
                ])
                ->assertOk()
                ->json('challenge');

            $this->actingAs($user)
                ->postJson('/api/qr-attendance/scan', [
                    'code' => "DILGATTEND:v1:{$otherPersonnelId}:{$signature}",
                    'challenge' => $challenge,
                    'device_identifier' => $device,
                ])
                ->assertForbidden()
                ->assertJsonPath(
                    'message',
                    'You can only record attendance using the QR card linked to your own account. This card belongs to another personnel member.'
                );

            $this->actingAs($user)
                ->getJson('/api/qr-attendance/cards')
                ->assertForbidden();

            $user->delete();
        }

        $this->assertDatabaseCount('qr_scan_logs', 3);
    }

    public function test_scan_rejects_a_card_outside_its_own_validity_period(): void
    {
        $credential = hash('sha256', 'expired-card-credential');
        $personnelId = DB::table('personnel')->insertGetId([
            'employee_number' => 'GIP-EXPIRED-CARD',
            'first_name' => 'Expired',
            'last_name' => 'Credential',
            'personnel_type' => 'GIP',
            'employment_start_date' => now()->subYear()->toDateString(),
            'employment_end_date' => now()->addYear()->toDateString(),
            'qr_login_code' => $credential,
            'qr_valid_from' => now()->subYear()->toDateString(),
            'qr_valid_until' => now()->subDay()->toDateString(),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $administrator = User::create([
            'username' => 'expired-card-admin',
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => 'Administrator',
            'status' => 'Active',
        ]);
        $device = 'expired-card-device-001';
        $challenge = $this->actingAs($administrator)
            ->postJson('/api/qr-attendance/challenge', [
                'device_identifier' => $device,
            ])
            ->assertOk()
            ->json('challenge');
        $signature = hash_hmac(
            'sha256',
            "DILGATTEND|v1|{$personnelId}|{$credential}",
            (string) config('attendance.qr_signing_key')
        );

        $this->actingAs($administrator)
            ->postJson('/api/qr-attendance/scan', [
                'code' => "DILGATTEND:v1:{$personnelId}:{$signature}",
                'challenge' => $challenge,
                'device_identifier' => $device,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'This personnel card is not currently valid. Ask Administrator or HR to review its validity dates.'
            );

        $this->assertDatabaseHas('qr_scan_logs', [
            'personnel_id' => $personnelId,
            'scan_status' => 'Expired Credential',
        ]);
    }

    public function test_non_card_managers_can_only_view_their_own_card_and_cannot_regenerate_it(): void
    {
        $ownPersonnelId = DB::table('personnel')->insertGetId([
            'employee_number' => 'GIP-SUPERVISOR-001',
            'first_name' => 'Maria',
            'last_name' => 'Supervisor',
            'personnel_type' => 'GIP',
            'qr_login_code' => hash('sha256', 'supervisor-card'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherPersonnelId = DB::table('personnel')->insertGetId([
            'employee_number' => 'GIP-OTHER-001',
            'first_name' => 'Other',
            'last_name' => 'Personnel',
            'personnel_type' => 'GIP',
            'qr_login_code' => hash('sha256', 'other-card'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['Supervisor', 'Encoder'] as $role) {
            $user = User::create([
                'personnel_id' => $ownPersonnelId,
                'username' => strtolower($role).'-card-user',
                'password_hash' => bcrypt('ValidPassword!123'),
                'user_role' => $role,
                'status' => 'Active',
            ]);

            $this->actingAs($user)
                ->getJson('/api/qr-attendance')
                ->assertOk()
                ->assertJsonPath('can_view_cards', true)
                ->assertJsonPath('can_manage_codes', false)
                ->assertJsonCount(1, 'personnel')
                ->assertJsonPath('personnel.0.personnel_id', $ownPersonnelId);

            $this->actingAs($user)
                ->postJson("/api/qr-attendance/personnel/{$otherPersonnelId}/regenerate")
                ->assertForbidden();

            $user->delete();
        }
    }

    public function test_administrator_and_hr_can_regenerate_personnel_cards(): void
    {
        $personnelId = DB::table('personnel')->insertGetId([
            'employee_number' => 'GIP-REGENERATE-001',
            'first_name' => 'Card',
            'last_name' => 'Holder',
            'personnel_type' => 'GIP',
            'qr_login_code' => hash('sha256', 'original-card'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['Administrator', 'HR'] as $role) {
            $user = User::create([
                'username' => strtolower($role).'-regenerator',
                'password_hash' => bcrypt('ValidPassword!123'),
                'user_role' => $role,
                'status' => 'Active',
            ]);
            $previousCode = DB::table('personnel')
                ->where('personnel_id', $personnelId)
                ->value('qr_login_code');

            $this->actingAs($user)
                ->postJson("/api/qr-attendance/personnel/{$personnelId}/regenerate")
                ->assertOk()
                ->assertJsonPath('personnel.personnel_id', $personnelId);

            $this->assertNotSame(
                $previousCode,
                DB::table('personnel')->where('personnel_id', $personnelId)->value('qr_login_code')
            );
        }
    }

    public function test_card_manager_browsing_is_paginated_and_role_protected(): void
    {
        foreach (range(1, 13) as $number) {
            DB::table('personnel')->insert([
                'employee_number' => 'CARD-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'first_name' => 'Card',
                'last_name' => str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'personnel_type' => 'GIP',
                'qr_login_code' => hash('sha256', "card-{$number}"),
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $administrator = User::create([
            'username' => 'card-browser-admin',
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => 'Administrator',
            'status' => 'Active',
        ]);
        $personnelUser = User::create([
            'username' => 'card-browser-personnel',
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => 'Personnel',
            'status' => 'Active',
        ]);

        $this->actingAs($administrator)
            ->getJson('/api/qr-attendance/cards?page=2&per_page=6')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('meta.pagination.current_page', 2)
            ->assertJsonPath('meta.pagination.total', 13)
            ->assertJsonPath('meta.pagination.last_page', 3);

        $this->actingAs($personnelUser)
            ->getJson('/api/qr-attendance/cards')
            ->assertForbidden();
    }
}
