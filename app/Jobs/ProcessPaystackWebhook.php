<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PaystackWebhookEvent;
use App\Services\Finance\PaystackWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPaystackWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public readonly int $eventId)
    {
    }

    public function handle(PaystackWebhookProcessor $processor): void
    {
        $event = PaystackWebhookEvent::find($this->eventId);
        if (! $event) {
            return;
        }
        $processor->process($event);
    }
}
