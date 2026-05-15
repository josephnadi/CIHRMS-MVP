<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title'        => ['required', 'string', 'max:200'],
            'slug'         => ['nullable', 'string', 'max:200', 'unique:policies,slug'],
            'category'     => ['required', 'in:hr,finance,it,compliance,safety,conduct,other'],
            'summary'      => ['nullable', 'string', 'max:1000'],
            'is_active'    => ['nullable', 'boolean'],
            'initial_body' => ['nullable', 'string'],
        ];
    }
}
