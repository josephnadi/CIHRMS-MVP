<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\FeeAssignment;
use Illuminate\Foundation\Http\FormRequest;

class StoreBillingRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FeeAssignment::class) === true;
    }

    public function rules(): array
    {
        return [
            'fee_product_id' => ['required', 'integer', 'exists:fee_products,id'],
            'period_label'   => ['required', 'string', 'max:20'],
            'invoice_date'   => ['sometimes', 'date'],
            'due_date'       => ['sometimes', 'nullable', 'date', 'after_or_equal:invoice_date'],
            'member_ids'     => ['sometimes', 'array'],
            'member_ids.*'   => ['integer', 'exists:members,id'],
        ];
    }
}
