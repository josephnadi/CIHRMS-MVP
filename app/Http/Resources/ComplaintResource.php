<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'reference'    => $this->reference,
            'submitted_by' => $this->submitted_by,
            'assigned_to'  => $this->assigned_to,
            'assignee'     => $this->whenLoaded('assignee', fn () => [
                'id'   => $this->assignee?->id,
                'name' => $this->assignee?->name,
            ]),
            'details'      => $this->details,
            'status'       => $this->status?->value,
            'status_label' => $this->status?->label(),
            'created_at'   => $this->created_at?->toISOString(),
            'updated_at'   => $this->updated_at?->toISOString(),
        ];
    }
}
