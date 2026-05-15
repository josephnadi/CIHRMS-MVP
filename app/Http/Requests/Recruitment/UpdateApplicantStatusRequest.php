<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\ApplicantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicantStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recruitment.manage');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ApplicantStatus::class)],
        ];
    }
}
