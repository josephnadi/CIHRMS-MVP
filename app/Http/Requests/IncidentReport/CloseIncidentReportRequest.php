<?php

namespace App\Http\Requests\IncidentReport;

use Illuminate\Foundation\Http\FormRequest;

class CloseIncidentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');
        return $report !== null && $this->user()?->can('close', $report) === true;
    }

    public function rules(): array
    {
        return [
            'resolution_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
