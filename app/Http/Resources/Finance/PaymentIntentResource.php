<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentIntent */
class PaymentIntentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'reference'           => $this->reference,
            'status'              => ['value' => $this->status->value, 'label' => $this->status->label()],
            'amount'              => (float) $this->amount,
            'currency'            => $this->currency,
            'paystack_reference'  => $this->paystack_reference,
            'authorization_url'   => $this->authorization_url,
            'narration'           => $this->narration,
            'paid_at'             => $this->paid_at?->format('Y-m-d H:i'),
            'expires_at'          => $this->expires_at?->format('Y-m-d H:i'),
            'ar_receipt_id'       => $this->ar_receipt_id,
            'customer'            => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'code' => $this->customer->code, 'name' => $this->customer->name,
            ]),
            'invoice'             => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id, 'reference' => $this->invoice->reference,
            ] : null),
            'created_at'          => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
