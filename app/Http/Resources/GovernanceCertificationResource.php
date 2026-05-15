<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GovernanceCertificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'employee_id'       => $this->employee_id,
            'employee'          => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'          => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'name'        => $this->employee->user?->name,
            ] : null),
            'name'              => $this->name,
            'issuer'            => $this->issuer,
            'credential_id'     => $this->credential_id,
            'issued_at'         => $this->issued_at?->toDateString(),
            'expires_at'        => $this->expires_at?->toDateString(),
            'days_to_expiry'    => $this->daysToExpiry,
            'verification_url'  => $this->verification_url,
            'reminder_sent_at'  => $this->reminder_sent_at?->toIso8601String(),
        ];
    }
}
