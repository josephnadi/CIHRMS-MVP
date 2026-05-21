<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Enums\DataSubjectRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitPublicDpaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint — gated by reCAPTCHA-style throttling at the route
    }

    public function rules(): array
    {
        return [
            'subject_email'         => ['required', 'email', 'max:255'],
            'subject_full_name'     => ['required', 'string', 'min:2', 'max:120'],
            'request_type'          => ['required', Rule::enum(DataSubjectRequestType::class)],
            'subject_statement'     => ['required', 'string', 'min:10', 'max:5000'],
            'rectification_details' => ['nullable', 'string', 'max:2000'],
            'objection_purpose'     => ['nullable', 'string', 'max:2000'],
        ];
    }
}
