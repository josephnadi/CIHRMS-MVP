<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LoanAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'reference'           => $this->reference,
            'status'              => $this->status?->value,
            'status_label'        => $this->status?->label(),
            'employee' => [
                'id'          => $this->employee?->id,
                'employee_no' => $this->employee?->employee_no,
                'name'        => $this->employee?->user?->name,
            ],
            'product'             => $this->whenLoaded('product', fn () => new LoanProductResource($this->product)),
            'principal'           => (float) $this->principal,
            'term_months'         => (int) $this->term_months,
            'monthly_installment' => (float) $this->monthly_installment,
            'total_interest'      => (float) $this->total_interest,
            'total_repayable'     => (float) $this->total_repayable,
            'outstanding_balance' => (float) $this->outstanding_balance,
            'installments_paid'   => (int) $this->installments_paid,
            'progress'            => $this->progress(),
            'booked_interest_rate'=> (float) $this->booked_interest_rate,
            'amortization_method' => $this->booked_amortization_method?->value,
            'purpose'             => $this->purpose,
            'applied_at'          => optional($this->applied_at)->toIso8601String(),
            'approved_at'         => optional($this->approved_at)->toIso8601String(),
            'disbursed_at'        => optional($this->disbursed_at)->toIso8601String(),
            'rejection_reason'    => $this->rejection_reason,
            'applicant'           => $this->applicant?->only(['id', 'name']),
            'approver'            => $this->approver?->only(['id', 'name']),

            'can' => [
                'approve'  => $request->user()?->can('approve',  $this->resource),
                'disburse' => $request->user()?->can('disburse', $this->resource),
            ],
        ];
    }
}
