<?php

namespace App\Http\Requests\Whistleblower;

use App\Enums\WhistleblowerSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TriageReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('whistleblower.investigate');
    }

    public function rules(): array
    {
        return [
            'severity'             => ['required', Rule::enum(WhistleblowerSeverity::class)],
            'assigned_investigator_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes'                => ['nullable', 'string', 'max:2000'],
        ];
    }
}
