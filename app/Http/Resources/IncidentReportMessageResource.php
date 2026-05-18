<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentReportMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'author'      => [
                'id'   => $this->author?->id,
                'name' => $this->author?->name,
            ],
            'body'        => $this->body,
            'created_at'  => $this->created_at?->toISOString(),
            'attachments' => IncidentReportAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
