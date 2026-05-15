<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BenefitClaimResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'enrolment_id'    => $this->enrolment_id,
            'enrolment'       => $this->whenLoaded('enrolment', fn () => $this->enrolment ? [
                'id'        => $this->enrolment->id,
                'plan_name' => $this->enrolment->plan?->name,
                'employee'  => [
                    'id'          => $this->enrolment->employee?->id,
                    'employee_no' => $this->enrolment->employee?->employee_no,
                    'name'        => $this->enrolment->employee?->user?->name,
                ],
            ] : null),
            'claim_reference' => $this->claim_reference,
            'amount'          => (float) $this->amount,
            'currency'        => $this->currency,
            'claim_date'      => $this->claim_date?->toDateString(),
            'description'     => $this->description,
            'status'          => $this->status?->value,
            'submitted_at'    => $this->submitted_at?->toIso8601String(),
            'decision_at'     => $this->decision_at?->toIso8601String(),
            'decision_notes'  => $this->decision_notes,
            'decided_by'      => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
        ];
    }
}
