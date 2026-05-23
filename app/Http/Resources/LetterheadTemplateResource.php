<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LetterheadTemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'owner_scope'      => $this->owner_scope?->value,
            'owner_id'         => $this->owner_id,
            'header_height_mm' => $this->header_height_mm,
            'is_default'       => $this->is_default,
            'preview_url'      => route('settings.letterheads.preview', $this->id),
        ];
    }
}
