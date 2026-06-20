<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type?->value,
            'type_label'   => $this->type?->label(),
            'start_date'   => $this->start_date?->toDateString(),
            'end_date'     => $this->end_date?->toDateString(),
            'duration_days' => $this->durationInDays(),
            'reason'       => $this->reason,
            'status'       => $this->status?->value,
            'status_label' => $this->status?->label(),
            'decision_comment' => $this->decision_comment,
            'decided_at'       => $this->decided_at?->toISOString(),
            'has_attachment'   => ! empty($this->attachment_path),
            'employee'     => $this->whenLoaded('employee', fn () => [
                'id'          => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'position'    => $this->employee->position,
            ]),
            'approver' => $this->whenLoaded('approver', fn () => [
                'id'   => $this->approver->id,
                'name' => $this->approver->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
