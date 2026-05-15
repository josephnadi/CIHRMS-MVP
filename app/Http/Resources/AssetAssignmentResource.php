<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssetAssignmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'asset_id'            => $this->asset_id,
            'asset_tag'           => $this->whenLoaded('asset', fn () => $this->asset?->asset_tag),
            'asset'               => $this->whenLoaded('asset', fn () => $this->asset ? [
                'id'        => $this->asset->id,
                'name'      => $this->asset->name,
                'asset_tag' => $this->asset->asset_tag,
            ] : null),
            'employee_id'         => $this->employee_id,
            'employee_no'         => $this->whenLoaded('employee', fn () => $this->employee?->employee_no),
            'employee_name'       => $this->whenLoaded('employee', fn () => $this->employee?->user?->name),
            'assigned_at'         => $this->assigned_at?->toIso8601String(),
            'assigned_by'         => $this->whenLoaded('assignedBy', fn () => $this->assignedBy?->name),
            'due_back_at'         => $this->due_back_at?->toDateString(),
            'returned_at'         => $this->returned_at?->toIso8601String(),
            'returned_to'         => $this->whenLoaded('returnedToUser', fn () => $this->returnedToUser?->name),
            'condition_on_return' => $this->condition_on_return?->value,
            'notes'               => $this->notes,
        ];
    }
}
