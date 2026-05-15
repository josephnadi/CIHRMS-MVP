<?php

namespace App\Integrations\DTO;

final class CalendarEventDto
{
    /**
     * @param  array<int, string>  $attendees  email addresses
     */
    public function __construct(
        public readonly string $title,
        public readonly \DateTimeInterface $startsAt,
        public readonly \DateTimeInterface $endsAt,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
        public readonly array $attendees = [],
        public readonly bool $allDay = false,
        public readonly ?string $externalId = null,
    ) {}
}
