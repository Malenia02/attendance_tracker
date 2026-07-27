<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateInitialAdministrator extends Command
{
    protected $signature = 'system:create-admin {username? : Administrator username}';

    protected $description = 'Securely create the first administrator without exposing a password in shell history';

    public function handle(): int
    {
        if (User::query()->where('user_role', 'Administrator')->where('status', 'Active')->exists()) {
            $this->components->error('An active administrator already exists.');

            return self::FAILURE;
        }

        $username = trim((string) ($this->argument('username') ?: $this->ask('Username')));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        if (! hash_equals($password, $confirmation)) {
            $this->components->error('The password confirmation does not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            [
                'username' => ['required', 'string', 'max:100', 'unique:system_users,username'],
                'password' => [
                    'required',
                    Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
                ],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->line('  [ERROR] '.$error);
            }

            return self::FAILURE;
        }

        User::create([
            'personnel_id' => null,
            'username' => $username,
            'password_hash' => Hash::make($password),
            'user_role' => 'Administrator',
            'status' => 'Active',
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);

        $this->components->info("Administrator '{$username}' created successfully.");

        return self::SUCCESS;
    }
}
