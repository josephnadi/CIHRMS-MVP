<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class LinkReconciliationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reconciliation.match') === true;
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', 'string', 'in:ap_payment,ar_receipt'],
            'target_id'   => ['required', 'integer', 'min:1'],
        ];
    }
}
