<?php

namespace App\Integrations\DTO;

final class ContactDto
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly ?string $externalId,
        public readonly string $firstName,
        public readonly ?string $lastName = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $jobTitle = null,
        public readonly ?string $company = null,
        public readonly array $extra = [],
    ) {}

    public static function fromEmployee(\App\Models\Employee $employee): self
    {
        $user = $employee->user;
        $name = explode(' ', (string) ($user?->name ?? ''), 2);

        return new self(
            externalId: $employee->external_crm_id ?? null,
            firstName:  $name[0] ?? 'Unknown',
            lastName:   $name[1] ?? null,
            email:      $user?->email,
            phone:      $employee->phone ?? null,
            jobTitle:   $employee->position ?? null,
            company:    'CIHRM Ghana',
            extra: [
                'employee_no' => $employee->employee_no,
                'department'  => $employee->department?->name,
            ],
        );
    }

    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'first_name'  => $this->firstName,
            'last_name'   => $this->lastName,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'job_title'   => $this->jobTitle,
            'company'     => $this->company,
            'extra'       => $this->extra,
        ];
    }
}
