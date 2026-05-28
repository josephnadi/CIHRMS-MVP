<?php

namespace App\Services\Messaging\Sms;

use App\Enums\SmsStatus;
use App\Jobs\Messaging\SendSmsJob;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Messaging\Sms\Contracts\SmsProvider;
use Illuminate\Support\Facades\DB;

/**
 * Persists outbound SMS to `sms_messages`, dispatches via the active provider,
 * and updates the row with status + provider response.
 *
 * Default behaviour: row inserted with status=Queued, SendSmsJob dispatched,
 * row returned immediately. The job runs `deliver()` which talks to the
 * provider synchronously.
 *
 * Pass sync=true to bypass the queue (used in tests and from inside the job
 * itself, where queueing again would be infinite recursion).
 *
 * Usage:
 *   $dispatcher->send('+233200000099', 'Your payslip for May is ready.');
 *   $dispatcher->send($phone, $body, contextType: 'payroll', contextId: $run->id);
 *   $dispatcher->send($phone, $body, sync: true); // tests / inside-job
 */
class SmsDispatcher
{
    public function __construct(private readonly SmsProvider $provider) {}

    public function send(
        string $toPhone,
        string $body,
        ?string $fromSender = null,
        ?string $contextType = null,
        ?int $contextId = null,
        ?User $triggeredBy = null,
        bool $sync = false,
    ): SmsMessage {
        $message = SmsMessage::create([
            'to_phone'     => $toPhone,
            'from_sender'  => $fromSender,
            'body'         => $body,
            'provider'     => $this->provider->name(),
            'status'       => SmsStatus::Queued->value,
            'segments'     => max(1, (int) ceil(mb_strlen($body) / 160)),
            'context_type' => $contextType,
            'context_id'   => $contextId,
            'triggered_by' => $triggeredBy?->id,
        ]);

        if ($sync) {
            $this->deliver($message);
            return $message->fresh();
        }

        SendSmsJob::dispatch($message->id);
        return $message;
    }

    /**
     * Synchronous provider call + row update. Used by SendSmsJob and by
     * the sync=true path of send(). Idempotent: returns early if the row
     * has already moved past Queued.
     *
     * On provider success: row → Sent.
     * On permanent failure: row → Failed (no retry signal).
     * On transient failure: row stays Queued + failure_reason recorded;
     * the caller (SendSmsJob) inspects status and throws to trigger retry.
     */
    public function deliver(SmsMessage $message): void
    {
        if ($message->status !== SmsStatus::Queued) return;

        $result = $this->provider->send($message->to_phone, $message->body, $message->from_sender);

        DB::transaction(function () use ($message, $result) {
            if ($result->success) {
                $message->update([
                    'status'              => SmsStatus::Sent->value,
                    'provider_message_id' => $result->providerMessageId,
                    'segments'            => $result->segments,
                    'cost'                => $result->cost,
                    'failure_reason'      => null,
                    'sent_at'             => now(),
                ]);
            } elseif ($result->retryable) {
                // Transient — leave Queued, record reason. The job will throw
                // and Laravel queue retry will re-run this method.
                $message->update([
                    'status'         => SmsStatus::Queued->value,
                    'failure_reason' => $result->failureReason,
                ]);
            } else {
                $message->update([
                    'status'         => SmsStatus::Failed->value,
                    'failure_reason' => $result->failureReason,
                    'sent_at'        => null,
                ]);
            }
        });
    }

    /** Provider-callback path — flips Sent → Delivered when the network confirms. */
    public function markDelivered(string $providerMessageId): ?SmsMessage
    {
        $message = SmsMessage::where('provider_message_id', $providerMessageId)->first();
        if (! $message) return null;
        $message->update(['status' => SmsStatus::Delivered->value, 'delivered_at' => now()]);
        return $message->fresh();
    }
}
