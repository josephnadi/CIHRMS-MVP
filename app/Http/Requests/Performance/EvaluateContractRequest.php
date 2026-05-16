<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

class EvaluateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('performance.manage');
    }

    public function rules(): array
    {
        return [
            'actuals'        => ['required', 'array'],
            'actuals.*'      => ['numeric', 'min:0'],
            'end_year_note'  => ['nullable', 'string', 'max:5000'],
        ];
    }
}
