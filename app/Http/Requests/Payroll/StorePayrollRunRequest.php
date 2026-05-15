<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class StorePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payroll.run');
    }

    public function rules(): array
    {
        return [
            'period_year'   => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month'  => ['required', 'integer', 'min:1', 'max:12'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'reason'        => ['nullable', 'string', 'max:1000'],
        ];
    }
}
