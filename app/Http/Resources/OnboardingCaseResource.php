<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingCaseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'reference'       => $this->reference,
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'hire_date'       => optional($this->hire_date)->toDateString(),
            'target_date'     => optional($this->target_completion_date)->toDateString(),
            'progress'        => $this->progress(),
            'employee'        => [
                'id'          => $this->employee?->id,
                'name'        => $this->employee?->user?->name,
            ],
            'tasks'           => OnboardingTaskResource::collection($this->whenLoaded('tasks')),
            'completed_at'    => optional($this->completed_at)->toIso8601String(),
            'can' => [
                'complete' => $request->user()?->can('complete', $this->resource),
                'manage'   => $request->user()?->can('manage', $this->resource),
            ],
        ];
    }
}
