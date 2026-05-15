<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BenefitPlanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                              => $this->id,
            'name'                            => $this->name,
            'code'                            => $this->code,
            'type'                            => $this->type?->value,
            'provider'                        => $this->provider,
            'description'                     => $this->description,
            'monthly_cost'                    => (float) $this->monthly_cost,
            'employee_contribution_percentage'=> (float) $this->employee_contribution_percentage,
            'is_active'                       => (bool) $this->is_active,
            'effective_from'                  => $this->effective_from?->toDateString(),
            'effective_to'                    => $this->effective_to?->toDateString(),
            'max_dependants'                  => $this->max_dependants,
            'cover_details'                   => $this->cover_details,
        ];
    }
}
