<?php

namespace App\Http\Requests\Leave;

use App\Enums\LeaveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave.approve');
    }

    public function rules(): array
    {
        return [
            'status'  => ['required', Rule::enum(LeaveStatus::class)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
