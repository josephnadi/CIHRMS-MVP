<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ReviewCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'decision'       => ['required', 'in:approve,reject'],
            'decision_notes' => ['nullable', 'string', 'max:500', 'required_if:decision,reject'],
        ];
    }
}
