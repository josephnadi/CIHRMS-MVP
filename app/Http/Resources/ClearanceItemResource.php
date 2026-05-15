<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClearanceItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'area'          => $this->area?->value,
            'area_label'    => $this->area?->label(),
            'label'         => $this->label,
            'status'        => $this->status?->value,
            'status_label'  => $this->status?->label(),
            'is_required'   => (bool) $this->is_required,
            'department'    => $this->whenLoaded('department', fn () => $this->department?->only(['id', 'name'])),
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => $this->responsibleUser?->only(['id', 'name'])),
            'cleared_by'    => $this->whenLoaded('clearer', fn () => $this->clearer?->only(['id', 'name'])),
            'cleared_at'    => optional($this->cleared_at)->toIso8601String(),
            'notes'         => $this->notes,
        ];
    }
}
