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
            // M16: cap at ~10M GHS (well above any realistic single line);
            // protects downstream aggregations from float overflow / typo.
            'lines.*.debit_amount'          => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'lines.*.credit_amount'         => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'lines.*.narration'             => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * L8: a line where BOTH debit and credit are zero contributes nothing to
     * the JE and is almost always a UI bug or noise. Reject as a domain
     * constraint so the JE table never accumulates dead rows.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            foreach ((array) $this->input('lines', []) as $i => $line) {
                $dr = (float) ($line['debit_amount']  ?? 0);
                $cr = (float) ($line['credit_amount'] ?? 0);
                if ($dr === 0.0 && $cr === 0.0) {
                    $v->errors()->add("lines.{$i}.debit_amount", 'Line must have a non-zero debit or credit.');
                }
            }
        });
    }
}
