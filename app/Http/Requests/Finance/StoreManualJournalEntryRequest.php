<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('journal.post_manual') === true;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'narration'  => ['nullable', 'string', 'max:500'],
            'lines'                         => ['required', 'array', 'min:2'],
            'lines.*.gl_account_id'         => ['required', 'integer', 'exists:gl_accounts,id'],
            'lines.*.debit_amount'          => ['required', 'numeric', 'min:0'],
            'lines.*.credit_amount'         => ['required', 'numeric', 'min:0'],
            'lines.*.narration'             => ['nullable', 'string', 'max:500'],
        ];
    }
}
