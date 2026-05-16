<?php

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

class RejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('privacy.fulfill');
    }

    public function rules(): array
    {
        return [
            'statutory_basis' => ['required', 'string', 'min:5',  'max:500'],
            'summary'         => ['required', 'string', 'min:20', 'max:5000'],
        ];
    }
}
