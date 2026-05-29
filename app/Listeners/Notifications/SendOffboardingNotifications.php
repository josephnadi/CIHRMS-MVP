<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\OffboardingCompleted;
use App\Events\OffboardingInitiated;
use App\Models\User;
use App\Notifications\OffboardingCompletedNotification;
use App\Notifications\OffboardingInitiatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendOffboardingNotifications implements ShouldQueue
{
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof OffboardingInitiated => $this->onInitiated($event),
            $event instanceof OffboardingCompleted => $this->onCompleted($event),
            default                                => null,
        };
    }

    private function onInitiated(OffboardingInitiated $event): void
    {
        $case = $event->case;
        $departing = $case->employee?->user;
        $manager   = $case->employee?->manager?->user;
        $hr  = $this->holders('employees.manage');
        $it  = $this->holders('assets.manage');

        $recipients = collect(array_filter([$departing, $manager]))->concat($hr)->concat($it)->unique('id');
        $notification = new OffboardingInitiatedNotification($case);
        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function onCompleted(OffboardingCompleted $event): void
    {
        $notification = new OffboardingCompletedNotification($event->case);
        foreach ($this->holders('employees.manage') as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
