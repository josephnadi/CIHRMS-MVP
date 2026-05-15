<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LoanRepaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'installment_no'    => (int) $this->installment_no,
            'due_period'        => $this->due_period?->format('Y-m'),
            'scheduled_amount'  => (float) $this->scheduled_amount,
            'principal_portion' => (float) $this->principal_portion,
            'interest_portion'  => (float) $this->interest_portion,
            'balance_after'     => (float) $this->balance_after,
            'paid_amount'       => (float) $this->paid_amount,
            'status'            => $this->status?->value,
            'status_label'      => $this->status?->label(),
            'posted_at'         => optional($this->posted_at)->toIso8601String(),
        ];
    }
}
