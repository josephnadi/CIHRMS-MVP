<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PolicyResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request->user();
        $myAck = null;
        if ($user && $this->current_version_id) {
            $myAck = \App\Models\PolicyAcknowledgement::where('policy_version_id', $this->current_version_id)
                ->where('user_id', $user->id)
                ->first();
        }

        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'slug'         => $this->slug,
            'category'     => $this->category?->value,
            'summary'      => $this->summary,
            'is_active'    => (bool) $this->is_active,
            'owner'        => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id, 'name' => $this->owner->name,
            ] : null),
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion ? [
                'id'             => $this->currentVersion->id,
                'version_number' => $this->currentVersion->version_number,
                'effective_from' => $this->currentVersion->effective_from?->toDateString(),
                'published_at'   => $this->currentVersion->published_at?->toIso8601String(),
            ] : null),
            'my_ack_status' => $myAck ? 'acknowledged' : ($this->current_version_id ? 'pending' : 'no_version'),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];
    }
}
