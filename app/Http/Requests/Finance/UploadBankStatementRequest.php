<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class UploadBankStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reconciliation.import') === true;
    }

    public function rules(): array
    {
        return [
            'org_bank_account_id' => ['required', 'integer', 'exists:org_bank_accounts,id'],
            'bank_key'            => ['nullable', 'string', 'in:gcb,stanbic,gtb,ecobank'],
            'file'                => ['required', 'file', 'max:10240', 'mimes:csv,txt,ofx,sta,mt940,mt'],
        ];
    }
}
