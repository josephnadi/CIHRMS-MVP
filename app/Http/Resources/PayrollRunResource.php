<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'reference'       => $this->reference,
            'period_year'     => $this->period_year,
            'period_month'    => $this->period_month,
            'period_label'    => $this->periodLabel(),
            'period_start'    => $this->period_start?->toDateString(),
            'period_end'      => $this->period_end?->toDateString(),
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'department'      => $this->whenLoaded('department', fn () => [
                'id'   => $this->department?->id,
                'name' => $this->department?->name,
            ]),
            'creator'         => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'approver'        => $this->whenLoaded('approver', fn () => $this->approver?->only(['id', 'name'])),
            'reverser'        => $this->whenLoaded('reverser', fn () => $this->reverser?->only(['id', 'name'])),
            'reason'          => $this->reason,
            'lines_count'     => (int) $this->lines_count,
            'skipped_count'   => (int) $this->skipped_count,
            'totals'          => [
                'gross'           => (float) $this->gross_total,
                'net'             => (float) $this->net_total,
                'paye'            => (float) $this->paye_total,
                'ssnit_employee'  => (float) $this->ssnit_tier1_employee_total,
                'ssnit_employer'  => (float) $this->ssnit_tier1_employer_total,
                'nhia'            => (float) $this->nhia_total,
                'tier2'           => (float) $this->tier2_employer_total,
                'tier3'           => (float) $this->tier3_total,
                'voluntary'       => (float) $this->voluntary_deductions_total,
            ],
            'locked_at'       => optional($this->locked_at)->toIso8601String(),
            'approved_at'     => optional($this->approved_at)->toIso8601String(),
            'paid_at'         => optional($this->paid_at)->toIso8601String(),
            'reversed_at'     => optional($this->reversed_at)->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),

            'can' => [
                'approve'  => $request->user()?->can('approve', $this->resource),
                'reverse'  => $request->user()?->can('reverse', $this->resource),
                'disburse' => $request->user()?->hasPermission('payroll.disburse') === true,
            ],
        ];
    }
}
