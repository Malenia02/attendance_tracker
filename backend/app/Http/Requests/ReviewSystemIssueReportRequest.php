<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewSystemIssueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->user_role === 'Administrator';
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['Open', 'In Progress', 'Resolved', 'Closed'])],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
