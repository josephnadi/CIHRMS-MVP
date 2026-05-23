<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\VendorInvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VendorInvoiceLine */
class VendorInvoiceLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'line_no'     => $this->line_no,
            'description' => $this->description,
            'quantity'    => (float) $this->quantity,
            'unit_price'  => (float) $this->unit_price,
            'line_total'  => (float) $this->line_total,
            'tax_rate'    => (float) $this->tax_rate,
            'tax_amount'  => (float) $this->tax_amount,
            'gl_account'  => $this->whenLoaded('glAccount', fn () => [
                'id' => $this->glAccount?->id,
                'code' => $this->glAccount?->code,
                'name' => $this->glAccount?->name,
            ]),
        ];
    }
}
