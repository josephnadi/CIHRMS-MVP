<?php

namespace App\Http\Requests\IncidentReport;

use Illuminate\Foundation\Http\FormRequest;

class CloseIncidentReportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'resolution_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
