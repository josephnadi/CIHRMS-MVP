<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\ArInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ArInvoice */
class ArInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'reference'           => $this->reference,
            'customer_invoice_no' => $this->customer_invoice_no,
            'status'              => ['value' => $this->status->value, 'label' => $this->status->label()],
            'invoice_date'        => $this->invoice_date?->format('Y-m-d'),
            'due_date'            => $this->due_date?->format('Y-m-d'),
            'subtotal'            => (float) $this->subtotal,
            'tax_amount'          => (float) $this->tax_amount,
            'total'               => (float) $this->total,
            'amount_received'     => (float) $this->amount_received,
            'outstanding'         => $this->outstandingAmount(),
            'currency'            => $this->currency,
            'notes'               => $this->notes,
            'customer'            => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'code' => $this->customer->code, 'name' => $this->customer->name,
            ]),
            'lines'               => ArInvoiceLineResource::collection($this->whenLoaded('lines')),
            'accrual_journal_entry_id'   => $this->accrual_journal_entry_id,
            'write_off_journal_entry_id' => $this->write_off_journal_entry_id,
            'approved_by'         => $this->approved_by,
            'approved_at'         => $this->approved_at?->format('Y-m-d H:i'),
            'cancelled_by'        => $this->cancelled_by,
            'cancelled_at'        => $this->cancelled_at?->format('Y-m-d H:i'),
            'written_off_by'      => $this->written_off_by,
            'written_off_at'      => $this->written_off_at?->format('Y-m-d H:i'),
            'written_off_reason'  => $this->written_off_reason,
            'created_by'          => $this->created_by,
        ];
    }
}
