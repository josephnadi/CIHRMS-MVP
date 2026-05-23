<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreApPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('ap_invoices.pay') === true;
    }

    public function rules(): array
    {
        return [
            'vendor_id'           => ['required', 'integer', 'exists:vendors,id'],
            'payment_date'        => ['required', 'date'],
            'amount'              => ['required', 'numeric', 'min:0.01'],
            'currency'            => ['sometimes', 'string', 'size:3'],
            'org_bank_account_id' => ['required', 'integer', 'exists:org_bank_accounts,id'],
            'narration'           => ['nullable', 'string', 'max:500'],
            'allocations'                          => ['required', 'array', 'min:1'],
            'allocations.*.vendor_invoice_id'      => ['required', 'integer', 'exists:vendor_invoices,id'],
            'allocations.*.allocated_amount'       => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
