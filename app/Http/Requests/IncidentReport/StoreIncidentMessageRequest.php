<?php

namespace App\Http\Requests\IncidentReport;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentMessageRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'body'          => ['required', 'string', 'min:1', 'max:10000'],
            'attachments'   => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'mimes:pdf,png,jpg,jpeg,doc,docx', 'max:10240'],
        ];
    }
}
