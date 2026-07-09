<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class VetIncomingInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('incoming_invoices.vet') === true;
    }

    public function rules(): array
    {
        return ['notes' => ['nullable', 'string', 'max:2000']];
    }
}
