<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WhistleblowerReportResource extends JsonResource
{
    public function toArray($request): array
    {
        // Investigator-facing payload. The submitter-side tracking response uses
        // a different (heavily redacted) shape; see TrackingStatusResource.
        return [
            'id'              => $this->id,
            'case_number'     => $this->case_number,
            'category'        => $this->category?->value,
            'category_label'  => $this->category?->label(),
            'severity'        => $this->severity?->value,
            'severity_label'  => $this->severity?->label(),
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'is_anonymous'    => (bool) $this->is_anonymous,
            'subject_summary' => $this->subject_summary,
            'incident_date'   => optional($this->incident_date)->toDateString(),

            // Encrypted fields decrypt automatically on read.
            'description'        => $this->description,
            'desired_outcome'    => $this->desired_outcome,
            'incident_location'  => $this->incident_location,
            // Contact disclosed only if the submitter opted out of anonymity.
            'submitter_contact'  => $this->is_anonymous ? null : $this->submitter_contact,
            'closure_summary'    => $this->closure_summary,

            'investigator'    => $this->whenLoaded('investigator', fn () => $this->investigator?->only(['id', 'name'])),
            'triaged_at'      => optional($this->triaged_at)->toIso8601String(),
            'closed_at'       => optional($this->closed_at)->toIso8601String(),
            'received_at'     => optional($this->received_at)->toIso8601String(),
            'intake_source'   => $this->intake_source,

            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->map(fn ($s) => [
                'id'             => $s->id,
                'subject_label'  => $s->subject_label,
                'role_context'   => $s->role_context,
                'linked_employee'=> $s->linkedEmployee?->only(['id', 'employee_no']),
            ])),

            'evidence' => $this->whenLoaded('evidence', fn () => $this->evidence->map(fn ($e) => [
                'id'              => $e->id,
                'original_filename' => $e->original_filename,
                'mime_type'       => $e->mime_type,
                'size_bytes'      => $e->size_bytes,
                'caption'         => $e->caption,
                'uploaded_at'     => $e->created_at?->toIso8601String(),
            ])),

            'actions' => $this->whenLoaded('actions', fn () => $this->actions->map(fn ($a) => [
                'id'             => $a->id,
                'action_type'    => $a->action_type?->value,
                'action_label'   => $a->action_type?->label(),
                'investigator'   => $a->investigator?->only(['id', 'name']),
                'notes'          => $a->notes,
                'meta'           => $a->meta,
                'occurred_at'    => $a->occurred_at?->toIso8601String(),
            ])),

            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id'        => $m->id,
                'direction' => $m->direction,
                'body'      => $m->body,
                'posted_by' => $m->poster?->name,    // null when submitter posted
                'posted_at' => $m->posted_at?->toIso8601String(),
            ])),
        ];
    }
}
