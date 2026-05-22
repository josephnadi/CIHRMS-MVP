<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\GlAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGlAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounts.manage') === true;
    }

    public function rules(): array
    {
        // NOTE: Rule::unique('gl_accounts', 'code')->ignore($id) still queries soft-deleted rows
        // for all other records. GL codes are permanently retired once used — never re-issued —
        // to preserve audit trail continuity. If a code must be reused, hard-delete the archived row.
        $id = $this->route('account')?->id;

        return [
            'code'        => ['required', 'string', 'max:20', Rule::unique('gl_accounts', 'code')->ignore($id)],
            'name'        => ['required', 'string', 'max:150'],
            'type'        => ['required', Rule::enum(GlAccountType::class)],
            'parent_id'   => [
                'nullable',
                'integer',
                'exists:gl_accounts,id',
                Rule::notIn([$id]),
            ],
            'is_active'   => ['sometimes', 'boolean'],
            'currency'    => ['sometimes', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
