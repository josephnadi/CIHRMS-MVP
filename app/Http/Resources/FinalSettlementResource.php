<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FinalSettlementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                     => $this->id,
            'status'                 => $this->status?->value,
            'status_label'           => $this->status?->label(),
            'basic_salary'           => (float) $this->basic_salary,
            'years_of_service'       => (float) $this->years_of_service,
            'accrued_leave_days'     => (float) $this->accrued_leave_days,
            'working_days_per_month' => (float) $this->working_days_per_month,

            'earnings' => [
                'gratuity'             => (float) $this->gratuity,
                'severance'            => (float) $this->severance,
                'leave_encashment'     => (float) $this->leave_encashment,
                'prorated_13th_month'  => (float) $this->prorated_13th_month,
                'ex_gratia'            => (float) $this->ex_gratia,
                'gross_settlement'     => (float) $this->gross_settlement,
            ],
            'deductions' => [
                'outstanding_loans'  => (float) $this->outstanding_loans,
                'garnishments'       => (float) $this->garnishments,
                'other_deductions'   => (float) $this->other_deductions,
                'paye_on_settlement' => (float) $this->paye_on_settlement,
                'total_deductions'   => (float) $this->total_deductions,
            ],
            'net_payable' => (float) $this->net_payable,

            'calculated_at' => optional($this->calculated_at)->toIso8601String(),
            'approved_at'   => optional($this->approved_at)->toIso8601String(),
            'paid_at'       => optional($this->paid_at)->toIso8601String(),
            'breakdown'     => $this->breakdown,
        ];
    }
}
