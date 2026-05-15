<?php

declare(strict_types=1);

namespace App\Http\Requests\Benefits;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('benefits.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'                              => ['required', 'string', 'max:120'],
            'code'                              => ['required', 'string', 'max:40', 'unique:benefit_plans,code'],
            'type'                              => ['required', 'in:health_insurance,provident_fund,life_insurance,dental,vision,wellness,other'],
            'provider'                          => ['nullable', 'string', 'max:120'],
            'description'                       => ['nullable', 'string'],
            'monthly_cost'                      => ['required', 'numeric', 'min:0'],
            'employee_contribution_percentage'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active'                         => ['nullable', 'boolean'],
            'effective_from'                    => ['required', 'date'],
            'effective_to'                      => ['nullable', 'date', 'after_or_equal:effective_from'],
            'max_dependants'                    => ['nullable', 'integer', 'min:0', 'max:50'],
            'cover_details'                     => ['nullable', 'array'],
        ];
    }
}
