<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssetMaintenanceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'asset_id'     => $this->asset_id,
            'type'         => $this->type?->value,
            'status'       => $this->status?->value,
            'started_at'   => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cost'         => $this->cost !== null ? (float) $this->cost : null,
            'vendor'       => $this->vendor,
            'notes'        => $this->notes,
            'recorded_by'  => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
        ];
    }
}
