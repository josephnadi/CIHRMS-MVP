<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class ReturnAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();
        return ($u?->hasPermission('assets.manage') || $u?->hasPermission('assets.assign')) ?? false;
    }

    public function rules(): array
    {
        return [
            'condition_on_return' => ['required', 'in:good,fair,poor,damaged'],
            'notes'               => ['nullable', 'string', 'max:500'],
        ];
    }
}
