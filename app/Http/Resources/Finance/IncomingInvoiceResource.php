<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\IncomingInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IncomingInvoice */
class IncomingInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'reference'         => $this->reference,
            'status'            => ['value' => $this->status->value, 'label' => $this->status->label()],
            'vendor_name'       => $this->vendor_name,
            'vendor_invoice_no' => $this->vendor_invoice_no,
            'invoice_date'      => $this->invoice_date?->format('Y-m-d'),
            'currency'          => $this->currency,
            'amount'            => (float) $this->amount,
            'description'       => $this->description,
            'department'        => $this->whenLoaded('department', fn () => [
                'id' => $this->department?->id, 'name' => $this->department?->name,
            ]),
            'vetting_notes'     => $this->vetting_notes,
            'return_reason'     => $this->return_reason,
            'vendor_invoice_id' => $this->vendor_invoice_id,
            'submitted_at'      => $this->submitted_at?->format('Y-m-d H:i'),
            'vetted_at'         => $this->vetted_at?->format('Y-m-d H:i'),
            'approved_at'       => $this->approved_at?->format('Y-m-d H:i'),
            'posted_at'         => $this->posted_at?->format('Y-m-d H:i'),
            'created_by'        => $this->created_by,
            'attachments'       => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id, 'original_name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
            ])),
            'events'            => IncomingInvoiceEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
