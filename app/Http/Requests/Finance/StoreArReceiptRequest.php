<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreArReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('ar_invoices.receive') === true;
    }

    public function rules(): array
    {
        return [
            'customer_id'         => ['required', 'integer', 'exists:customers,id'],
            'receipt_date'        => ['required', 'date'],
            'amount'              => ['required', 'numeric', 'min:0.01'],
            'currency'            => ['sometimes', 'string', 'size:3'],
            'org_bank_account_id' => ['required', 'integer', 'exists:org_bank_accounts,id'],
            'external_ref'        => ['nullable', 'string', 'max:100'],
            'narration'           => ['nullable', 'string', 'max:500'],
            'allocations'                       => ['required', 'array', 'min:1'],
            'allocations.*.ar_invoice_id'       => ['required', 'integer', 'exists:ar_invoices,id'],
            'allocations.*.allocated_amount'    => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
