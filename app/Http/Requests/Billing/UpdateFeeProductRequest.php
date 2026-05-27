<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\BillingCycle;
use App\Enums\MemberClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateFeeProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('fee_product');
        return $product !== null && $this->user()?->can('update', $product) === true;
    }

    public function rules(): array
    {
        $product     = $this->route('fee_product');
        $id          = is_object($product) ? $product->id : $product;
        $classValues = array_map(fn (MemberClass $c) => $c->value, MemberClass::cases());

        return [
            'code'                  => ['sometimes', 'string', 'max:40', Rule::unique('fee_products', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'                  => ['sometimes', 'string', 'max:200'],
            'description'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'amount'                => ['sometimes', 'numeric', 'min:0.01', 'max:9999999.99'],
            'currency'              => ['sometimes', 'string', 'size:3'],
            'billing_cycle'         => ['sometimes', new Enum(BillingCycle::class)],
            'applies_to_classes'    => ['sometimes', 'nullable', 'array'],
            'applies_to_classes.*'  => ['string', Rule::in($classValues)],
            'gl_income_account_id'  => ['sometimes', 'integer', 'exists:gl_accounts,id'],
            'is_active'             => ['sometimes', 'boolean'],
        ];
    }
}
