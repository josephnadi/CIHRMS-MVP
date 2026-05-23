<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\BankStatement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BankStatement */
class BankStatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totalLines      = $this->lines()->count();
        $reconciledLines = $this->lines()->reconciled()->count();

        return [
            'id'                  => $this->id,
            'statement_date'      => $this->statement_date?->format('Y-m-d'),
            'period_start'        => $this->period_start?->format('Y-m-d'),
            'opening_balance'     => (float) $this->opening_balance,
            'closing_balance'     => (float) $this->closing_balance,
            'currency'            => $this->currency,
            'file_name'           => $this->file_name,
            'format'              => $this->format,
            'total_lines'         => $totalLines,
            'reconciled_lines'    => $reconciledLines,
            'reconciled_pct'      => $totalLines > 0 ? round($reconciledLines / $totalLines * 100, 1) : 0.0,
            'imported_at'         => $this->created_at?->format('Y-m-d H:i'),
            'org_bank_account'    => $this->whenLoaded('orgBankAccount', fn () => [
                'id' => $this->orgBankAccount->id, 'bank_name' => $this->orgBankAccount->bank_name,
            ]),
        ];
    }
}
