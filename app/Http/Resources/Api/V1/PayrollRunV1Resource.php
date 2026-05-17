<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunV1Resource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'reference'    => $this->reference,
            'period'       => sprintf('%04d-%02d', $this->period_year, $this->period_month),
            'period_start' => optional($this->period_start)->toDateString(),
            'period_end'   => optional($this->period_end)->toDateString(),
            'status'       => $this->status?->value,
            'department'   => $this->whenLoaded('department', fn () => $this->department?->only(['id', 'name'])),
            'totals' => [
                'gross'          => (float) $this->gross_total,
                'net'            => (float) $this->net_total,
                'paye'           => (float) $this->paye_total,
                'ssnit_employee' => (float) $this->ssnit_tier1_employee_total,
                'ssnit_employer' => (float) $this->ssnit_tier1_employer_total,
                'nhia'           => (float) $this->nhia_total,
                'tier2'          => (float) $this->tier2_employer_total,
            ],
            'lines_count'  => (int) $this->lines_count,
            'skipped_count'=> (int) $this->skipped_count,
            'approved_at'  => optional($this->approved_at)->toIso8601String(),
            'paid_at'      => optional($this->paid_at)->toIso8601String(),
        ];
    }
}
