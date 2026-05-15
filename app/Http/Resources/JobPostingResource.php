<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobPostingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'closes_at'   => $this->closes_at?->toDateString(),
            'status'      => $this->status?->value,
            'is_expired'  => $this->isExpired(),
            'applicants_count' => $this->whenCounted('applicants'),
            'applicants'  => ApplicantResource::collection($this->whenLoaded('applicants')),
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
