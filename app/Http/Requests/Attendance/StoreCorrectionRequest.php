<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.correct') ?? false;
    }

    public function rules(): array
    {
        return [
            'requested_event_at'   => ['required', 'date'],
            'requested_direction'  => ['required', 'in:in,out'],
            'reason'               => ['required', 'string', 'min:8', 'max:500'],
            'attendance_record_id' => ['nullable', 'integer', 'exists:attendance_records,id'],
        ];
    }
}
