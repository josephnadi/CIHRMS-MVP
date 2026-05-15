<?php

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

class ApplyLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('loans.apply')
            && $this->user()->employee !== null;
    }

    public function rules(): array
    {
        return [
            'product_id'   => ['required', 'integer', 'exists:loan_products,id'],
            'principal'    => ['required', 'numeric', 'min:1'],
            'term_months'  => ['required', 'integer', 'min:1', 'max:360'],
            'purpose'      => ['nullable', 'string', 'max:1000'],
            // Optional HR-side override: apply on behalf of another employee
            'employee_id'  => ['nullable', 'integer', 'exists:employees,id'],
        ];
    }
}
