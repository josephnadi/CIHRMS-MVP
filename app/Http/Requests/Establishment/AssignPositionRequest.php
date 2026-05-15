<?php

namespace App\Http\Requests\Establishment;

use Illuminate\Foundation\Http\FormRequest;

class AssignPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('positions.manage');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'is_acting'   => ['boolean'],
            'reason'      => ['nullable', 'string', 'max:255'],
        ];
    }
}
