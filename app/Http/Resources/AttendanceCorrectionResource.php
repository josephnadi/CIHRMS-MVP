<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceCorrectionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'employee'            => $this->whenLoaded('employee', fn () => [
                'id'          => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'position'    => $this->employee->position,
            ]),
            'requester'           => $this->whenLoaded('requester', fn () => [
                'id'   => $this->requester->id,
                'name' => $this->requester->name,
            ]),
            'reviewer'            => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id'   => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'requested_event_at'  => $this->requested_event_at?->toIso8601String(),
            'requested_direction' => $this->requested_direction,
            'reason'              => $this->reason,
            'status'              => $this->status?->value,
            'reviewed_at'         => $this->reviewed_at?->toIso8601String(),
            'decision_notes'      => $this->decision_notes,
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
