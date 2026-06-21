<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            // Authorized download URL (private disk); null when no CV uploaded.
            'cv_url'     => $this->cv_path ? route('applicants.cv', $this->resource) : null,
            'status'     => $this->status?->value,
            'status_label' => $this->status?->label(),
            'job_posting' => $this->whenLoaded('jobPosting', fn () => [
                'id'    => $this->jobPosting->id,
                'title' => $this->jobPosting->title,
            ]),
            // E-sign envelope tracking (Wave 11)
            'esign_provider'     => $this->esign_provider,
            'esign_envelope_id'  => $this->esign_envelope_id,
            'esign_status'       => $this->esign_status,
            'esign_sent_at'      => $this->esign_sent_at?->toISOString(),
            'esign_completed_at' => $this->esign_completed_at?->toISOString(),
            'created_at'         => $this->created_at?->toISOString(),
        ];
    }
}
