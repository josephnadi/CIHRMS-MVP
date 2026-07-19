<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetAuditEvent */
class AssetAuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'action'     => $this->action,
            'detail'     => $this->detail,
            'actor'      => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor?->id, 'name' => $this->actor?->name,
            ]),
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
