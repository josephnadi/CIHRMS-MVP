<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSummaryV1Resource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'employee_id'     => $this->employee_id,
            'employee_no'     => $this->employee?->employee_no,
            'date'            => optional($this->summary_date)->toDateString(),
            'status'          => $this->status?->value ?? $this->status,
            'first_in'        => $this->first_in,
            'last_out'        => $this->last_out,
            'hours_worked'    => (float) $this->hours_worked,
            'overtime_hours'  => (float) $this->overtime_hours,
            'is_weekend'      => (bool) $this->is_weekend,
            'is_holiday'      => (bool) $this->is_holiday,
        ];
    }
}
