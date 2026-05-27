<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\BillingCycle;
use App\Enums\MemberClass;
use App\Models\FeeProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreFeeProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeeProduct::class) === true;
    }

    public function rules(): array
    {
        $classValues = array_map(fn (MemberClass $c) => $c->value, MemberClass::cases());

        return [
            'code'                  => ['required', 'string', 'max:40', Rule::unique('fee_products', 'code')->whereNull('deleted_at')],
            'name'                  => ['required', 'string', 'max:200'],
            'description'           => ['nullable', 'string', 'max:2000'],
            // M16 ceiling pattern from PR #60.
            'amount'                => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'currency'              => ['sometimes', 'string', 'size:3'],
            'billing_cycle'         => ['required', new Enum(BillingCycle::class)],
            'applies_to_classes'    => ['nullable', 'array'],
            'applies_to_classes.*'  => ['string', Rule::in($classValues)],
            'gl_income_account_id'  => ['required', 'integer', 'exists:gl_accounts,id'],
            'is_active'             => ['sometimes', 'boolean'],
        ];
    }
}
