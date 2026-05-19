<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'ref_no'          => $this->ref_no,
            'title'           => $this->title,
            'description'     => $this->description,
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'confidentiality' => $this->confidentiality?->value,
            'tags'            => $this->tags ?? [],
            'owner'           => $this->whenLoaded('owner', fn () => [
                'id'   => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'current_version' => $this->whenLoaded('currentVersion', fn () => [
                'id'           => $this->currentVersion?->id,
                'version_no'   => $this->currentVersion?->version_no,
                'original_name'=> $this->currentVersion?->original_name,
                'mime'         => $this->currentVersion?->mime,
                'size'         => $this->currentVersion?->size,
            ]),
            'routes'      => DocumentRouteResource::collection($this->whenLoaded('routes')),
            'annotations' => DocumentAnnotationResource::collection($this->whenLoaded('annotations')),
            'events'      => DocumentEventResource::collection($this->whenLoaded('events')),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
