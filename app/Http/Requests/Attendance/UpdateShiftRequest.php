<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.shift_manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code'                 => ['required', 'string', 'max:20', Rule::unique('shifts', 'code')->ignore($this->route('shift')?->id)],
            'name'                 => ['required', 'string', 'max:80'],
            'start_time'           => ['required', 'date_format:H:i'],
            'end_time'             => ['required', 'date_format:H:i'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'full_day_hours'       => ['nullable', 'numeric', 'min:1', 'max:24'],
            'half_day_hours'       => ['nullable', 'numeric', 'min:0.5', 'max:12'],
            'working_days'         => ['nullable', 'array'],
            'working_days.*'       => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'department_id'        => ['nullable', 'integer', 'exists:departments,id'],
            'is_active'            => ['nullable', 'boolean'],
        ];
    }
}
