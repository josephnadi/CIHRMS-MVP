<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BroadcastStatus;
use App\Jobs\Messaging\DispatchBroadcastJob;
use App\Models\Broadcast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Picks up broadcasts in Scheduled status whose scheduled_at <= now(),
 * flips them to Queued, and dispatches DispatchBroadcastJob. Designed to
 * run every minute via the scheduler.
 */
class FireDueBroadcastsCommand extends Command
{
    protected $signature = 'messaging:fire-due-broadcasts';
    protected $description = 'Dispatch any scheduled broadcasts whose scheduled_at has passed.';

    public function handle(): int
    {
        $count = 0;
        Broadcast::due()->get()->each(function (Broadcast $b) use (&$count) {
            $b->update(['status' => BroadcastStatus::Queued->value]);
            DispatchBroadcastJob::dispatch($b->id);
            $count++;
        });

        $this->info("Fired {$count} due broadcast(s).");
        Log::info('messaging:fire-due-broadcasts', ['count' => $count]);
        return self::SUCCESS;
    }
}
