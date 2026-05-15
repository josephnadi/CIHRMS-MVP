<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class StorePolicyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'body'      => ['required', 'string', 'min:20'],
            'changelog' => ['nullable', 'string', 'max:500'],
        ];
    }
}
