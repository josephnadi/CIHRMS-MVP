<?php

declare(strict_types=1);

namespace App\Http\Requests\DocumentAssets;

use App\Enums\AssetOwnerScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreWatermarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\WatermarkTemplate::class) === true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:120'],
            'owner_scope' => ['required', new Enum(AssetOwnerScope::class)],
            'owner_id'    => [
                Rule::requiredIf(fn () => $this->input('owner_scope') === AssetOwnerScope::Department->value),
                'nullable', 'integer',
            ],
            'type'        => ['required', 'in:text,image'],
            'text'        => [
                Rule::requiredIf(fn () => $this->input('type') === 'text'),
                'nullable', 'string', 'max:120',
            ],
            'color'       => ['nullable', 'string', 'max:9'],
            'file'        => [
                Rule::requiredIf(fn () => $this->input('type') === 'image'),
                'nullable', 'file', 'mimes:png', 'max:1024',
            ],
            'opacity'     => ['nullable', 'numeric', 'between:0.05,1'],
            'angle_deg'   => ['nullable', 'integer', 'between:-90,90'],
        ];
    }
}
