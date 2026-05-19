<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DocumentAnnotationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'        => $this->id,
            'type'      => $this->type?->value,
            'page'      => $this->page,
            'x_pct'     => (float) $this->x_pct,
            'y_pct'     => (float) $this->y_pct,
            'w_pct'     => (float) $this->w_pct,
            'h_pct'     => (float) $this->h_pct,
            'rotation'  => $this->rotation,
            'data'      => $this->data,
            'user'      => ['id' => $this->user_id, 'name' => $this->user?->name],
            'route_id'  => $this->route_id,
            'created_at'=> $this->created_at,
        ];
    }
}
