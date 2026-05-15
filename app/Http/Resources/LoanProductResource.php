<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LoanProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'code'                 => $this->code,
            'name'                 => $this->name,
            'type'                 => $this->type?->value,
            'type_label'           => $this->type?->label(),
            'min_amount'           => (float) $this->min_amount,
            'max_amount'           => (float) $this->max_amount,
            'min_term_months'      => (int) $this->min_term_months,
            'max_term_months'      => (int) $this->max_term_months,
            'annual_interest_rate' => (float) $this->annual_interest_rate,
            'amortization_method'  => $this->amortization_method?->value,
            'amortization_label'   => $this->amortization_method?->label(),
            'requires_guarantor'   => (bool) $this->requires_guarantor,
            'requires_collateral'  => (bool) $this->requires_collateral,
            'is_active'            => (bool) $this->is_active,
            'description'          => $this->description,
        ];
    }
}
