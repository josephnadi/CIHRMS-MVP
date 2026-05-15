<?php

namespace App\Integrations\Contracts;

use App\Integrations\DTO\CalendarEventDto;

interface CalendarProvider extends IntegrationProvider
{
    public function createEvent(CalendarEventDto $event): string;

    public function updateEvent(string $eventId, CalendarEventDto $event): void;

    public function deleteEvent(string $eventId): bool;

    /** @return array<int, CalendarEventDto> */
    public function listEvents(\DateTimeInterface $from, \DateTimeInterface $to): array;
}
