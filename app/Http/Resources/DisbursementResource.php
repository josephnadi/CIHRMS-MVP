<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DisbursementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'run' => $this->whenLoaded('run', fn () => $this->run ? [
                'id'        => $this->run->id,
                'reference' => $this->run->reference,
                'period'    => sprintf('%04d-%02d', $this->run->period_year, $this->run->period_month),
            ] : null),
            'employee' => [
                'id'   => $this->employee?->id,
                'no'   => $this->employee?->employee_no,
                'name' => $this->employee?->user?->name,
            ],
            'channel'             => $this->channel?->value,
            'channel_label'       => $this->channel?->label(),
            'status'              => $this->status?->value,
            'status_label'        => $this->status?->label(),
            'gross_amount'        => (float) $this->gross_amount,
            'e_levy'              => (float) $this->e_levy,
            'net_to_recipient'    => (float) $this->net_to_recipient,
            'beneficiary_account' => $this->beneficiary_account,
            'beneficiary_name'    => $this->beneficiary_name,
            'provider_reference'  => $this->provider_reference,
            'sent_at'             => optional($this->sent_at)->toIso8601String(),
            'settled_at'          => optional($this->settled_at)->toIso8601String(),
            'failed_at'           => optional($this->failed_at)->toIso8601String(),
            'failure_reason'      => $this->failure_reason,
            'retry_count'         => (int) $this->retry_count,
        ];
    }
}
