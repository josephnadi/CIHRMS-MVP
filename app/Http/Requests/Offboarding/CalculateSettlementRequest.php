<?php

namespace App\Http\Requests\Offboarding;

use Illuminate\Foundation\Http\FormRequest;

class CalculateSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('offboarding.settle');
    }

    public function rules(): array
    {
        return [
            'gratuity_months_per_year'  => ['nullable', 'numeric', 'min:0', 'max:6'],
            'severance_months_per_year' => ['nullable', 'numeric', 'min:0', 'max:6'],
            'working_days_per_month'    => ['nullable', 'numeric', 'min:15', 'max:31'],
            'ex_gratia'                 => ['nullable', 'numeric', 'min:0'],
            'prorated_13th_month'       => ['nullable', 'numeric', 'min:0'],
            'other_deductions'          => ['nullable', 'numeric', 'min:0'],
            'garnishments'              => ['nullable', 'numeric', 'min:0'],
            'pay_paye'                  => ['nullable', 'boolean'],
        ];
    }
}
