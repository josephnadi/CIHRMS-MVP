<?php

namespace App\Http\Requests\Whistleblower;

use Illuminate\Foundation\Http\FormRequest;

class PostMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('whistleblower.investigate');
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ];
    }
}
