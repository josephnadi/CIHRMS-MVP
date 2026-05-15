<?php

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Employees can record their own certs; HR/LD can record for anyone.
        return $this->user()->hasPermission('learning.manage')
            || $this->user()->employee?->id === (int) $this->input('employee_id');
    }

    public function rules(): array
    {
        return [
            'employee_id'      => ['required', 'exists:employees,id'],
            'course_id'        => ['nullable', 'exists:courses,id'],
            'name'             => ['required', 'string', 'max:200'],
            'issuer'           => ['nullable', 'string', 'max:120'],
            'credential_id'    => ['nullable', 'string', 'max:120'],
            'issued_at'        => ['nullable', 'date'],
            'expires_at'       => ['nullable', 'date', 'after_or_equal:issued_at'],
            'document_path'    => ['nullable', 'string', 'max:255'],
            'verification_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
