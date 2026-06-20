<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\GlAccountType;
use App\Enums\OrgBankAccountPurpose;
use App\Models\GlAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrgBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('bank_accounts.manage') === true;
    }

    public function rules(): array
    {
        return [
            'gl_account_id'   => [
                'required',
                'integer',
                'exists:gl_accounts,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    $gl = GlAccount::find($value);
                    if ($gl && $gl->type !== GlAccountType::Asset) {
                        $fail('The linked GL account must be of type asset.');
                    }
                },
            ],
            'bank_name'       => ['required', 'string', 'max:150'],
            'branch'          => ['nullable', 'string', 'max:150'],
            'account_name'    => ['required', 'string', 'max:200'],
            'account_number'  => [
                'required', 'string', 'max:64',
                Rule::unique('org_bank_accounts')->where(fn ($q) => $q->where('bank_name', $this->input('bank_name'))->whereNull('deleted_at')),
            ],
            'sort_code'       => ['nullable', 'string', 'max:20'],
            'swift'           => ['nullable', 'string', 'max:20'],
            'currency'        => ['sometimes', 'string', 'size:3'],
            'purpose'         => ['required', Rule::enum(OrgBankAccountPurpose::class)],
            'opening_balance' => ['sometimes', 'numeric', 'min:0'],
            'is_active'       => ['sometimes', 'boolean'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ];
    }
}
