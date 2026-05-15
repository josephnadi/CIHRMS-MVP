<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'employee_id'      => $this->employee_id,
            'course_id'        => $this->course_id,
            'name'             => $this->name,
            'issuer'           => $this->issuer,
            'credential_id'    => $this->credential_id,
            'issued_at'        => $this->issued_at?->toDateString(),
            'expires_at'       => $this->expires_at?->toDateString(),
            'days_to_expiry'   => $this->days_to_expiry,
            'document_path'    => $this->document_path,
            'verification_url' => $this->verification_url,
            'employee'         => $this->whenLoaded('employee', fn () => [
                'id'   => $this->employee->id,
                'name' => $this->employee->user?->name,
            ]),
            'course'           => $this->whenLoaded('course', fn () => $this->course ? [
                'id'    => $this->course->id,
                'title' => $this->course->title,
            ] : null),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
