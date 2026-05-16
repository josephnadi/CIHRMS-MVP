<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceContractResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'cycle'          => $this->whenLoaded('cycle', fn () => $this->cycle?->only(['id', 'name', 'status'])),
            'employee'       => [
                'id'          => $this->employee?->id,
                'employee_no' => $this->employee?->employee_no,
                'name'        => $this->employee?->user?->name,
                'department'  => $this->employee?->department?->name,
            ],
            'supervisor'     => $this->whenLoaded('supervisor', fn () => $this->supervisor ? [
                'id'          => $this->supervisor->id,
                'name'        => $this->supervisor->user?->name,
            ] : null),
            'status'         => $this->status?->value,
            'status_label'   => $this->status?->label(),
            'kpis'           => $this->kpis ?? [],
            'balanced_scorecard'   => $this->balanced_scorecard,
            'weighted_achievement' => $this->weighted_achievement !== null ? (float) $this->weighted_achievement : null,
            'employee_signed_at'   => optional($this->employee_signed_at)->toIso8601String(),
            'supervisor_signed_at' => optional($this->supervisor_signed_at)->toIso8601String(),
            'is_fully_signed'      => $this->isFullySigned(),
            'finalised_at'         => optional($this->finalised_at)->toIso8601String(),
            'mid_year_note'        => $this->mid_year_note,
            'end_year_note'        => $this->end_year_note,

            'can' => [
                'sign'     => $request->user()?->can('sign', $this->resource),
                'evaluate' => $request->user()?->can('evaluate', $this->resource),
            ],
        ];
    }
}
