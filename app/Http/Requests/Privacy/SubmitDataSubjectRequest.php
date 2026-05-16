<?php

namespace App\Http\Requests\Privacy;

use App\Enums\DataSubjectRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitDataSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Every authenticated user has the right to submit.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'request_type'          => ['required', Rule::enum(DataSubjectRequestType::class)],
            'subject_statement'     => ['required', 'string', 'min:10', 'max:5000'],
            'rectification_details' => ['nullable', 'string', 'max:5000', 'required_if:request_type,rectification'],
            'objection_purpose'     => ['nullable', 'string', 'max:5000', 'required_if:request_type,objection'],
        ];
    }
}
