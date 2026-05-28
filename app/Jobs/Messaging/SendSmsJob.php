<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Enums\SmsStatus;
use App\Models\SmsMessage;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Drives a single SmsMessage row through its provider call. Created by
 * SmsDispatcher::send() (async path) and re-dispatched by the sweep.
 *
 * Retry policy: 3 attempts with exponential backoff (60s, 5m, 15m). Permanent
 * failures (bad input, auth) short-circuit without throwing so they don't
 * waste retries; transient failures (5xx, network) throw so the queue
 * runner re-enqueues with backoff.
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Backoff in seconds for each retry attempt. */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public readonly int $messageId) {}

    public function handle(SmsDispatcher $dispatcher): void
    {
        $message = SmsMessage::find($this->messageId);
        if (! $message) {
            Log::info('SendSmsJob skipped — SmsMessage row missing', ['id' => $this->messageId]);
            return;
        }

        // Idempotency: another worker (or a previous run of this same job)
        // already moved the row past Queued. Don't redo the send.
        if ($message->status !== SmsStatus::Queued) {
            return;
        }

        $dispatcher->deliver($message);

        $message->refresh();
        if ($message->status === SmsStatus::Queued) {
            // Provider returned transient failure — dispatcher marked it back
            // to Queued and recorded the reason; throw to trigger queue retry.
            throw new \RuntimeException($message->failure_reason ?? 'transient SMS failure');
        }
    }
}
