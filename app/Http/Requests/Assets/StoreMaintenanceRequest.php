<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('assets.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'type'    => ['required', 'in:repair,service,upgrade'],
            'cost'    => ['nullable', 'numeric', 'min:0'],
            'vendor'  => ['nullable', 'string', 'max:120'],
            'notes'   => ['nullable', 'string', 'max:1000'],
        ];
    }
}
