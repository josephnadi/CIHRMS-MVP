<?php

namespace App\Http\Requests\Complaint;

use App\Enums\ComplaintStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComplaintStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('complaints.manage');
    }

    public function rules(): array
    {
        return [
            'status'      => ['sometimes', Rule::enum(ComplaintStatus::class)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (! $this->hasAny(['status', 'assigned_to'])) {
                $v->errors()->add('status', 'Provide at least a status change or a new assignee.');
            }
        });
    }
}
