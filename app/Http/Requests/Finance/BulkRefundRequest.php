<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class BulkRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('gateway.refund') === true;
    }

    public function rules(): array
    {
        return [
            'intent_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'intent_ids.*' => ['integer', 'exists:payment_intents,id'],
            'reason'       => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
