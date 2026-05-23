<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\BankStatementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BankStatementLine */
class BankStatementLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'line_no'          => $this->line_no,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'value_date'       => $this->value_date?->format('Y-m-d'),
            'description'      => $this->description,
            'reference'        => $this->reference,
            'amount'           => (float) $this->amount,
            'running_balance'  => $this->running_balance !== null ? (float) $this->running_balance : null,
            'matched_type'     => $this->matched_type,
            'matched_id'       => $this->matched_id,
            'confidence'       => $this->confidence,
            'reconciled_at'    => $this->reconciled_at?->format('Y-m-d H:i'),
        ];
    }
}
