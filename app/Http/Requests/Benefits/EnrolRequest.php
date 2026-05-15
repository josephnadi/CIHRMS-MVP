<?php

declare(strict_types=1);

namespace App\Http\Requests\Benefits;

use Illuminate\Foundation\Http\FormRequest;

class EnrolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('benefits.enrol') ?? false;
    }

    public function rules(): array
    {
        return [
            'plan_id'        => ['required', 'integer', 'exists:benefit_plans,id'],
            'effective_from' => ['required', 'date'],
            'premium'        => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
