<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ActionCenterIndexRequest extends FormRequest
{
    private const QUEUES = [
        'attendance_verification',
        'missing_time_outs',
        'correction_requests',
        'leave_requests',
        'returned_dtrs',
        'expiring_qr_cards',
        'workforce_gaps',
    ];

    public function authorize(): bool
    {
        return $this->user()
            && in_array(
                $this->user()->user_role,
                ['Administrator', 'HR', 'Supervisor'],
                true
            );
    }

    public function rules(): array
    {
        return [
            'queue' => ['nullable', Rule::in(self::QUEUES)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:10,50'],
        ];
    }
}
