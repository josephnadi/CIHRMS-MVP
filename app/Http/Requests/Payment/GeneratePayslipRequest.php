<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePayslipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payroll.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id'                  => ['required', 'exists:employees,id'],
            'period'                       => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'basic'                        => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'allowances'                   => ['array'],
            'allowances.*.label'           => ['required_with:allowances.*.amount', 'string', 'max:120'],
            'allowances.*.amount'          => ['required_with:allowances.*.label', 'numeric', 'min:0'],
            'voluntary_deductions'         => ['array'],
            'voluntary_deductions.*.label' => ['required_with:voluntary_deductions.*.amount', 'string', 'max:120'],
            'voluntary_deductions.*.amount'=> ['required_with:voluntary_deductions.*.label', 'numeric', 'min:0'],
            'tier3_employee'               => ['nullable', 'numeric', 'min:0'],
            'mark_paid'                    => ['boolean'],
        ];
    }
}
