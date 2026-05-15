<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

class DisburseLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('loans.disburse');
    }

    public function rules(): array
    {
        return [
            'first_repayment_period' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
