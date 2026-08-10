<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\OfficeNetwork;
use App\Models\User;
use App\Services\OfficeNetworkVerifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OfficeNetworkSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.frontend_api_proxy', false);
        config()->set('security.office_network_validity_days', 30);
        config()->set('security.allow_private_office_network_ips', false);

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

        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('department_id');
            $table->string('department_code')->unique();
            $table->string('department_name');
            $table->string('office_location');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('allowed_radius_meters')->default(100);
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        Schema::create('office_networks', function (Blueprint $table): void {
            $table->id('office_network_id');
            $table->unsignedInteger('department_id');
            $table->string('network_name', 80);
            $table->string('ip_address', 45);
            $table->dateTime('verified_at');
            $table->dateTime('expires_at');
            $table->string('status')->default('Active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['department_id', 'ip_address']);
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
        Schema::dropIfExists('office_networks');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('system_users');

        parent::tearDown();
    }

    public function test_only_administrator_can_register_server_detected_public_ip(): void
    {
        $department = $this->department();
        $administrator = $this->user('Administrator', 'network-admin');

        $this->actingAs($administrator)
            ->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->postJson("/api/departments/{$department->department_id}/office-networks", [
                'network_name' => 'Main office internet',
            ])
            ->assertCreated()
            ->assertJsonPath('data.ip_address', '8.8.8.8');

        $this->assertDatabaseHas('office_networks', [
            'department_id' => $department->department_id,
            'ip_address' => '8.8.8.8',
            'status' => 'Active',
            'created_by' => $administrator->user_id,
        ]);

        $hr = $this->user('HR', 'network-hr');
        $this->actingAs($hr)
            ->withServerVariables(['REMOTE_ADDR' => '8.8.4.4'])
            ->postJson("/api/departments/{$department->department_id}/office-networks", [
                'network_name' => 'Unauthorized network',
            ])
            ->assertForbidden();
    }

    public function test_client_cannot_submit_an_ip_and_private_addresses_fail_closed(): void
    {
        $department = $this->department();
        $administrator = $this->user('Administrator', 'network-validation-admin');

        $this->actingAs($administrator)
            ->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->postJson("/api/departments/{$department->department_id}/office-networks", [
                'network_name' => 'Spoof attempt',
                'ip_address' => '1.1.1.1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ip_address');

        $this->actingAs($administrator)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson("/api/departments/{$department->department_id}/office-networks", [
                'network_name' => 'Localhost',
            ])
            ->assertUnprocessable();
    }

    public function test_match_is_department_scoped_active_and_not_expired(): void
    {
        $department = $this->department();
        $other = $this->department('DILG-OTHER');
        OfficeNetwork::create([
            'department_id' => $department->department_id,
            'network_name' => 'Office',
            'ip_address' => '8.8.8.8',
            'verified_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'status' => 'Active',
        ]);
        OfficeNetwork::create([
            'department_id' => $other->department_id,
            'network_name' => 'Expired',
            'ip_address' => '8.8.4.4',
            'verified_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
            'status' => 'Active',
        ]);
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '8.8.8.8']);
        $verifier = app(OfficeNetworkVerifier::class);

        $this->assertNotNull($verifier->match($request, $department->department_id));
        $this->assertNull($verifier->match($request, $other->department_id));
    }

    private function department(string $code = 'DILG-IPIL'): Department
    {
        return Department::create([
            'department_code' => $code,
            'department_name' => $code.' Office',
            'office_location' => 'Ipil, Zamboanga Sibugay',
            'latitude' => 7.7845,
            'longitude' => 122.5868,
            'allowed_radius_meters' => 100,
            'status' => 'Active',
        ]);
    }

    private function user(string $role, string $username): User
    {
        return User::create([
            'username' => $username,
            'password_hash' => bcrypt('ValidPassword!123'),
            'user_role' => $role,
            'status' => 'Active',
        ]);
    }
}
