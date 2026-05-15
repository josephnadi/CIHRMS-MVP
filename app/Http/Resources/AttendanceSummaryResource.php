<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'date'           => $this->summary_date?->toDateString(),
            'status'         => $this->status?->value,
            'status_label'   => $this->status?->label(),
            'first_in'       => $this->first_in,
            'last_out'       => $this->last_out,
            'hours_worked'   => (float) $this->hours_worked,
            'overtime_hours' => (float) $this->overtime_hours,
            'is_weekend'     => (bool) $this->is_weekend,
            'is_holiday'     => (bool) $this->is_holiday,
            'source'         => $this->source,
        ];
    }
}
