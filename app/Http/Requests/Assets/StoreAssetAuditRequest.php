<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('asset_audits.manage') === true;
    }

    public function rules(): array
    {
        return [
            'scope_type'  => ['required', Rule::in(['all', 'category', 'location'])],
            'scope_value' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $this->input('scope_type') !== 'all')],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ];
    }
}
