<?php

namespace App\Http\Requests\Whistleblower;

use App\Enums\InvestigationActionType;
use App\Enums\WhistleblowerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LogActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('whistleblower.investigate');
    }

    public function rules(): array
    {
        return [
            'action_type' => ['required', Rule::enum(InvestigationActionType::class)],
            'notes'       => ['nullable', 'string', 'max:10000'],
            'meta'        => ['nullable', 'array'],
            // Optional: change status alongside the action
            'new_status'  => ['nullable', Rule::enum(WhistleblowerStatus::class)],
            'closure_summary' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
