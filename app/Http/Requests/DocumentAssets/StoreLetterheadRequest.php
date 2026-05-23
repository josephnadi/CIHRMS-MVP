<?php

declare(strict_types=1);

namespace App\Http\Requests\DocumentAssets;

use App\Enums\AssetOwnerScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreLetterheadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\LetterheadTemplate::class) === true;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:120'],
            'owner_scope'      => ['required', new Enum(AssetOwnerScope::class)],
            'owner_id'         => [
                Rule::requiredIf(fn () => $this->input('owner_scope') === AssetOwnerScope::Department->value),
                'nullable', 'integer',
            ],
            'file'             => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:3072'], // 3 MB
            'header_height_mm' => ['nullable', 'integer', 'between:20,80'],
        ];
    }
}
