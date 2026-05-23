<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\CustomerStatus;
use App\Enums\GlAccountType;
use App\Models\GlAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('customers.manage') === true;
    }

    public function rules(): array
    {
        $glTypeCheck = function (GlAccountType $expected) {
            return function (string $attribute, mixed $value, Closure $fail) use ($expected) {
                if ($value === null) return;
                $gl = GlAccount::find($value);
                if ($gl && $gl->type !== $expected) {
                    $fail("The {$attribute} must reference a GL account of type {$expected->value}.");
                }
            };
        };

        return [
            'code'    => ['required', 'string', 'max:30', 'unique:customers,code'],
            'name'    => ['required', 'string', 'max:200'],
            'tax_id'  => ['nullable', 'string', 'max:50'],
            'status'  => ['sometimes', Rule::enum(CustomerStatus::class)],
            'email'   => ['nullable', 'email', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes'   => ['nullable', 'string', 'max:2000'],
            'default_income_gl_account_id' => ['nullable', 'integer', 'exists:gl_accounts,id', $glTypeCheck(GlAccountType::Income)],
            'default_ar_gl_account_id'     => ['nullable', 'integer', 'exists:gl_accounts,id', $glTypeCheck(GlAccountType::Asset)],
            'default_bank_account_id'      => ['nullable', 'integer', 'exists:org_bank_accounts,id'],
        ];
    }
}
