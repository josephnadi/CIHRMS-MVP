<?php

namespace App\Services\Messaging\Sms;

use App\Enums\SmsStatus;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Messaging\Sms\Contracts\SmsProvider;
use Illuminate\Support\Facades\DB;

/**
 * Persists outbound SMS to `sms_messages`, dispatches via the active provider,
 * and updates the row with status + provider response. Each call is one
 * SQL transaction — failed provider attempts still leave a `failed` row for
 * the retry sweep + audit.
 *
 * Usage:
 *   $dispatcher->send('+233200000099', 'Your payslip for May is ready.');
 *   $dispatcher->send($phone, $body, contextType: 'payroll', contextId: $run->id);
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

        $result = $this->provider->send($toPhone, $body, $fromSender);

        DB::transaction(function () use ($message, $result) {
            $message->update([
                'status'              => $result->success ? SmsStatus::Sent->value : SmsStatus::Failed->value,
                'provider_message_id' => $result->providerMessageId,
                'segments'            => $result->segments,
                'cost'                => $result->cost,
                'failure_reason'      => $result->failureReason,
                'sent_at'             => $result->success ? now() : null,
            ]);
        });

        return $message->fresh();
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
