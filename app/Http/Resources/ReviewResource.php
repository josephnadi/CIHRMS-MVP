<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'cycle_id'           => $this->cycle_id,
            'employee_id'        => $this->employee_id,
            'reviewer_id'        => $this->reviewer_id,
            'type'               => $this->type?->value,
            'type_label'         => $this->type?->label(),
            'overall_rating'     => $this->overall_rating !== null ? (float) $this->overall_rating : null,
            'performance_rating' => $this->performance_rating !== null ? (float) $this->performance_rating : null,
            'potential_rating'   => $this->potential_rating !== null ? (float) $this->potential_rating : null,
            'strengths'          => $this->strengths,
            'opportunities'      => $this->opportunities,
            'comments'           => $this->comments,
            'status'             => $this->status?->value,
            'status_label'       => $this->status?->label(),
            'status_color'       => $this->status?->color(),
            'submitted_at'       => $this->submitted_at?->toIso8601String(),
            'acknowledged_at'    => $this->acknowledged_at?->toIso8601String(),

            'cycle'    => $this->whenLoaded('cycle', fn () => [
                'id'   => $this->cycle->id,
                'name' => $this->cycle->name,
            ]),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id'           => $this->employee->id,
                'name'         => $this->employee->user?->name,
                'employee_no'  => $this->employee->employee_no,
                'department'   => $this->employee->department?->name,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id'   => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
