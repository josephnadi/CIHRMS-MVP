<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\VendorInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VendorInvoice */
class VendorInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'reference'         => $this->reference,
            'vendor_invoice_no' => $this->vendor_invoice_no,
            'status'            => ['value' => $this->status->value, 'label' => $this->status->label()],
            'invoice_date'      => $this->invoice_date?->format('Y-m-d'),
            'due_date'          => $this->due_date?->format('Y-m-d'),
            'subtotal'          => (float) $this->subtotal,
            'tax_amount'        => (float) $this->tax_amount,
            'total'             => (float) $this->total,
            'amount_paid'       => (float) $this->amount_paid,
            'outstanding'       => $this->outstandingAmount(),
            'currency'          => $this->currency,
            'notes'             => $this->notes,
            'vendor'            => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id, 'code' => $this->vendor->code, 'name' => $this->vendor->name,
            ]),
            'lines'             => VendorInvoiceLineResource::collection($this->whenLoaded('lines')),
            'accrual_journal_entry_id' => $this->accrual_journal_entry_id,
            'approved_by'       => $this->approved_by,
            'approved_at'       => $this->approved_at?->format('Y-m-d H:i'),
            'cancelled_by'      => $this->cancelled_by,
            'cancelled_at'      => $this->cancelled_at?->format('Y-m-d H:i'),
            'created_by'        => $this->created_by,
        ];
    }
}
