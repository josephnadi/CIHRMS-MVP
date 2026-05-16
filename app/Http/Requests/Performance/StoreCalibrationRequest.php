<?php

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

class StoreCalibrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('performance.calibrate');
    }

    public function rules(): array
    {
        return [
            'cycle_id'             => ['required', 'integer', 'exists:review_cycles,id'],
            'department_id'        => ['nullable', 'integer', 'exists:departments,id'],
            'target_distribution'  => ['nullable', 'array'],
        ];
    }
}
