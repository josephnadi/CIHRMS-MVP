<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ManualAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.manage');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'event_at'    => ['required', 'date'],
            'direction'   => ['required', 'in:in,out'],
            'reason'      => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
