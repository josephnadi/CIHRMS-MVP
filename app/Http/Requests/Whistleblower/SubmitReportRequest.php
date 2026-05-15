<?php

namespace App\Http\Requests\Whistleblower;

use App\Enums\WhistleblowerCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitReportRequest extends FormRequest
{
    /** Public endpoint — anyone can submit. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category'         => ['required', Rule::enum(WhistleblowerCategory::class)],
            'subject_summary'  => ['required', 'string', 'max:255'],
            'description'      => ['required', 'string', 'min:30', 'max:20000'],
            'desired_outcome'  => ['nullable', 'string', 'max:5000'],
            'incident_location'=> ['nullable', 'string', 'max:500'],
            'incident_date'    => ['nullable', 'date', 'before_or_equal:today'],
            'is_anonymous'     => ['nullable', 'boolean'],
            'submitter_contact'=> ['nullable', 'string', 'max:255', 'required_if:is_anonymous,false'],

            'subjects'                      => ['nullable', 'array', 'max:10'],
            'subjects.*.label'              => ['required_with:subjects', 'string', 'max:255'],
            'subjects.*.role_context'       => ['nullable', 'string', 'max:1000'],
            'subjects.*.linked_employee_id' => ['nullable', 'integer', 'exists:employees,id'],

            'evidence'                      => ['nullable', 'array', 'max:10'],
            'evidence.*'                    => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,docx,xlsx,txt,mp3,mp4'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => 'Please provide enough detail (at least 30 characters) for an investigator to act on.',
            'submitter_contact.required_if' => 'If you are submitting non-anonymously, please provide a contact (email or phone).',
        ];
    }
}
