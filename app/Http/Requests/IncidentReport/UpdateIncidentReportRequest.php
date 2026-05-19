<?php

namespace App\Http\Requests\IncidentReport;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIncidentReportRequest extends FormRequest
{
    public function authorize(): bool { return true; /* policy handles auth */ }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:6', 'max:180'],
            'body'  => ['required', 'string', 'min:20', 'max:10000'],
        ];
    }
}
