<?php

declare(strict_types=1);

namespace App\Http\Requests\Benefits;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDependantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('benefits.enrol') ?? false;
    }

    public function rules(): array
    {
        return [
            'full_name'     => ['nullable', 'string', 'max:120'],
            'relationship'  => ['nullable', 'in:spouse,child,parent,other'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'national_id'   => ['nullable', 'string', 'max:32'],
            'gender'        => ['nullable', 'in:male,female,other'],
            'is_covered'    => ['nullable', 'boolean'],
        ];
    }
}
