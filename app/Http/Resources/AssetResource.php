<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'asset_tag'            => $this->asset_tag,
            'name'                 => $this->name,
            'category'             => $this->category?->value,
            'serial_number'        => $this->serial_number,
            'brand'                => $this->brand,
            'model'                => $this->model,
            'purchase_date'        => $this->purchase_date?->toDateString(),
            'purchase_cost'        => $this->purchase_cost !== null ? (float) $this->purchase_cost : null,
            'currency'             => $this->currency,
            'supplier'             => $this->supplier,
            'warranty_expires_at'  => $this->warranty_expires_at?->toDateString(),
            'current_status'       => $this->current_status?->value,
            'location'             => $this->location,
            'notes'                => $this->notes,
            'current_assignment'   => $this->whenLoaded('currentAssignment', fn () => $this->currentAssignment ? [
                'id'                  => $this->currentAssignment->id,
                'employee_id'         => $this->currentAssignment->employee_id,
                'employee_no'         => $this->currentAssignment->employee?->employee_no,
                'employee_name'       => $this->currentAssignment->employee?->user?->name,
                'assigned_at'         => $this->currentAssignment->assigned_at?->toIso8601String(),
                'due_back_at'         => $this->currentAssignment->due_back_at?->toDateString(),
            ] : null),
            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }
}
