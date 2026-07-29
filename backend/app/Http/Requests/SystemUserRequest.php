<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class SystemUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->user_role === 'Administrator';
    }

    public function rules(): array
    {
        /** @var User|null $systemUser */
        $systemUser = $this->route('system_user') ?? $this->route('systemUser');

        return [
            'personnel_id' => [
                'nullable',
                'integer',
                'exists:personnel,personnel_id',
                Rule::unique('system_users', 'personnel_id')
                    ->ignore($systemUser?->user_id, 'user_id'),
            ],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:100',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('system_users', 'username')
                    ->ignore($systemUser?->user_id, 'user_id'),
            ],
            'password' => [
                $systemUser ? 'nullable' : 'required',
                'string',
                'max:72',
                'confirmed',
                Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
            'user_role' => [
                'required',
                Rule::in(['Administrator', 'HR', 'Supervisor', 'Encoder', 'Personnel']),
            ],
            'status' => ['required', Rule::in(['Active', 'Inactive', 'Locked'])],
        ];
    }
}
