<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\IncomingInvoiceEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IncomingInvoiceEvent */
class IncomingInvoiceEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'action'      => $this->action,
            'from_status' => $this->from_status,
            'to_status'   => $this->to_status,
            'comment'     => $this->comment,
            'actor'       => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor?->id, 'name' => $this->actor?->name,
            ]),
            'created_at'  => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
