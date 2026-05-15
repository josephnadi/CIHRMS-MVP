<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'code'                  => $this->code,
            'description'           => $this->description,
            'employee_count'        => $this->whenCounted('employees'),
            'active_employee_count' => $this->when(
                isset($this->active_employees_count),
                fn () => $this->active_employees_count,
            ),
            'created_at'            => $this->created_at?->toISOString(),
        ];
    }
}
