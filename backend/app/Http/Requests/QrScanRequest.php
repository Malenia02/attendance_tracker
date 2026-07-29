<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class QrScanRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:255'],
            'challenge' => ['required', 'string', 'size:64'],
            'device_identifier' => ['required', 'string', 'min:8', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'between:0,10000'],
            'position_timestamp' => ['nullable', 'date'],
        ];
    }
}
