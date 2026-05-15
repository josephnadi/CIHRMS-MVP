<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'description'    => $this->description,
            'cadence'        => $this->cadence?->value,
            'cadence_label'  => $this->cadence?->label(),
            'target_value'   => $this->target_value !== null ? (float) $this->target_value : null,
            'current_value'  => (float) $this->current_value,
            'unit'           => $this->unit,
            'weight'         => (int) $this->weight,
            'status'         => $this->status?->value,
            'status_label'   => $this->status?->label(),
            'status_color'   => $this->status?->color(),
            'progress_pct'   => $this->progress_pct,
            'starts_at'      => $this->starts_at?->toDateString(),
            'due_at'         => $this->due_at?->toDateString(),
            'completed_at'   => $this->completed_at?->toIso8601String(),

            'employee_id'    => $this->employee_id,
            'parent_goal_id' => $this->parent_goal_id,
            'cycle_id'       => $this->cycle_id,

            'employee'       => $this->whenLoaded('employee', fn () => [
                'id'           => $this->employee->id,
                'name'         => $this->employee->user?->name,
                'employee_no'  => $this->employee->employee_no,
                'department'   => $this->employee->department?->name,
            ]),
            'cycle'          => $this->whenLoaded('cycle', fn () => $this->cycle ? [
                'id'   => $this->cycle->id,
                'name' => $this->cycle->name,
            ] : null),
            'last_checkin'   => $this->whenLoaded('checkins', fn () => optional($this->checkins->first(), fn ($c) => [
                'progress_pct' => (float) $c->progress_pct,
                'mood'         => $c->mood,
                'recorded_at'  => $c->recorded_at?->toIso8601String(),
            ])),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
