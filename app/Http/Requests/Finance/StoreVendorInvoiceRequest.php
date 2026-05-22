<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('ap_invoices.create') === true;
    }

    public function rules(): array
    {
        return [
            'vendor_id'         => ['required', 'integer', 'exists:vendors,id'],
            'vendor_invoice_no' => ['nullable', 'string', 'max:100'],
            'invoice_date'      => ['required', 'date'],
            'due_date'          => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency'          => ['sometimes', 'string', 'size:3'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'lines'                       => ['required', 'array', 'min:1'],
            'lines.*.description'         => ['required', 'string', 'max:500'],
            'lines.*.quantity'            => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price'          => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate'            => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'lines.*.gl_account_id'       => ['required', 'integer', 'exists:gl_accounts,id'],
        ];
    }
}
