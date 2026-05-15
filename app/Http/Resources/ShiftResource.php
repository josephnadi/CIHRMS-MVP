<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'code'                 => $this->code,
            'name'                 => $this->name,
            'start_time'           => substr((string) $this->start_time, 0, 5),
            'end_time'             => substr((string) $this->end_time, 0, 5),
            'grace_period_minutes' => $this->grace_period_minutes,
            'full_day_hours'       => (float) $this->full_day_hours,
            'half_day_hours'       => (float) $this->half_day_hours,
            'working_days'         => $this->working_days,
            'department'           => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'is_active'            => (bool) $this->is_active,
        ];
    }
}
