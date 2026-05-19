<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DocumentEventResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type?->value,
            'actor'        => ['id' => $this->actor_id, 'name' => $this->actor?->name],
            'payload'      => $this->payload,
            'occurred_at'  => $this->occurred_at,
        ];
    }
}
