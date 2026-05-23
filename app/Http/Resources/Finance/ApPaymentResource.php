<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\ApPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApPayment */
class ApPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'reference'      => $this->reference,
            'status'         => ['value' => $this->status->value, 'label' => $this->status->label()],
            'payment_date'   => $this->payment_date?->format('Y-m-d'),
            'amount'         => (float) $this->amount,
            'currency'       => $this->currency,
            'narration'      => $this->narration,
            'journal_entry_id' => $this->journal_entry_id,
            'disbursement_id'  => $this->disbursement_id,
            'vendor'          => $this->whenLoaded('vendor', fn () => ['id' => $this->vendor->id, 'code' => $this->vendor->code, 'name' => $this->vendor->name]),
            'bank_account'    => $this->whenLoaded('bankAccount', fn () => ['id' => $this->bankAccount->id, 'bank_name' => $this->bankAccount->bank_name, 'account_name' => $this->bankAccount->account_name]),
            'allocations'     => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'id' => $a->id, 'vendor_invoice_id' => $a->vendor_invoice_id, 'allocated_amount' => (float) $a->allocated_amount,
            ])),
            'processed_at'    => $this->processed_at?->format('Y-m-d H:i'),
            'voided_at'       => $this->voided_at?->format('Y-m-d H:i'),
        ];
    }
}
