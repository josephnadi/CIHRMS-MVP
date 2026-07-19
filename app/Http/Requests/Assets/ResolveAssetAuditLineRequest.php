<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use App\Enums\AssetAuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveAssetAuditLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('asset_audits.manage') === true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in([
                AssetAuditAction::MarkedLost->value,
                AssetAuditAction::Relocated->value,
                AssetAuditAction::MaintenanceLogged->value,
                AssetAuditAction::Flagged->value,
            ])],
        ];
    }
}
