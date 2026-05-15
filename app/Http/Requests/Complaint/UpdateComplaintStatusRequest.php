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
            'status' => ['required', Rule::enum(ComplaintStatus::class)],
        ];
    }
}
