<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('assets.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'asset_tag'           => ['required', 'string', 'max:40', 'unique:assets,asset_tag'],
            'name'                => ['required', 'string', 'max:120'],
            'category'            => ['required', 'in:laptop,monitor,phone,vehicle,furniture,other'],
            'serial_number'       => ['nullable', 'string', 'max:80'],
            'brand'               => ['nullable', 'string', 'max:80'],
            'model'               => ['nullable', 'string', 'max:80'],
            'purchase_date'       => ['nullable', 'date'],
            'purchase_cost'       => ['nullable', 'numeric', 'min:0'],
            'currency'            => ['nullable', 'string', 'size:3'],
            'supplier'            => ['nullable', 'string', 'max:120'],
            'warranty_expires_at' => ['nullable', 'date'],
            'location'            => ['nullable', 'string', 'max:120'],
            'notes'               => ['nullable', 'string'],
        ];
    }
}
