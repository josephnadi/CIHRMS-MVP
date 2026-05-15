<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class AssignShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.shift_manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id'    => ['required', 'integer', 'exists:employees,id'],
            'shift_id'       => ['required', 'integer', 'exists:shifts,id'],
            'effective_from' => ['required', 'date'],
            'effective_to'   => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
