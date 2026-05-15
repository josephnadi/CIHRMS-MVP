<?php

namespace App\Integrations\Drivers\Google;

use App\Integrations\Contracts\CalendarProvider;
use App\Integrations\DTO\CalendarEventDto;

/**
 * Google Calendar v3 driver — events on the user's primary calendar.
 */
class GoogleCalendarDriver extends GoogleBaseDriver implements CalendarProvider
{
    private const API = 'https://www.googleapis.com/calendar/v3/';

    public function capability(): string
    {
        return 'calendar';
    }

    public function createEvent(CalendarEventDto $event): string
    {
        return $this->track('calendar.create', ['title' => $event->title], function () use ($event) {
            $response = $this->post(self::API, 'calendars/primary/events', $this->toGooglePayload($event));
            return (string) $response->json('id');
        });
    }

    public function updateEvent(string $eventId, CalendarEventDto $event): void
    {
        $this->track('calendar.update', ['id' => $eventId], function () use ($eventId, $event) {
            $this->patch(self::API, "calendars/primary/events/{$eventId}", $this->toGooglePayload($event));
        });
    }

    public function deleteEvent(string $eventId): bool
    {
        return $this->track('calendar.delete', ['id' => $eventId], function () use ($eventId) {
            $this->deleteRequest(self::API, "calendars/primary/events/{$eventId}");
            return true;
        });
    }

    /** @return array<int, CalendarEventDto> */
    public function listEvents(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->track('calendar.list', ['from' => $from->format('c'), 'to' => $to->format('c')], function () use ($from, $to) {
            $data = (array) $this->http(self::API)
                ->get('calendars/primary/events', [
                    'timeMin'      => $from->format(\DateTimeInterface::RFC3339),
                    'timeMax'      => $to->format(\DateTimeInterface::RFC3339),
                    'singleEvents' => 'true',
                    'orderBy'      => 'startTime',
                    'maxResults'   => 100,
                ])
                ->throw()
                ->json();
            return array_map(fn ($e) => $this->fromGooglePayload($e), (array) ($data['items'] ?? []));
        });
    }

    protected function toGooglePayload(CalendarEventDto $event): array
    {
        $start = $event->allDay
            ? ['date' => $event->startsAt->format('Y-m-d')]
            : ['dateTime' => $event->startsAt->format(\DateTimeInterface::RFC3339)];
        $end = $event->allDay
            ? ['date' => $event->endsAt->format('Y-m-d')]
            : ['dateTime' => $event->endsAt->format(\DateTimeInterface::RFC3339)];

        return array_filter([
            'summary'     => $event->title,
            'description' => $event->description,
            'location'    => $event->location,
            'start'       => $start,
            'end'         => $end,
            'attendees'   => $event->attendees ? array_map(fn ($e) => ['email' => $e], $event->attendees) : null,
        ]);
    }

    protected function fromGooglePayload(array $raw): CalendarEventDto
    {
        $startsAt = isset($raw['start']['dateTime'])
            ? new \DateTimeImmutable($raw['start']['dateTime'])
            : new \DateTimeImmutable($raw['start']['date'] ?? 'now');
        $endsAt = isset($raw['end']['dateTime'])
            ? new \DateTimeImmutable($raw['end']['dateTime'])
            : new \DateTimeImmutable($raw['end']['date'] ?? 'now');

        return new CalendarEventDto(
            title:       (string) ($raw['summary'] ?? ''),
            startsAt:    $startsAt,
            endsAt:      $endsAt,
            description: (string) ($raw['description'] ?? '') ?: null,
            location:    (string) ($raw['location'] ?? '') ?: null,
            attendees:   array_map(fn ($a) => (string) ($a['email'] ?? ''), (array) ($raw['attendees'] ?? [])),
            allDay:      ! isset($raw['start']['dateTime']),
            externalId:  (string) ($raw['id'] ?? ''),
        );
    }
}
