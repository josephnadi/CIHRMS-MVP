<?php

namespace App\Http\Requests\Establishment;

use App\Enums\FundingSource;
use App\Enums\PositionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('positions.manage');
    }

    public function rules(): array
    {
        return [
            'code'                  => ['required', 'string', 'max:64', 'unique:positions,code'],
            'title'                 => ['required', 'string', 'max:255'],
            'grade_id'              => ['nullable', 'integer', 'exists:grades,id'],
            'department_id'         => ['nullable', 'integer', 'exists:departments,id'],
            'reports_to_position_id'=> ['nullable', 'integer', 'exists:positions,id'],
            'cost_center'           => ['nullable', 'string', 'max:32'],
            'funding_source'        => ['required', Rule::enum(FundingSource::class)],
            'status'                => ['required', Rule::enum(PositionStatus::class)],
            'headcount_ceiling'     => ['required', 'integer', 'min:1', 'max:100'],
            'is_supervisory'        => ['boolean'],
            'job_description'       => ['nullable', 'string'],
        ];
    }
}
