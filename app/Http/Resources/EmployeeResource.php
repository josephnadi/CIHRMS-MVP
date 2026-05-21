<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeSalary = $user?->can('viewSalary', $this->resource) ?? false;

        return [
            'id'           => $this->id,
            'employee_no'  => $this->employee_no,
            'position'     => $this->position,
            'hire_date'    => $this->hire_date?->toDateString(),
            'tenure_years' => $this->tenureYears,
            'phone'        => $this->phone,
            'status'       => $this->status?->value,
            'status_label' => $this->status?->label(),
            'avatar_url'   => $this->avatarUrl,

            // Personal
            'gender'        => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'national_id'   => $this->national_id,
            'address'       => $this->address,

            // Emergency
            'emergency_contact_name'         => $this->emergency_contact_name,
            'emergency_contact_phone'        => $this->emergency_contact_phone,
            'emergency_contact_relationship' => $this->emergency_contact_relationship,

            // Compensation (gated on salary)
            'bank_name'    => $this->bank_name,
            'bank_account' => $this->bank_account,
            'salary'       => $this->when($canSeeSalary, fn () => $this->salary),

            // Relations
            'department'   => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id'          => $this->manager->id,
                'employee_no' => $this->manager->employee_no,
                'name'        => $this->manager->user?->name,
                'position'    => $this->manager->position,
            ] : null),
            'reports' => $this->whenLoaded('reports', fn () => $this->reports->map(fn ($r) => [
                'id'          => $r->id,
                'employee_no' => $r->employee_no,
                'name'        => $r->user?->name,
                'position'    => $r->position,
            ])),
            'documents' => $this->whenLoaded('documents'),
            'skills'    => $this->whenLoaded('skills', fn () => $this->skills->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'level'      => $s->level,
                'expires_at' => $s->expires_at?->toDateString(),
            ])),
            'leave_requests' => $this->whenLoaded('leaveRequests', fn () => $this->leaveRequests->map(fn ($lr) => [
                'id'         => $lr->id,
                'type'       => $lr->type?->value,
                'status'     => $lr->status?->value,
                'start_date' => $lr->start_date?->toDateString(),
                'end_date'   => $lr->end_date?->toDateString(),
            ])),
            'tickets' => $this->whenLoaded('tickets', fn () => $this->tickets->map(fn ($t) => [
                'id'       => $t->id,
                'title'    => $t->title,
                'status'   => $t->status?->value,
                'priority' => $t->priority?->value,
                'created_at' => $t->created_at?->toISOString(),
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id'          => $p->id,
                'description' => $p->description,
                'amount'      => $p->amount,
                'currency'    => $p->currency,
                'status'      => $p->status?->value,
                'paid_at'     => $p->paid_at?->toISOString(),
            ])),
            'benefit_enrolments' => $this->whenLoaded('benefitEnrolments', fn () => $this->benefitEnrolments->map(fn ($e) => [
                'id'              => $e->id,
                'status'          => is_object($e->status) ? $e->status->value : $e->status,
                'enrolled_at'     => $e->enrolled_at?->toDateString(),
                'effective_from'  => $e->effective_from?->toDateString(),
                'effective_to'    => $e->effective_to?->toDateString(),
                'monthly_premium' => $e->monthly_premium,
                'plan'            => $e->plan ? [
                    'id'           => $e->plan->id,
                    'name'         => $e->plan->name,
                    'code'         => $e->plan->code,
                    'type'         => $e->plan->type,
                    'provider'     => $e->plan->provider,
                    'monthly_cost' => $e->plan->monthly_cost,
                ] : null,
            ])),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
