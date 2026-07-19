<?php

declare(strict_types=1);

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class CompleteAssetAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('asset_audits.manage') === true;
    }

    public function rules(): array
    {
        return [];
    }
}
