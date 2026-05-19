<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DocumentRouteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'sequence'        => $this->sequence,
            'from_user'       => ['id' => $this->from_user_id, 'name' => $this->fromUser?->name],
            'to_user'         => ['id' => $this->to_user_id,   'name' => $this->toUser?->name],
            'action_required' => $this->action_required?->value,
            'action_label'    => $this->action_required?->label(),
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'due_at'          => $this->due_at,
            'acted_at'        => $this->acted_at,
            'comment'         => $this->comment,
        ];
    }
}
