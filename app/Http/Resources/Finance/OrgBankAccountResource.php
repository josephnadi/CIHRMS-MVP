<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\OrgBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrgBankAccount */
class OrgBankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canManage = $request->user()?->hasPermission('bank_accounts.manage') === true;
        $accountNumber = (string) $this->account_number;

        return [
            'id'              => $this->id,
            'gl_account'      => new \App\Http\Resources\Finance\GlAccountResource($this->whenLoaded('glAccount')),
            'bank_name'       => $this->bank_name,
            'branch'          => $this->branch,
            'account_name'    => $this->account_name,
            'account_number'  => $canManage
                ? $accountNumber
                : str_repeat('•', max(4, strlen($accountNumber) - 4)) . substr($accountNumber, -4),
            'sort_code'       => $this->sort_code,
            'swift'           => $this->swift,
            'currency'        => $this->currency,
            'purpose'         => [
                'value' => $this->purpose->value,
                'label' => $this->purpose->label(),
            ],
            'opening_balance' => (float) $this->opening_balance,
            'is_active'       => $this->is_active,
            'notes'           => $this->notes,
        ];
    }
}
