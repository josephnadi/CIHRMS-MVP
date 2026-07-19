<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetAudit */
class AssetAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'reference'         => $this->reference,
            'status'            => ['value' => $this->status->value, 'label' => $this->status->label()],
            'scope_type'        => $this->scope_type,
            'scope_value'       => $this->scope_value,
            'total_lines'       => $this->total_lines,
            'counted_lines'     => $this->counted_lines,
            'discrepancy_lines' => $this->discrepancy_lines,
            'notes'             => $this->notes,
            'opened_at'         => $this->opened_at?->format('Y-m-d H:i'),
            'completed_at'      => $this->completed_at?->format('Y-m-d H:i'),
            'lines'             => AssetAuditLineResource::collection($this->whenLoaded('lines')),
            'events'            => AssetAuditEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
