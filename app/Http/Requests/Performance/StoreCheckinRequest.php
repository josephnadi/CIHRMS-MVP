<?php

namespace App\Http\Requests\Performance;

use App\Models\Goal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckinRequest extends FormRequest
{
    public function authorize(): bool
    {
        $goal = $this->route('goal');
        if (! $goal instanceof Goal) return false;

        return $this->user()->hasPermission('performance.manage')
            || $this->user()->employee?->id === $goal->employee_id;
    }

    public function rules(): array
    {
        return [
            'progress_pct'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'current_value' => ['nullable', 'numeric', 'min:0'],
            'narrative'     => ['nullable', 'string', 'max:5000'],
            'mood'          => ['nullable', Rule::in(['green', 'amber', 'red'])],
        ];
    }
}
