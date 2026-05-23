<?php

declare(strict_types=1);

namespace App\Http\Requests\Learning;

use App\Models\SkillCatalogItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('learning.manage');
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:120', Rule::unique('skill_catalog', 'name')],
            'category'    => ['nullable', 'string', Rule::in(SkillCatalogItem::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
