<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
            $table->string('signature')->nullable();
            $table->string('qr_login_code', 100)->nullable()->unique();
            $table->date('qr_valid_from')->nullable();
            $table->date('qr_valid_until')->nullable();
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

    public function test_card_validity_is_separate_and_cannot_exceed_employment(): void
    {
        $department = $this->department();
        $administrator = $this->administrator();
        $payload = [
            ...$this->gipPayload($department->department_id, 'Validity'),
            'employment_start_date' => '2026-07-01',
            'employment_end_date' => '2026-12-31',
            'qr_valid_from' => '2026-08-01',
            'qr_valid_until' => '2027-07-31',
        ];

        $this->actingAs($administrator)
            ->postJson('/api/personnel', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qr_valid_until']);

        $payload['qr_valid_until'] = '2026-12-31';

        $response = $this->actingAs($administrator)
            ->postJson('/api/personnel', $payload)
            ->assertCreated()
            ->assertJsonPath('data.employment_start_date', '2026-07-01')
            ->assertJsonPath('data.employment_end_date', '2026-12-31')
            ->assertJsonPath('data.qr_valid_from', '2026-08-01')
            ->assertJsonPath('data.qr_valid_until', '2026-12-31');

        $saved = Personnel::findOrFail($response->json('data.personnel_id'));
        $this->assertSame('2026-07-01', $saved->employment_start_date->format('Y-m-d'));
        $this->assertSame('2026-12-31', $saved->employment_end_date->format('Y-m-d'));
        $this->assertSame('2026-08-01', $saved->qr_valid_from->format('Y-m-d'));
        $this->assertSame('2026-12-31', $saved->qr_valid_until->format('Y-m-d'));
    }

    public function test_signature_image_is_stored_privately_and_returned_through_an_authorized_route(): void
    {
        Storage::fake('local');
        $department = $this->department();
        $administrator = $this->administrator();

        $response = $this->actingAs($administrator)
            ->post('/api/personnel', [
                ...$this->gipPayload($department->department_id, 'Signed'),
                'signature' => UploadedFile::fake()->createWithContent(
                    'signature.png',
                    $this->pngImage(600, 200)
                ),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $personnel = Personnel::findOrFail($response->json('data.personnel_id'));

        $this->assertNotNull($personnel->signature);
        Storage::disk('local')->assertExists($personnel->signature);
        $this->assertNotNull($response->json('data.signature_url'));

        $this->actingAs($administrator)
            ->get('/api/personnel/'.$personnel->personnel_id.'/signature')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $unrelatedPersonnel = Personnel::create([
            'employee_number' => 'GIP-UNRELATED-2026-0001',
            'first_name' => 'Unrelated',
            'last_name' => 'Personnel',
            'personnel_type' => 'GIP',
            'status' => 'Active',
        ]);
        $unrelatedUser = User::create([
            'personnel_id' => $unrelatedPersonnel->personnel_id,
            'username' => 'unrelated-personnel',
            'password_hash' => 'not-used',
            'user_role' => 'Personnel',
            'status' => 'Active',
        ]);

        $this->actingAs($unrelatedUser)
            ->get('/api/personnel/'.$personnel->personnel_id.'/signature')
            ->assertForbidden();
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

    private function pngImage(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data))
                .$type
                .$data
                .pack('N', crc32($type.$data));
        };
        $header = pack('NNC5', $width, $height, 8, 2, 0, 0, 0);
        $row = "\x00".str_repeat("\xFF\xFF\xFF", $width);

        return "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', $header)
            .$chunk('IDAT', gzcompress(str_repeat($row, $height), 9))
            .$chunk('IEND', '');
    }
}
