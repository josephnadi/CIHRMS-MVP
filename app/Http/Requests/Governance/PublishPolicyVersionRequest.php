<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class PublishPolicyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
        ];
    }
}
