<?php

namespace App\Http\Requests\Performance;

use App\Enums\ReviewType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Employees can write self-reviews for themselves; managers/HR can write any.
        return $this->user()->hasPermission('performance.manage')
            || ($this->input('type') === ReviewType::Self->value
                && $this->user()->employee?->id === (int) $this->input('employee_id')
                && $this->user()->id              === (int) $this->input('reviewer_id'));
    }

    public function rules(): array
    {
        return [
            'cycle_id'           => ['required', 'exists:review_cycles,id'],
            'employee_id'        => ['required', 'exists:employees,id'],
            'reviewer_id'        => ['required', 'exists:users,id'],
            'type'               => ['required', Rule::enum(ReviewType::class)],
            'overall_rating'     => ['nullable', 'numeric', 'min:1', 'max:5'],
            'performance_rating' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'potential_rating'   => ['nullable', 'numeric', 'min:1', 'max:5'],
            'strengths'          => ['nullable', 'string', 'max:5000'],
            'opportunities'      => ['nullable', 'string', 'max:5000'],
            'comments'           => ['nullable', 'string', 'max:10000'],
        ];
    }
}
