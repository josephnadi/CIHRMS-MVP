<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stub: full implementation in Task 6. Exists now so BroadcastService
 * tests can reference it via Bus::fake([DispatchBroadcastJob::class]).
 */
class DispatchBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $broadcastId) {}

    public function handle(): void
    {
        // Implemented in Task 6.
    }
}
