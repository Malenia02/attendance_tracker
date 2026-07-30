<?php

namespace App\Http\Requests;

use App\Models\LeaveRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LeaveRecord::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'leave_type' => ['required', Rule::in([
                'Vacation Leave',
                'Sick Leave',
                'Emergency Leave',
                'Maternity Leave',
                'Paternity Leave',
                'Special Leave',
                'Official Business',
                'Other',
            ])],
            'day_part' => ['required', Rule::in(['Full Day'])],
            'date_from' => [
                'required',
                'date',
                'after_or_equal:'.now()->subDays(90)->toDateString(),
            ],
            'date_to' => [
                'required',
                'date',
                'after_or_equal:date_from',
                'before_or_equal:'.now()->addYear()->toDateString(),
            ],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'supporting_document' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'date_from.after_or_equal' => 'Requests may only be backdated by up to 90 days.',
            'date_to.before_or_equal' => 'A request may not extend more than one year into the future.',
            'supporting_document.max' => 'The supporting document must not exceed 5 MB.',
        ];
    }
}
