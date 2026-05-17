<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeV1Resource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'employee_no'  => $this->employee_no,
            'name'         => $this->user?->name,
            'email'        => $this->user?->email,
            'department'   => $this->whenLoaded('department', fn () => [
                'id'   => $this->department?->id,
                'name' => $this->department?->name,
                'code' => $this->department?->code,
            ]),
            'position'     => $this->position,
            'grade'        => $this->whenLoaded('currentGrade', fn () => $this->currentGrade?->code),
            'step'         => $this->current_step,
            'hire_date'    => optional($this->hire_date)->toDateString(),
            'status'       => $this->status?->value ?? $this->status,
            'phone'        => $this->phone,
            // Sensitive fields (salary, bank, Ghana Card) are NEVER returned on /api/v1
            // — partners that need them must use the dedicated, separately-scoped
            // statutory-export endpoint.
        ];
    }
}
