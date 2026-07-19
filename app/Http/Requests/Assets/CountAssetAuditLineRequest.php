<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use App\Enums\AssetAuditResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CountAssetAuditLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('asset_audits.manage') === true;
    }

    public function rules(): array
    {
        return [
            'result' => ['required', Rule::in([
                AssetAuditResult::Present->value,
                AssetAuditResult::Missing->value,
                AssetAuditResult::WrongLocation->value,
                AssetAuditResult::WrongHolder->value,
                AssetAuditResult::Damaged->value,
            ])],
            'observed_location' => ['nullable', 'string', 'max:120'],
            'observed_note'     => ['nullable', 'string', 'max:2000'],
        ];
    }
}
