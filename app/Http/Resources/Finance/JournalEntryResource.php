<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JournalEntry */
class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'reference'    => $this->reference,
            'entry_date'   => $this->entry_date?->format('Y-m-d'),
            'narration'    => $this->narration,
            'status'       => ['value' => $this->status->value, 'label' => $this->status->label()],
            'source_type'  => ['value' => $this->source_type->value, 'label' => $this->source_type->label()],
            'source_id'    => $this->source_id,
            'posted_at'    => $this->posted_at?->format('Y-m-d H:i'),
            'reversed_at'  => $this->reversed_at?->format('Y-m-d H:i'),
            'reversal_of_id' => $this->reversal_of_id,
            'lines'        => JournalLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
