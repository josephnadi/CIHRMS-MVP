<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DataSubjectRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                     => $this->id,
            'reference'              => $this->reference,
            'subject' => $this->whenLoaded('subject', fn () => [
                'id'   => $this->subject?->id,
                'name' => $this->subject?->name,
            ]),
            'request_type'           => $this->request_type?->value,
            'request_type_label'     => $this->request_type?->label(),
            'status'                 => $this->status?->value,
            'status_label'           => $this->status?->label(),
            'subject_statement'      => $this->subject_statement,
            'rectification_details'  => $this->rectification_details,
            'objection_purpose'      => $this->objection_purpose,
            'submitted_at'           => optional($this->submitted_at)->toIso8601String(),
            'target_completion_date' => optional($this->target_completion_date)->toDateString(),
            'days_remaining'         => $this->daysRemaining(),
            'is_overdue'             => $this->isOverdue(),
            'acknowledged_at'        => optional($this->acknowledged_at)->toIso8601String(),
            'completed_at'           => optional($this->completed_at)->toIso8601String(),
            'assignee'               => $this->whenLoaded('assignee', fn () => $this->assignee?->only(['id', 'name'])),
            'decision_summary'       => $this->decision_summary,
            'rejection_basis'        => $this->rejection_basis,
            'has_export'             => $this->export_path !== null,
            'export_sha256'          => $this->export_sha256,
            'export_generated_at'    => optional($this->export_generated_at)->toIso8601String(),
            'tombstone_log'          => $this->tombstone_log,
            'audit_trail'            => $this->audit_trail,

            'can' => [
                'fulfill'  => $request->user()?->can('fulfill', $this->resource),
                'withdraw' => $request->user()?->can('withdraw', $this->resource),
                'download' => $request->user()?->can('downloadExport', $this->resource),
            ],
        ];
    }
}
