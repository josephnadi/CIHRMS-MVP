<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DependantResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'employee_id'   => $this->employee_id,
            'full_name'     => $this->full_name,
            'relationship'  => $this->relationship?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'national_id'   => $this->national_id,
            'gender'        => $this->gender,
            'is_covered'    => (bool) $this->is_covered,
        ];
    }
}
