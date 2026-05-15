<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class AssignAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();
        return ($u?->hasPermission('assets.manage') || $u?->hasPermission('assets.assign')) ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'due_back_at' => ['nullable', 'date', 'after:today'],
            'notes'       => ['nullable', 'string', 'max:500'],
        ];
    }
}
