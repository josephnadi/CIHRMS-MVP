<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PipResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'employee'        => [
                'id'          => $this->employee?->id,
                'employee_no' => $this->employee?->employee_no,
                'name'        => $this->employee?->user?->name,
                'department'  => $this->employee?->department?->name,
            ],
            'mentor'          => $this->whenLoaded('mentor', fn () => $this->mentor ? [
                'id'   => $this->mentor->id,
                'name' => $this->mentor->user?->name,
            ] : null),
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'opened_on'       => optional($this->opened_on)->toDateString(),
            'target_end_date' => optional($this->target_end_date)->toDateString(),
            'actual_end_date' => optional($this->actual_end_date)->toDateString(),
            'extensions_used' => (int) $this->extensions_used,
            'max_extensions'  => (int) $this->max_extensions,
            'target_metrics'  => $this->target_metrics ?? [],
            'checkins'        => $this->checkins ?? [],
            'outcome_summary' => $this->outcome_summary,
        ];
    }
}
