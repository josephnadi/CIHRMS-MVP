<?php

namespace App\Http\Requests\IncidentReport;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->employee !== null;
    }

    public function rules(): array
    {
        return [
            'category'        => ['required', 'in:grievance,improvement,safety,other'],
            'title'           => ['required', 'string', 'min:6', 'max:180'],
            'body'            => ['required', 'string', 'min:20', 'max:10000'],
            'attachments'     => ['nullable', 'array', 'max:3'],
            'attachments.*'   => ['file', 'mimes:pdf,png,jpg,jpeg,doc,docx', 'max:10240'],
        ];
    }
}
