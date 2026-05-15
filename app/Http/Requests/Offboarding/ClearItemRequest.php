<?php

namespace App\Http\Requests\Offboarding;

use Illuminate\Foundation\Http\FormRequest;

class ClearItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('offboarding.clear')
            || $this->user()->hasPermission('offboarding.manage');
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:clear,waive'],
            'notes'  => ['nullable', 'string', 'max:1000', 'required_if:action,waive'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required_if' => 'A reason is required when waiving a clearance item.',
        ];
    }
}
