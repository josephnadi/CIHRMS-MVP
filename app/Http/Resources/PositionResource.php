<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PositionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'code'               => $this->code,
            'title'              => $this->title,
            'grade'              => $this->whenLoaded('grade', fn () => $this->grade?->only(['id', 'code', 'name'])),
            'department'         => $this->whenLoaded('department', fn () => $this->department?->only(['id', 'name'])),
            'reports_to'         => $this->whenLoaded('reportsTo', fn () => $this->reportsTo?->only(['id', 'code', 'title'])),
            'cost_center'        => $this->cost_center,
            'funding_source'     => $this->funding_source?->value,
            'funding_source_label'=> $this->funding_source?->label(),
            'status'             => $this->status?->value,
            'status_label'       => $this->status?->label(),
            'headcount_ceiling'  => (int) $this->headcount_ceiling,
            'is_supervisory'     => (bool) $this->is_supervisory,
            'job_description'    => $this->job_description,
        ];
    }
}
