<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\AttendanceCorrectionDecided;
use App\Events\AttendanceCorrectionRequested;
use App\Models\User;
use App\Notifications\AttendanceCorrectionDecidedNotification;
use App\Notifications\AttendanceCorrectionRequestedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendAttendanceCorrectionNotifications implements ShouldQueue
{
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof AttendanceCorrectionRequested => $this->onRequested($event),
            $event instanceof AttendanceCorrectionDecided   => $this->onDecided($event),
            default                                         => null,
        };
    }

    private function onRequested(AttendanceCorrectionRequested $event): void
    {
        $correction = $event->correction;
        $manager    = $correction->employee?->manager?->user;
        $approvers  = $this->holders('attendance.approve');

        $recipients   = collect(array_filter([$manager]))->concat($approvers)->unique('id');
        $notification = new AttendanceCorrectionRequestedNotification($correction);
        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function onDecided(AttendanceCorrectionDecided $event): void
    {
        $requester = User::find($event->correction->requester_id);
        if (! $requester) return;
        $requester->notify(new AttendanceCorrectionDecidedNotification($event->correction));
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
