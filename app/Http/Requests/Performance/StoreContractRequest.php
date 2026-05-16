<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('performance.manage');
    }

    public function rules(): array
    {
        return [
            'cycle_id'      => ['required', 'integer', 'exists:review_cycles,id'],
            'employee_id'   => ['required', 'integer', 'exists:employees,id'],
            'supervisor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'kpis'          => ['required', 'array', 'min:1', 'max:20'],
            'kpis.*.name'   => ['required', 'string', 'max:255'],
            'kpis.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'kpis.*.target' => ['required', 'numeric', 'min:0'],
            'kpis.*.unit'   => ['nullable', 'string', 'max:32'],
            'kpis.*.scorecard' => ['nullable', 'in:financial,customer,process,learning'],
        ];
    }
}
