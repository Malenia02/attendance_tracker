<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ManualAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()
            && in_array($this->user()->user_role, ['Administrator', 'HR'], true);
    }

    public function rules(): array
    {
        return [
            'personnel_id' => ['required', 'integer', 'exists:personnel,personnel_id'],
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'record_type' => ['required', Rule::in([
                'Time Entries',
                'Absent',
                'Leave',
                'Official Business',
                'Work From Home',
            ])],
            'morning_time_in' => ['nullable', 'date_format:H:i'],
            'morning_time_out' => ['nullable', 'date_format:H:i'],
            'afternoon_time_in' => ['nullable', 'date_format:H:i'],
            'afternoon_time_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ];
    }
}
