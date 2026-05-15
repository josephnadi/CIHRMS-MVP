<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'employee' => [
                'id'          => $this->employee?->id,
                'employee_no' => $this->employee?->employee_no,
                'name'        => $this->employee?->user?->name,
            ],
            'device'     => $this->whenLoaded('device', fn () => $this->device?->only(['id', 'code', 'name'])),
            'source'     => $this->source?->value,
            'source_label' => $this->source?->label(),
            'direction'  => $this->direction,
            'event_at'   => optional($this->event_at)->toIso8601String(),
            'recorded_at'=> optional($this->recorded_at)->toIso8601String(),
            'geo'        => $this->geo_lat ? ['lat' => (float) $this->geo_lat, 'lng' => (float) $this->geo_lng] : null,
            'reason'     => $this->reason,
            'recorded_by'=> $this->recorder?->name,
        ];
    }
}
