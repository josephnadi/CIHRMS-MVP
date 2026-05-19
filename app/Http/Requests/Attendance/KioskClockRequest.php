<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class KioskClockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_no' => ['required', 'string', 'max:50'],
            'name'        => ['required', 'string', 'max:120'],
            'direction'   => ['required', 'in:in,out'],
        ];
    }
}
