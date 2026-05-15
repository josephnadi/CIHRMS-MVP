<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ClockSelfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('attendance.clock_self')
            && $this->user()->employee !== null;
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', 'in:in,out'],
            'geo_lat'   => ['nullable', 'numeric', 'between:-90,90'],
            'geo_lng'   => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
