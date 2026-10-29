<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSystemIssueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in([
                'Attendance',
                'DTR',
                'Account Access',
                'Performance',
                'Incorrect Data',
                'Other',
            ])],
            'subject' => ['required', 'string', 'min:5', 'max:120'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'page_url' => ['nullable', 'string', 'max:500', 'regex:/^\/(?!\/)[^\r\n]*$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'page_url.regex' => 'The affected page must be a page inside Attendance Hub.',
        ];
    }
}
