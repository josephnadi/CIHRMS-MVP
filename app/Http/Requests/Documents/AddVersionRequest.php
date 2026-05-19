<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class AddVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'file'  => ['required', 'file', 'max:25600', 'mimes:pdf,docx,doc,png,jpg,jpeg'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
