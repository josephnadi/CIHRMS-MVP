<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetAuditLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetAuditLine */
class AssetAuditLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'asset'             => $this->whenLoaded('asset', fn () => [
                'id'       => $this->asset->id,
                'asset_tag'=> $this->asset->asset_tag,
                'name'     => $this->asset->name,
            ]),
            'expected_status'   => $this->expected_status,
            'expected_location' => $this->expected_location,
            'expected_holder'   => $this->whenLoaded('expectedHolder', fn () => $this->expectedHolder?->user?->name),
            'result'            => ['value' => $this->result->value, 'label' => $this->result->label()],
            'observed_location' => $this->observed_location,
            'observed_note'     => $this->observed_note,
            'is_discrepancy'    => $this->is_discrepancy,
            'resolution_action' => ['value' => $this->resolution_action->value, 'label' => $this->resolution_action->label()],
            'resolved_at'       => $this->resolved_at?->format('Y-m-d H:i'),
        ];
    }
}
