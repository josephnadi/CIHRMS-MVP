<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.cert_manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id'      => ['required', 'integer', 'exists:employees,id'],
            'name'             => ['required', 'string', 'max:200'],
            'issuer'           => ['nullable', 'string', 'max:200'],
            'credential_id'    => ['nullable', 'string', 'max:120'],
            'issued_at'        => ['nullable', 'date'],
            'expires_at'       => ['nullable', 'date', 'after_or_equal:issued_at'],
            'verification_url' => ['nullable', 'url', 'max:500'],
        ];
    }
}
