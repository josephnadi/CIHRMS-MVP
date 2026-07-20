<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HubtelWebhookEvent;
use App\Services\Finance\HubtelWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessHubtelWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public readonly int $eventId)
    {
    }

    public function handle(HubtelWebhookProcessor $processor): void
    {
        $event = HubtelWebhookEvent::find($this->eventId);
        if (! $event) {
            return;
        }
        $processor->process($event);
    }
}
