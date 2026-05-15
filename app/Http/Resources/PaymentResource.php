<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'description'  => $this->description,
            'amount'       => $this->amount,
            'currency'     => $this->currency,
            'status'       => $this->status?->value,
            'status_label' => $this->status?->label(),
            'paid_at'      => $this->paid_at?->toISOString(),
            'employee'     => $this->whenLoaded('employee', fn () => [
                'id'          => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'name'        => $this->employee->user?->name,
            ]),
            'processed_by' => $this->whenLoaded('processedBy', fn () => [
                'id'   => $this->processedBy->id,
                'name' => $this->processedBy->name,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id'     => $item->id,
                'label'  => $item->label,
                'type'   => $item->type,
                'amount' => $item->amount,
            ])),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
