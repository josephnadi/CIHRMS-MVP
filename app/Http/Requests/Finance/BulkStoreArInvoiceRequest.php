<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create one draft AR invoice per selected customer, all sharing the same lines,
 * dates and notes (e.g. billing an annual fee to many customers at once).
 */
class BulkStoreArInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('ar_invoices.create') === true;
    }

    public function rules(): array
    {
        return [
            'customer_ids'          => ['required', 'array', 'min:1'],
            'customer_ids.*'        => ['integer', 'distinct', 'exists:customers,id'],
            'invoice_date'          => ['required', 'date'],
            'due_date'              => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency'              => ['sometimes', 'string', 'size:3'],
            'notes'                 => ['nullable', 'string', 'max:2000'],
            'lines'                 => ['required', 'array', 'min:1'],
            'lines.*.description'   => ['required', 'string', 'max:500'],
            'lines.*.quantity'      => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price'    => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate'      => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'lines.*.gl_account_id' => ['required', 'integer', 'exists:gl_accounts,id'],
        ];
    }
}
