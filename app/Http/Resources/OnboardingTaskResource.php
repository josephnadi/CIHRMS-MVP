<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingTaskResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'area'         => $this->area?->value,
            'area_label'   => $this->area?->label(),
            'label'        => $this->label,
            'status'       => $this->status?->value,
            'is_required'  => (bool) $this->is_required,
            'notes'        => $this->notes,
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'completed_by' => $this->completer?->name,
        ];
    }
}
