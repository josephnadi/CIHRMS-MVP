<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\ArReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ArReceipt */
class ArReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'reference'      => $this->reference,
            'status'         => ['value' => $this->status->value, 'label' => $this->status->label()],
            'receipt_date'   => $this->receipt_date?->format('Y-m-d'),
            'amount'         => (float) $this->amount,
            'currency'       => $this->currency,
            'external_ref'   => $this->external_ref,
            'narration'      => $this->narration,
            'journal_entry_id' => $this->journal_entry_id,
            'customer'       => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'code' => $this->customer->code, 'name' => $this->customer->name,
            ]),
            'bank_account'   => $this->whenLoaded('bankAccount', fn () => [
                'id' => $this->bankAccount->id, 'bank_name' => $this->bankAccount->bank_name, 'account_name' => $this->bankAccount->account_name,
            ]),
            'allocations'    => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'id' => $a->id, 'ar_invoice_id' => $a->ar_invoice_id, 'allocated_amount' => (float) $a->allocated_amount,
            ])),
            'processed_at'   => $this->processed_at?->format('Y-m-d H:i'),
            'voided_at'      => $this->voided_at?->format('Y-m-d H:i'),
        ];
    }
}
