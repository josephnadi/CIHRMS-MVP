<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\ArInvoice;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreArInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('ar_invoices.create') === true;
    }

    public function rules(): array
    {
        // F2 lesson forward-applied: enforce (customer_id, customer_invoice_no)
        // uniqueness at the request layer when both are present, surfacing a
        // clean field error instead of a DB constraint violation.
        $customerInvoiceNoUnique = function (string $attribute, mixed $value, Closure $fail) {
            if (! $value || ! $this->input('customer_id')) return;
            $exists = ArInvoice::query()
                ->where('customer_id', $this->input('customer_id'))
                ->where('customer_invoice_no', $value)
                ->exists();
            if ($exists) {
                $fail("This customer_invoice_no has already been recorded for this customer.");
            }
        };

        return [
            'customer_id'         => ['required', 'integer', 'exists:customers,id'],
            'customer_invoice_no' => ['nullable', 'string', 'max:100', $customerInvoiceNoUnique],
            'invoice_date'        => ['required', 'date'],
            'due_date'            => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency'            => ['sometimes', 'string', 'size:3'],
            'notes'               => ['nullable', 'string', 'max:2000'],
            'lines'                       => ['required', 'array', 'min:1'],
            'lines.*.description'         => ['required', 'string', 'max:500'],
            'lines.*.quantity'            => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price'          => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate'            => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'lines.*.gl_account_id'       => ['required', 'integer', 'exists:gl_accounts,id'],
        ];
    }
}
