<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

class VerifyIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('identity.verify');
    }

    public function rules(): array
    {
        return [
            'employee_id'        => ['required', 'integer', 'exists:employees,id'],
            'ghana_card_number'  => ['required', 'string', 'regex:/^GHA-\d{9}-\d$/i'],
            'evidence'           => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'ghana_card_number.regex' => 'Ghana Card number must match format GHA-NNNNNNNNN-N.',
        ];
    }
}
