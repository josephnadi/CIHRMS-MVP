<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StatutoryReturnResource extends JsonResource
{
    public function toArray($request): array
    {
        $remit = app(\App\Services\Payroll\RemittanceService::class);

        return [
            'id'             => $this->id,
            'kind'           => $this->kind?->value,
            'kind_label'     => $this->kind?->label(),
            'trustee'        => $this->whenLoaded('trustee', fn () => $this->trustee?->only(['id', 'name'])),
            'file_path'      => $this->file_path,
            'total_amount'   => (float) $this->total_amount,
            'record_count'   => (int) $this->record_count,
            'generated_at'   => optional($this->generated_at)->toIso8601String(),
            'submitted_at'   => optional($this->submitted_at)->toIso8601String(),
            'submission_reference' => $this->submission_reference,
            'submitted_by_name'    => $this->submitter?->name,
            'due_date'             => optional($remit->dueDate($this->resource))->toDateString(),
            'status'               => $remit->status($this->resource),
        ];
    }
}
