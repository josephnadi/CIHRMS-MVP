<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class IdentityVerificationResource extends JsonResource
{
    public function toArray($request): array
    {
        // The Ghana Card number is never returned in API responses; only the masked tail.
        $masked = $this->ghana_card_number
            ? 'GHA-•••••••••-' . substr((string) $this->ghana_card_number, -1)
            : null;

        return [
            'id'              => $this->id,
            'employee' => [
                'id'          => $this->employee?->id,
                'employee_no' => $this->employee?->employee_no,
                'name'        => $this->employee?->user?->name,
            ],
            'provider'        => $this->provider?->value,
            'provider_label'  => $this->provider?->label(),
            'masked_card'     => $masked,
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'verified_at'     => optional($this->verified_at)->toIso8601String(),
            'expires_at'      => optional($this->expires_at)->toIso8601String(),
            'failure_reason'  => $this->failure_reason,
        ];
    }
}
