<?php

namespace App\Http\Requests\Performance;

use App\Enums\PipStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClosePipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('performance.pip_manage');
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(PipStatus::class)],
            'summary' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }
}
