<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

class ApproveLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('loans.approve');
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:approve'],
        ];
    }
}
