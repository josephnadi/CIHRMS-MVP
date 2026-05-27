<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('gateway.create') === true;
    }

    public function rules(): array
    {
        return [
            'ar_invoice_id' => ['required', 'integer', 'exists:ar_invoices,id'],
            // M16: 9_999_999.99 GHS ceiling per gateway transaction.
            'amount'        => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'callback_url'  => ['nullable', 'url', 'max:500'],
            'narration'     => ['nullable', 'string', 'max:500'],
        ];
    }
}
