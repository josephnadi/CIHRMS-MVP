<?php

namespace App\Http\Requests\Offboarding;

use App\Enums\ExitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiateOffboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('offboarding.initiate');
    }

    public function rules(): array
    {
        return [
            'employee_id'        => ['required', 'integer', 'exists:employees,id'],
            'exit_type'          => ['required', Rule::enum(ExitType::class)],
            'notice_received_on' => ['required', 'date'],
            'last_working_day'   => ['required', 'date', 'after_or_equal:notice_received_on'],
            'reason'             => ['nullable', 'string', 'max:2000'],
        ];
    }
}
