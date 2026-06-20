<?php

declare(strict_types=1);

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('learning.compliance.manage') === true;
    }

    public function rules(): array
    {
        return [
            'course_id'    => ['required', 'integer', 'exists:courses,id'],
            'name'         => ['required', 'string', 'max:160'],
            'target_type'  => ['required', 'in:all_staff,role,department'],
            'target_value' => ['nullable', 'string', 'max:64'],
            'due_in_days'  => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
