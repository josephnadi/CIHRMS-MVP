<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class ApprovePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payroll.approve');
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:approve'],
        ];
    }
}
