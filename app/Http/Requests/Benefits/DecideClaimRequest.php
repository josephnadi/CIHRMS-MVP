<?php

declare(strict_types=1);

namespace App\Http\Requests\Benefits;

use Illuminate\Foundation\Http\FormRequest;

class DecideClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('benefits.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:reviewing,approved,rejected,paid'],
            'notes'  => ['nullable', 'string', 'max:1000', 'required_if:status,rejected'],
        ];
    }
}
