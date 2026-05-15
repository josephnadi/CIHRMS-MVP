<?php

namespace App\Http\Requests\Performance;

use App\Enums\GoalCadence;
use App\Enums\GoalStatus;
use App\Models\Goal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $goal = $this->route('goal');
        if (! $goal instanceof Goal) return false;

        // HR managers can edit anyone's goal; the employee can edit their own.
        return $this->user()->hasPermission('performance.manage')
            || $this->user()->employee?->id === $goal->employee_id;
    }

    public function rules(): array
    {
        return [
            'title'         => ['sometimes', 'string', 'max:200'],
            'description'   => ['nullable', 'string', 'max:5000'],
            'cadence'       => ['sometimes', Rule::enum(GoalCadence::class)],
            'target_value'  => ['nullable', 'numeric', 'min:0'],
            'current_value' => ['nullable', 'numeric', 'min:0'],
            'unit'          => ['nullable', 'string', 'max:20'],
            'weight'        => ['nullable', 'integer', 'min:0', 'max:100'],
            'status'        => ['sometimes', Rule::enum(GoalStatus::class)],
            'starts_at'     => ['nullable', 'date'],
            'due_at'        => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
