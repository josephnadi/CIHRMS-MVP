<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

class ApplyLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('loans.apply')
            || $this->user()->hasPermission('loans.manage');
    }

    public function rules(): array
    {
        return [
            'employee_id'  => ['required', 'integer', 'exists:employees,id'],
            'product_id'   => ['required', 'integer', 'exists:loan_products,id'],
            'principal'    => ['required', 'numeric', 'min:1', 'max:99999999.99'],
            'term_months'  => ['required', 'integer', 'min:1', 'max:360'],
            'purpose'      => ['nullable', 'string', 'max:1000'],
        ];
    }
}
