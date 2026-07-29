<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonnelNumberingSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('departments', function (Blueprint $table): void {
            $table->id('department_id');
            $table->string('department_code')->unique();
            $table->string('department_name');
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        Schema::create('personnel', function (Blueprint $table): void {
            $table->id('personnel_id');
            $table->string('employee_number', 50)->unique();
            $table->string('biometric_number', 50)->nullable()->unique();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();
            $table->string('sex')->nullable();
            $table->string('personnel_type')->default('GIP');
            $table->string('position_title', 150)->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->date('employment_start_date')->nullable();
            $table->date('employment_end_date')->nullable();
            $table->string('email', 150)->nullable()->unique();
            $table->string('contact_number', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('photo')->nullable();
            $table->string('qr_login_code', 100)->nullable()->unique();
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
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');
        Schema::dropIfExists('departments');

        parent::tearDown();
    }

    public function test_gip_employee_numbers_are_generated_safely_on_create(): void
    {
        $department = $this->department();
        $administrator = $this->administrator();

        $first = $this->actingAs($administrator)
            ->postJson('/api/personnel', $this->gipPayload($department->department_id, 'Louie'))
            ->assertCreated();
        $second = $this->actingAs($administrator)
            ->postJson('/api/personnel', $this->gipPayload($department->department_id, 'Jamie'))
            ->assertCreated();

        $this->assertSame('GIP-ZSP-2026-0001', $first->json('data.employee_number'));
        $this->assertSame('GIP-ZSP-2026-0002', $second->json('data.employee_number'));
        $this->assertNotSame(
            $first->json('data.employee_number'),
            $second->json('data.employee_number')
        );
        $this->assertNull($first->json('data.biometric_number'));
    }

    public function test_generated_gip_number_cannot_be_changed_through_update(): void
    {
        $department = $this->department();
        $administrator = $this->administrator();
        $created = $this->actingAs($administrator)
            ->postJson('/api/personnel', $this->gipPayload($department->department_id, 'Louie'))
            ->assertCreated()
            ->json('data');

        $payload = $this->gipPayload($department->department_id, 'Louie');
        $payload['employee_number'] = 'TAMPERED-NUMBER';

        $this->actingAs($administrator)
            ->putJson('/api/personnel/'.$created['personnel_id'], $payload)
            ->assertOk()
            ->assertJsonPath('data.employee_number', 'GIP-ZSP-2026-0001');

        $this->assertDatabaseHas('personnel', [
            'personnel_id' => $created['personnel_id'],
            'employee_number' => 'GIP-ZSP-2026-0001',
        ]);
    }

    public function test_non_gip_personnel_still_requires_an_official_employee_number(): void
    {
        $this->actingAs($this->administrator())
            ->postJson('/api/personnel', [
                'first_name' => 'Regular',
                'last_name' => 'Employee',
                'personnel_type' => 'Regular',
                'status' => 'Active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_number');
    }

    public function test_other_personnel_types_can_use_automatic_numbering(): void
    {
        $department = $this->department();
        $administrator = $this->administrator();
        $types = [
            'Regular' => 'REG',
            'Contractual' => 'CON',
            'Job Order' => 'JO',
            'Casual' => 'CAS',
            'Other' => 'OTH',
        ];
        $sequence = 1;

        foreach ($types as $type => $prefix) {
            $this->actingAs($administrator)
                ->postJson('/api/personnel', [
                    'auto_generate_employee_number' => true,
                    'first_name' => $prefix,
                    'last_name' => 'Employee',
                    'personnel_type' => $type,
                    'department_id' => $department->department_id,
                    'employment_start_date' => '2026-07-01',
                    'status' => 'Active',
                ])
                ->assertCreated()
                ->assertJsonPath(
                    'data.employee_number',
                    sprintf('%s-ZSP-2026-%04d', $prefix, $sequence)
                );

            $sequence++;
        }
    }

    public function test_non_gip_personnel_can_keep_an_official_employee_number(): void
    {
        $this->actingAs($this->administrator())
            ->postJson('/api/personnel', [
                'auto_generate_employee_number' => false,
                'employee_number' => 'DILG-OFFICIAL-105',
                'first_name' => 'Official',
                'last_name' => 'Employee',
                'personnel_type' => 'Regular',
                'status' => 'Active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'DILG-OFFICIAL-105');

        $this->assertDatabaseHas('personnel', [
            'employee_number' => 'DILG-OFFICIAL-105',
            'personnel_type' => 'Regular',
        ]);
    }

    private function department(): Department
    {
        return Department::create([
            'department_code' => 'ZSP',
            'department_name' => 'Zamboanga del Sur Provincial Office',
            'status' => 'Active',
        ]);
    }

    private function administrator(): User
    {
        return User::firstOrCreate(
            ['username' => 'numbering-admin'],
            [
                'password_hash' => 'not-used',
                'user_role' => 'Administrator',
                'status' => 'Active',
            ]
        );
    }

    private function gipPayload(int $departmentId, string $firstName): array
    {
        return [
            'first_name' => $firstName,
            'last_name' => 'Employee',
            'personnel_type' => 'GIP',
            'department_id' => $departmentId,
            'employment_start_date' => '2026-07-01',
            'status' => 'Active',
        ];
    }
}
