<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Submitter-facing status view. Heavily redacted compared to the investigator
 * resource: no internal IDs, no investigator name, no other-party PII —
 * just enough for the submitter to see "your case is at status X, here are
 * messages from the investigator".
 */
class TrackingStatusResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'case_number'   => $this->case_number,
            'status'        => $this->status?->value,
            'status_label'  => $this->status?->label(),
            'category_label'=> $this->category?->label(),
            'received_at'   => optional($this->received_at)->toDateString(),
            'triaged_at'    => optional($this->triaged_at)->toDateString(),
            'closed_at'     => optional($this->closed_at)->toDateString(),
            'closure_summary' => $this->closure_summary,    // visible if case is closed
            'messages'      => $this->whenLoaded('messages', fn () => $this->messages
                ->sortBy('posted_at')->values()
                ->map(fn ($m) => [
                    'direction' => $m->direction,
                    'body'      => $m->body,
                    'posted_at' => $m->posted_at?->toIso8601String(),
                ])),
        ];
    }
}
