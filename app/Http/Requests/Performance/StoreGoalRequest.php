<?php

namespace App\Http\Requests\Performance;

use App\Enums\GoalCadence;
use App\Enums\GoalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('performance.manage')
            || $this->user()->employee?->id === (int) $this->input('employee_id');
    }

    public function rules(): array
    {
        return [
            'employee_id'    => ['required', 'exists:employees,id'],
            'parent_goal_id' => ['nullable', 'exists:goals,id'],
            'cycle_id'       => ['nullable', 'exists:review_cycles,id'],
            'title'          => ['required', 'string', 'max:200'],
            'description'    => ['nullable', 'string', 'max:5000'],
            'cadence'        => ['required', Rule::enum(GoalCadence::class)],
            'target_value'   => ['nullable', 'numeric', 'min:0'],
            'current_value'  => ['nullable', 'numeric', 'min:0'],
            'unit'           => ['nullable', 'string', 'max:20'],
            'weight'         => ['nullable', 'integer', 'min:0', 'max:100'],
            'status'         => ['nullable', Rule::enum(GoalStatus::class)],
            'starts_at'      => ['nullable', 'date'],
            'due_at'         => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
