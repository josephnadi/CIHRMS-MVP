<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class PostBankAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reconciliation.adjust') === true;
    }

    public function rules(): array
    {
        return [
            'gl_account_id' => ['required', 'integer', 'exists:gl_accounts,id'],
            'narration'     => ['required', 'string', 'max:500'],
        ];
    }
}
