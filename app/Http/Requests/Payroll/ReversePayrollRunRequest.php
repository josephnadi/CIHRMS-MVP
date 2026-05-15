<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class ReversePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('payroll.reverse');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
