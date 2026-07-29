<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class QrChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()
            && in_array(
                $this->user()->user_role,
                ['Administrator', 'HR', 'Supervisor', 'Encoder', 'Personnel'],
                true
            );
    }

    public function rules(): array
    {
        return [
            'device_identifier' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }
}
