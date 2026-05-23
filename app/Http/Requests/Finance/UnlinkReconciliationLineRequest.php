<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class UnlinkReconciliationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reconciliation.match') === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
