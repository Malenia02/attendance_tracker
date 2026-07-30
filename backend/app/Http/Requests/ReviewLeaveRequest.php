<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRecord = $this->route('leaveRecord');

        return $leaveRecord && ($this->user()?->can('review', $leaveRecord) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['Approved', 'Rejected'])],
            'remarks' => [
                Rule::requiredIf($this->input('decision') === 'Rejected'),
                'nullable',
                'string',
                'min:10',
                'max:1000',
            ],
        ];
    }
}
