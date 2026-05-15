<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PayrollLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'employee' => [
                'id'          => $this->employee?->id,
                'employee_no' => $this->employee?->employee_no,
                'name'        => $this->employee?->user?->name,
                'department'  => $this->employee?->department?->name,
            ],
            'grade_code'          => $this->whenLoaded('grade', fn () => $this->grade?->code),
            'step'                => $this->step,
            'basic'               => (float) $this->basic,
            'allowance_total'     => (float) $this->allowance_total,
            'gross'               => (float) $this->gross,
            'ssnit_employee'      => (float) $this->ssnit_tier1_employee,
            'ssnit_employer'      => (float) $this->ssnit_tier1_employer,
            'nhia_split'          => (float) $this->nhia_split,
            'tier2'               => (float) $this->tier2_employer,
            'paye'                => (float) $this->paye,
            'voluntary'           => (float) $this->voluntary_deductions,
            'net'                 => (float) $this->net,
            'status'              => $this->status,
            'skip_reason'         => $this->skip_reason,
            'breakdown'           => $this->breakdown,
        ];
    }
}
