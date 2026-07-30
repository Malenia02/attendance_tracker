<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRecord = $this->route('leaveRecord');

        return $leaveRecord && ($this->user()?->can('cancel', $leaveRecord) ?? false);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
