<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'description'    => $this->description,
            'priority'       => $this->priority?->value,
            'priority_label' => $this->priority?->label(),
            'status'         => $this->status?->value,
            'status_label'   => $this->status?->label(),
            'is_overdue'     => $this->isOverdue(),
            'due_at'         => $this->due_at?->toISOString(),
            'resolved_at'    => $this->resolved_at?->toISOString(),
            'employee'       => $this->whenLoaded('employee', fn () => [
                'id'          => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'name'        => $this->employee->user?->name,
                'email'       => $this->employee->user?->email,
            ]),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => [
                'id'   => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
