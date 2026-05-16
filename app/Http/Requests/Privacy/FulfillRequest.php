<?php

namespace App\Http\Requests\Privacy;

use Illuminate\Foundation\Http\FormRequest;

class FulfillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('privacy.fulfill');
    }

    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'min:20', 'max:5000'],
        ];
    }
}
