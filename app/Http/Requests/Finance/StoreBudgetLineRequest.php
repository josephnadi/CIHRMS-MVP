<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('finance.budget.manage') === true;
    }

    public function rules(): array
    {
        return [
            'year'          => ['required', 'integer', 'min:2000', 'max:2100'],
            'gl_account_id' => ['required', 'integer', 'exists:gl_accounts,id'],
            'annual_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
