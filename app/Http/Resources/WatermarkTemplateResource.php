<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WatermarkTemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'owner_scope' => $this->owner_scope?->value,
            'type'        => $this->type,
            'text'        => $this->text,
            'color'       => $this->color,
            'opacity'     => $this->opacity,
            'angle_deg'   => $this->angle_deg,
            'preview_url' => $this->type === 'image' ? route('settings.watermarks.preview', $this->id) : null,
        ];
    }
}
