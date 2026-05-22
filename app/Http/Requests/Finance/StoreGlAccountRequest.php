<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\GlAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGlAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounts.manage') === true;
    }

    public function rules(): array
    {
        // NOTE: 'unique:gl_accounts,code' intentionally queries soft-deleted rows too.
        // GL codes are permanently retired once used — never re-issued — to preserve
        // audit trail continuity. If a code must be reused, hard-delete the archived row.
        return [
            'code'        => ['required', 'string', 'max:20', 'unique:gl_accounts,code'],
            'name'        => ['required', 'string', 'max:150'],
            'type'        => ['required', Rule::enum(GlAccountType::class)],
            'parent_id'   => ['nullable', 'integer', 'exists:gl_accounts,id'],
            'is_active'   => ['sometimes', 'boolean'],
            'currency'    => ['sometimes', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
