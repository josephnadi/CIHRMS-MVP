<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.cert_manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'             => ['nullable', 'string', 'max:200'],
            'issuer'           => ['nullable', 'string', 'max:200'],
            'credential_id'    => ['nullable', 'string', 'max:120'],
            'issued_at'        => ['nullable', 'date'],
            'expires_at'       => ['nullable', 'date'],
            'verification_url' => ['nullable', 'url', 'max:500'],
        ];
    }
}
