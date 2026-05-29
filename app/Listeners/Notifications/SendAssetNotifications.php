<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Events\AssetAssigned;
use App\Events\AssetReturned;
use App\Models\User;
use App\Notifications\AssetAssignedNotification;
use App\Notifications\AssetReturnedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class SendAssetNotifications implements ShouldQueue
{
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof AssetAssigned => $this->onAssigned($event),
            $event instanceof AssetReturned => $this->onReturned($event),
            default                         => null,
        };
    }

    private function onAssigned(AssetAssigned $event): void
    {
        $assignee = $event->assignment->employee?->user;
        if (! $assignee) return;
        $assignee->notify(new AssetAssignedNotification($event->assignment));
    }

    private function onReturned(AssetReturned $event): void
    {
        $assignee   = $event->assignment->employee?->user;
        $itManagers = $this->holders('assets.manage');
        $recipients = collect(array_filter([$assignee]))->concat($itManagers)->unique('id');
        $notification = new AssetReturnedNotification($event->assignment);
        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function holders(string $perm): Collection
    {
        return User::whereJsonContains('permissions', $perm)->get();
    }
}
