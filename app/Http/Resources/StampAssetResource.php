<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StampAssetResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'owner_scope'   => $this->owner_scope?->value,
            'owner_id'      => $this->owner_id,
            'default_w_pct' => $this->default_w_pct,
            'default_h_pct' => $this->default_h_pct,
            'preview_url'   => route('settings.stamps.preview', $this->id),
            'created_at'    => $this->created_at,
        ];
    }
}
