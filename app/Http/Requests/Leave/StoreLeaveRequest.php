<?php

namespace App\Http\Requests\Leave;

use App\Enums\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave.request');
    }

    /**
     * Self-service leave: default employee_id to the authenticated user's own
     * employee record (the apply form doesn't send it). An explicit employee_id
     * is preserved for any future HR "file on behalf" flow.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('employee_id')) {
            $this->merge(['employee_id' => $this->user()?->employee?->id]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'type'        => ['required', Rule::enum(LeaveType::class)],
            'start_date'  => ['required', 'date', 'after_or_equal:today'],
            'end_date'    => ['required', 'date', 'after_or_equal:start_date'],
            'reason'      => ['nullable', 'string', 'max:2000'],
        ];
    }
}
