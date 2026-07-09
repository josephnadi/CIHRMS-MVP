<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ReturnIncomingInvoiceRequest extends FormRequest
{
    // Both auditor (vet) and CEO (approve) may return; the controller picks the
    // transition by current status. Either permission authorizes the request.
    public function authorize(): bool
    {
        $u = $this->user();
        return $u !== null && ($u->hasPermission('incoming_invoices.vet') || $u->hasPermission('incoming_invoices.approve'));
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
