<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title'        => ['required', 'string', 'max:200'],
            'slug'         => ['nullable', 'string', 'max:200', \Illuminate\Validation\Rule::unique('policies', 'slug')->ignore($this->route('policy')?->id)],
            'category'     => ['required', 'in:hr,finance,it,compliance,safety,conduct,other'],
            'summary'      => ['nullable', 'string', 'max:1000'],
            'is_active'    => ['nullable', 'boolean'],
            'initial_body' => ['nullable', 'string'],
        ];
    }
}
