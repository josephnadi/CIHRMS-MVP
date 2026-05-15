<?php

namespace App\Integrations\Drivers\Microsoft;

use App\Integrations\Contracts\CalendarProvider;
use App\Integrations\DTO\CalendarEventDto;

/**
 * Outlook calendar via Microsoft Graph /me/events.
 * Used by the Leave module to drop "blocked" events on the requester's calendar
 * once leave is approved.
 */
class MsGraphCalendarDriver extends MsGraphBaseDriver implements CalendarProvider
{
    public function capability(): string
    {
        return 'calendar';
    }

    public function createEvent(CalendarEventDto $event): string
    {
        return $this->track('calendar.create', ['title' => $event->title], function () use ($event) {
            $response = $this->post('/me/events', $this->toGraphPayload($event));
            return (string) $response->json('id');
        });
    }

    public function updateEvent(string $eventId, CalendarEventDto $event): void
    {
        $this->track('calendar.update', ['id' => $eventId], function () use ($eventId, $event) {
            $this->patch("/me/events/{$eventId}", $this->toGraphPayload($event));
        });
    }

    public function deleteEvent(string $eventId): bool
    {
        return $this->track('calendar.delete', ['id' => $eventId], function () use ($eventId) {
            $this->deleteRequest("/me/events/{$eventId}");
            return true;
        });
    }

    /** @return array<int, CalendarEventDto> */
    public function listEvents(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->track('calendar.list', ['from' => $from->format('c'), 'to' => $to->format('c')], function () use ($from, $to) {
            $response = $this->get('/me/calendarView', [
                'startDateTime' => $from->format('c'),
                'endDateTime'   => $to->format('c'),
                '$top'          => 100,
            ]);
            return array_map(fn ($e) => $this->fromGraphPayload($e), (array) data_get($response->json(), 'value', []));
        });
    }

    protected function toGraphPayload(CalendarEventDto $event): array
    {
        return [
            'subject'  => $event->title,
            'body'     => ['contentType' => 'HTML', 'content' => $event->description ?? ''],
            'start'    => ['dateTime' => $event->startsAt->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end'      => ['dateTime' => $event->endsAt->format('Y-m-d\TH:i:s'),   'timeZone' => 'UTC'],
            'location' => $event->location ? ['displayName' => $event->location] : null,
            'isAllDay' => $event->allDay,
            'attendees'=> array_map(fn ($email) => [
                'emailAddress' => ['address' => $email],
                'type'         => 'required',
            ], $event->attendees),
        ];
    }

    protected function fromGraphPayload(array $raw): CalendarEventDto
    {
        return new CalendarEventDto(
            title:       (string) ($raw['subject'] ?? ''),
            startsAt:    new \DateTimeImmutable((string) data_get($raw, 'start.dateTime', 'now'), new \DateTimeZone((string) data_get($raw, 'start.timeZone', 'UTC'))),
            endsAt:      new \DateTimeImmutable((string) data_get($raw, 'end.dateTime', 'now'),   new \DateTimeZone((string) data_get($raw, 'end.timeZone', 'UTC'))),
            description: (string) data_get($raw, 'bodyPreview'),
            location:    (string) data_get($raw, 'location.displayName') ?: null,
            attendees:   array_map(fn ($a) => (string) data_get($a, 'emailAddress.address'), (array) ($raw['attendees'] ?? [])),
            allDay:      (bool) ($raw['isAllDay'] ?? false),
            externalId:  (string) ($raw['id'] ?? ''),
        );
    }
}
