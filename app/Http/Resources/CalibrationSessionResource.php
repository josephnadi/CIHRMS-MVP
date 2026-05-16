<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CalibrationSessionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'cycle'               => $this->whenLoaded('cycle', fn () => $this->cycle?->only(['id', 'name'])),
            'department'          => $this->whenLoaded('department', fn () => $this->department?->only(['id', 'name'])),
            'status'              => $this->status?->value,
            'status_label'        => $this->status?->label(),
            'facilitator'         => $this->whenLoaded('facilitator', fn () => $this->facilitator?->only(['id', 'name'])),
            'opened_at'           => optional($this->opened_at)->toIso8601String(),
            'locked_at'           => optional($this->locked_at)->toIso8601String(),
            'applied_at'          => optional($this->applied_at)->toIso8601String(),
            'target_distribution' => $this->target_distribution,
            'notes'               => $this->notes,
            'adjustments_count'   => $this->adjustments?->count() ?? 0,

            'adjustments' => $this->whenLoaded('adjustments', fn () => $this->adjustments->map(fn ($a) => [
                'id'              => $a->id,
                'review_id'       => $a->review_id,
                'original_rating' => (float) $a->original_rating,
                'adjusted_rating' => (float) $a->adjusted_rating,
                'reason'          => $a->reason,
                'adjusted_at'     => optional($a->adjusted_at)->toIso8601String(),
            ])),
        ];
    }
}
