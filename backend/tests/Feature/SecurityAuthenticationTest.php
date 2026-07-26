<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SecurityAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personnel', function (Blueprint $table): void {
            $table->id('personnel_id');
            $table->string('email')->nullable()->unique();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->unsignedBigInteger('personnel_id')->nullable()->unique();
            $table->string('username')->unique();
            $table->string('password_hash');
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
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('user_access_tokens', function (Blueprint $table): void {
            $table->id('token_id');
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64)->unique();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('user_access_tokens');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('personnel');

        parent::tearDown();
    }

    public function test_successful_login_uses_a_session_and_does_not_return_a_bearer_token(): void
    {
        $user = $this->createUser('session-user', 'Active');

        $response = $this
            ->withHeader('Referer', 'http://localhost/login')
            ->postJson('/api/auth/login', [
                'username' => 'session-user',
                'password' => 'Strong-Test-Password!2026',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.user_id', $user->user_id)
            ->assertJsonMissingPath('token');

        $this->assertAuthenticatedAs($user);

        $this
            ->withHeader('Referer', 'http://localhost/dashboard')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.username', 'session-user');
    }

    public function test_unknown_and_inactive_accounts_receive_the_same_generic_response(): void
    {
        $this->createUser('inactive-user', 'Inactive');

        $unknown = $this->postJson('/api/auth/login', [
            'username' => 'unknown-user',
            'password' => 'Strong-Test-Password!2026',
        ]);
        $inactive = $this->postJson('/api/auth/login', [
            'username' => 'inactive-user',
            'password' => 'Strong-Test-Password!2026',
        ]);

        $unknown->assertStatus(401);
        $inactive->assertStatus(401);
        $this->assertSame($unknown->json('message'), $inactive->json('message'));
        $this->assertArrayNotHasKey('attempts_remaining', $inactive->json());
        $this->assertArrayNotHasKey('locked_until', $inactive->json());
    }

    public function test_security_headers_are_added_to_responses(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    private function createUser(string $username, string $status): User
    {
        return User::create([
            'username' => $username,
            'password_hash' => Hash::make('Strong-Test-Password!2026'),
            'user_role' => 'Personnel',
            'status' => $status,
            'failed_login_attempts' => 0,
        ]);
    }
}
