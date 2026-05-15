<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewCycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'cadence'            => $this->cadence,
            'starts_at'          => $this->starts_at?->toDateString(),
            'ends_at'            => $this->ends_at?->toDateString(),
            'self_review_due'    => $this->self_review_due?->toDateString(),
            'peer_review_due'    => $this->peer_review_due?->toDateString(),
            'manager_review_due' => $this->manager_review_due?->toDateString(),
            'status'             => $this->status?->value,
            'status_label'       => $this->status?->label(),
            'closed_at'          => $this->closed_at?->toIso8601String(),
            'reviews_count'      => $this->reviews_count ?? null,
            'goals_count'        => $this->goals_count ?? null,
        ];
    }
}
