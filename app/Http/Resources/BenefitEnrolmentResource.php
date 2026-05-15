<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BenefitEnrolmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'plan_id'         => $this->plan_id,
            'employee_id'     => $this->employee_id,
            'plan'            => $this->whenLoaded('plan', fn () => $this->plan ? [
                'id' => $this->plan->id, 'name' => $this->plan->name, 'code' => $this->plan->code, 'type' => $this->plan->type?->value,
            ] : null),
            'employee'        => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'          => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'name'        => $this->employee->user?->name,
            ] : null),
            'enrolled_at'     => $this->enrolled_at?->toDateString(),
            'effective_from'  => $this->effective_from?->toDateString(),
            'effective_to'    => $this->effective_to?->toDateString(),
            'status'          => $this->status?->value,
            'monthly_premium' => (float) $this->monthly_premium,
            'notes'           => $this->notes,
        ];
    }
}
