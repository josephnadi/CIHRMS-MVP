<?php

namespace App\Http\Requests\Performance;

use App\Enums\ReviewCycleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('performance.manage');
    }

    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:120'],
            'cadence'            => ['required', Rule::in(['annual', 'half_year', 'quarterly', 'probation'])],
            'starts_at'          => ['required', 'date'],
            'ends_at'            => ['required', 'date', 'after:starts_at'],
            'self_review_due'    => ['nullable', 'date', 'before_or_equal:ends_at'],
            'peer_review_due'    => ['nullable', 'date', 'before_or_equal:ends_at'],
            'manager_review_due' => ['nullable', 'date', 'before_or_equal:ends_at'],
            'status'             => ['nullable', Rule::enum(ReviewCycleStatus::class)],
        ];
    }
}
