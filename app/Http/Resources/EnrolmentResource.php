<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrolmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'course_id'      => $this->course_id,
            'employee_id'    => $this->employee_id,
            'status'         => $this->status?->value,
            'status_label'   => $this->status?->label(),
            'status_color'   => $this->status?->color(),
            'progress_pct'   => (float) $this->progress_pct,
            'final_score'    => $this->final_score !== null ? (float) $this->final_score : null,
            'certificate_path' => $this->certificate_path,
            'enrolled_at'    => $this->enrolled_at?->toIso8601String(),
            'started_at'     => $this->started_at?->toIso8601String(),
            'completed_at'   => $this->completed_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),

            'course'   => $this->whenLoaded('course', fn () => [
                'id'             => $this->course->id,
                'title'          => $this->course->title,
                'slug'           => $this->course->slug,
                'cover_image'    => $this->course->cover_image,
                'category'       => $this->course->category?->value,
                'category_label' => $this->course->category?->label(),
                'category_color' => $this->course->category?->color(),
                'duration_label' => $this->course->duration_label,
            ]),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id'         => $this->employee->id,
                'name'       => $this->employee->user?->name,
                'employee_no'=> $this->employee->employee_no,
            ]),
        ];
    }
}
