<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

class OpenPipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('performance.pip_manage');
    }

    public function rules(): array
    {
        return [
            'employee_id'            => ['required', 'integer', 'exists:employees,id'],
            'triggered_by_review_id' => ['nullable', 'integer', 'exists:reviews,id'],
            'mentor_id'              => ['nullable', 'integer', 'exists:employees,id'],
            'duration_days'          => ['nullable', 'integer', 'min:30', 'max:180'],
            'target_metrics'         => ['required', 'array', 'min:1', 'max:10'],
            'target_metrics.*.metric'=> ['required', 'string', 'max:255'],
            'target_metrics.*.target'=> ['required'],
        ];
    }
}
