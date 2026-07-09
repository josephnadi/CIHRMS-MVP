<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncomingInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incoming_invoices.submit') === true;
    }

    public function rules(): array
    {
        return [
            'vendor_name'       => ['required', 'string', 'max:200'],
            'vendor_invoice_no' => ['nullable', 'string', 'max:100'],
            'invoice_date'      => ['required', 'date'],
            'currency'          => ['sometimes', 'string', 'size:3'],
            'amount'            => ['required', 'numeric', 'min:0'],
            'description'       => ['nullable', 'string', 'max:2000'],
            'attachments'       => ['sometimes', 'array', 'max:10'],
            'attachments.*'     => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
