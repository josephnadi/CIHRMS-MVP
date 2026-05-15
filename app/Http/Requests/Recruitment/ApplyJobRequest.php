<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

class ApplyJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null
            || $this->user()->hasPermission('recruitment.apply');
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'cv'    => ['nullable', 'file', 'max:5120', 'mimes:pdf,doc,docx'],
        ];
    }
}
