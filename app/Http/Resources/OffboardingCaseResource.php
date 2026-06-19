<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OffboardingCaseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'reference'    => $this->reference,
            'employee'     => [
                'id'          => $this->employee?->id,
                'employee_no' => $this->employee?->employee_no,
                'name'        => $this->employee?->user?->name,
                'department'  => $this->employee?->department?->name,
            ],
            'exit_type'         => $this->exit_type?->value,
            'exit_type_label'   => $this->exit_type?->label(),
            'status'            => $this->status?->value,
            'status_label'      => $this->status?->label(),
            'notice_received_on'           => optional($this->notice_received_on)->toDateString(),
            'last_working_day'             => optional($this->last_working_day)->toDateString(),
            'effective_termination_date'   => optional($this->effective_termination_date)->toDateString(),
            'rehire_eligible'   => (bool) $this->rehire_eligible,
            'reason'            => $this->reason,
            'exit_interview_summary' => $this->exit_interview_summary,
            'initiator'         => $this->whenLoaded('initiator', fn () => $this->initiator?->only(['id', 'name'])),
            'completed_at'      => optional($this->completed_at)->toIso8601String(),
            'clearance_progress'=> $this->clearanceProgress(),
            'clearance_complete'=> $this->isClearanceComplete(),

            'settlement' => $this->whenLoaded('settlement', fn () => $this->settlement ? [
                'id'             => $this->settlement->id,
                'status'         => $this->settlement->status?->value,
                'status_label'   => $this->settlement->status?->label(),
                'gross_settlement'=> (float) $this->settlement->gross_settlement,
                'total_deductions'=> (float) $this->settlement->total_deductions,
                'net_payable'    => (float) $this->settlement->net_payable,
                'calculated_at'  => optional($this->settlement->calculated_at)->toIso8601String(),
                'approved_at'    => optional($this->settlement->approved_at)->toIso8601String(),
            ] : null),

            'settlement_payout' => $this->settlementPayout(),

            'can' => [
                'clear'           => $request->user()?->can('clear', $this->resource),
                'settle'          => $request->user()?->can('calculateSettlement', $this->resource),
                'approve_settle'  => $request->user()?->can('approveSettlement', $this->resource),
                'pay_settle'      => $request->user()?->can('paySettlement', $this->resource),
                'reverse_settle'  => $request->user()?->can('reverseSettlement', $this->resource),
                'complete'        => $request->user()?->can('complete', $this->resource),
                'dispatch_payout' => $request->user()?->can('dispatchPayout', $this->resource),
            ],
        ];
    }

    /**
     * Latest disbursement linked to this case's settlement (additive payout
     * tracking). Returns plain scalars — enum casts are unwrapped to their
     * string values so Vue receives 'pending'/'sent'/'settled'/'failed'.
     */
    private function settlementPayout(): ?array
    {
        $settlementId = $this->settlement?->id;
        if (! $settlementId) {
            return null;
        }

        $d = \App\Models\Disbursement::where('final_settlement_id', $settlementId)
            ->latest('id')
            ->first();

        if (! $d) {
            return null;
        }

        return [
            'id'                 => $d->id,
            'channel'            => $d->channel?->value,
            'status'             => $d->status?->value,
            'gross_amount'       => (float) $d->gross_amount,
            'net_to_recipient'   => (float) $d->net_to_recipient,
            'provider_reference' => $d->provider_reference,
            'failure_reason'     => $d->failure_reason,
        ];
    }
}
