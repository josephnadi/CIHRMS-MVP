<?php

declare(strict_types=1);

namespace App\Http\Requests\Benefits;

use Illuminate\Foundation\Http\FormRequest;

class StoreDependantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('benefits.enrol') ?? false;
    }

    public function rules(): array
    {
        return [
            'full_name'     => ['required', 'string', 'max:120'],
            'relationship'  => ['required', 'in:spouse,child,parent,other'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'national_id'   => ['nullable', 'string', 'max:32'],
            'gender'        => ['nullable', 'in:male,female,other'],
            'is_covered'    => ['nullable', 'boolean'],
        ];
    }
}
