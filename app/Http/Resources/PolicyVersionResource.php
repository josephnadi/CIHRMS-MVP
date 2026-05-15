<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PolicyVersionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'policy_id'      => $this->policy_id,
            'version_number' => $this->version_number,
            'body'           => $this->body,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to'   => $this->effective_to?->toDateString(),
            'changelog'      => $this->changelog,
            'published_at'   => $this->published_at?->toIso8601String(),
            'published_by'   => $this->whenLoaded('publishedBy', fn () => $this->publishedBy?->name),
            'ack_count'      => $this->whenCounted('acknowledgements'),
        ];
    }
}
