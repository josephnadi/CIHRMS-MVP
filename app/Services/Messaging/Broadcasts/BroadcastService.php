<?php

declare(strict_types=1);

namespace App\Services\Messaging\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Models\Broadcast;

class BroadcastService
{
    /**
     * Persist + queue or schedule a Broadcast. If scheduled_at is in the
     * future, the row stays at Scheduled and the messaging:fire-due-broadcasts
     * scheduler picks it up. Otherwise DispatchBroadcastJob fires now.
     */
    public function queue(Broadcast $broadcast): void
    {
        if ($broadcast->scheduled_at && $broadcast->scheduled_at->isFuture()) {
            $broadcast->update(['status' => BroadcastStatus::Scheduled->value]);
            return;
        }

        $broadcast->update(['status' => BroadcastStatus::Queued->value]);
        DispatchBroadcastJob::dispatch($broadcast->id);
    }

    /**
     * Cancel a Scheduled or Queued broadcast. Errors if the broadcast is
     * already terminal or in-flight.
     */
    public function cancel(Broadcast $broadcast): void
    {
        if (! in_array($broadcast->status, [BroadcastStatus::Scheduled, BroadcastStatus::Queued], true)) {
            throw new \DomainException("Cannot cancel broadcast in status {$broadcast->status->value}.");
        }
        $broadcast->update(['status' => BroadcastStatus::Cancelled->value]);
    }
}
