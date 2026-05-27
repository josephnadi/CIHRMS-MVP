<?php

declare(strict_types=1);

namespace App\Http\Requests\DocumentAssets;

use App\Enums\AssetOwnerScope;
use App\Rules\RealImageContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreStampAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\StampAsset::class) === true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:120'],
            'owner_scope'   => ['required', new Enum(AssetOwnerScope::class)],
            'owner_id'      => [
                Rule::requiredIf(fn () => $this->input('owner_scope') === AssetOwnerScope::Department->value),
                'nullable', 'integer',
            ],
            'file'          => ['required', 'file', 'mimes:png', 'max:1024', new RealImageContent(['png'])],
            'default_w_pct' => ['nullable', 'numeric', 'between:4,80'],
            'default_h_pct' => ['nullable', 'numeric', 'between:4,80'],
        ];
    }
}
