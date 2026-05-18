<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'category'        => $this->category?->value,
            'category_label'  => $this->category?->label(),
            'title'           => $this->title,
            'body'            => $this->body,
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'closed_at'       => $this->closed_at?->toISOString(),
            'resolution_note' => $this->resolution_note,
            'created_at'      => $this->created_at?->toISOString(),
            'submitter'       => $this->whenLoaded('employee', fn () => [
                'id'   => $this->employee->id,
                'name' => $this->employee->user?->name,
            ]),
            'assignees'       => $this->whenLoaded('currentAssignees', fn () => $this->currentAssignees->map(fn ($u) => [
                'id'   => $u->id,
                'name' => $u->name,
                'role' => $u->role,
            ])->values()),
            'messages'        => IncidentReportMessageResource::collection($this->whenLoaded('messages')),
            'attachments'     => IncidentReportAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
