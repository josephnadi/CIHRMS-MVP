<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Employee;
use App\Models\Member;
use App\Models\User;
use App\Services\Messaging\Broadcasts\AudienceResolver;
use App\Services\Messaging\Broadcasts\TemplateRenderer;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Drives a Broadcast end-to-end: resolves the audience, chunks 100 at a
 * time, renders body per recipient via TemplateRenderer, fires SMS via the
 * N1 async SmsDispatcher and mail via Mail::raw. Each recipient produces
 * one BroadcastRecipient row; the unique constraint on
 * (broadcast_id, recipient_type, recipient_id) makes the job idempotent
 * on retry.
 */
class DispatchBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $broadcastId) {}

    public function handle(
        AudienceResolver $resolver,
        TemplateRenderer $renderer,
        SmsDispatcher $sms,
    ): void {
        $broadcast = Broadcast::find($this->broadcastId);
        if (! $broadcast) {
            Log::info('DispatchBroadcastJob skipped — broadcast missing', ['id' => $this->broadcastId]);
            return;
        }

        // Idempotency guard: only Queued broadcasts proceed.
        if ($broadcast->status !== BroadcastStatus::Queued) {
            return;
        }

        $broadcast->update([
            'status'     => BroadcastStatus::Sending->value,
            'started_at' => now(),
        ]);

        $type = $broadcast->audience_type;
        $params = $broadcast->audience_params ?? [];
        $channels = $broadcast->channels ?? [];
        $hasSms  = in_array(BroadcastChannel::Sms->value, $channels, true);
        $hasMail = in_array(BroadcastChannel::Mail->value, $channels, true);

        $counts = [
            'recipient_count'     => 0,
            'sms_sent_count'      => 0,
            'sms_failed_count'    => 0,
            'sms_throttled_count' => 0,
            'mail_sent_count'     => 0,
            'mail_failed_count'   => 0,
        ];

        $resolver->resolve($type, $params)
            ->chunkById(100, function ($chunk) use (
                $broadcast, $type, $renderer, $sms, $hasSms, $hasMail, &$counts,
            ) {
                foreach ($chunk as $recipient) {
                    $counts['recipient_count']++;

                    $recipientClass = $type->recipientClass();
                    // Idempotency: skip if BroadcastRecipient row already exists
                    $exists = BroadcastRecipient::where([
                        'broadcast_id'   => $broadcast->id,
                        'recipient_type' => $recipientClass,
                        'recipient_id'   => $recipient->id,
                    ])->exists();
                    if ($exists) continue;

                    $row = [
                        'broadcast_id'   => $broadcast->id,
                        'recipient_type' => $recipientClass,
                        'recipient_id'   => $recipient->id,
                        'created_at'     => now(),
                    ];

                    // SMS leg
                    if ($hasSms) {
                        $phone = $this->phoneOf($recipient);
                        if (! $phone) {
                            $row['sms_status'] = 'Skipped';
                        } elseif (! $broadcast->throttle_overridden
                                  && RateLimiter::tooManyAttempts("sms:marketing:{$phone}", 5)) {
                            $row['sms_status'] = 'Throttled';
                            $counts['sms_throttled_count']++;
                        } else {
                            $body = $renderer->render($broadcast->sms_body ?? '', $recipient, $type);
                            try {
                                $msg = $sms->send(
                                    toPhone:     $phone,
                                    body:        $body,
                                    contextType: 'broadcast',
                                    contextId:   $broadcast->id,
                                );
                                $row['sms_status']     = 'Sent';
                                $row['sms_message_id'] = $msg->id;
                                $counts['sms_sent_count']++;
                                if (! $broadcast->throttle_overridden) {
                                    RateLimiter::hit("sms:marketing:{$phone}", 3600);
                                }
                            } catch (\Throwable $e) {
                                $row['sms_status'] = 'Failed';
                                $counts['sms_failed_count']++;
                            }
                        }
                    }

                    // Mail leg
                    if ($hasMail) {
                        $email = $this->emailOf($recipient);
                        if (! $email) {
                            $row['mail_status'] = 'Skipped';
                        } else {
                            try {
                                $body    = $renderer->render($broadcast->mail_body ?? '', $recipient, $type);
                                $subject = $renderer->render($broadcast->mail_subject ?? '', $recipient, $type);
                                Mail::raw($body, function ($m) use ($email, $subject) {
                                    $m->to($email)->subject($subject);
                                });
                                $row['mail_status'] = 'Sent';
                                $counts['mail_sent_count']++;
                            } catch (\Throwable $e) {
                                $row['mail_status']          = 'Failed';
                                $row['mail_failure_reason']  = $e->getMessage();
                                $counts['mail_failed_count']++;
                            }
                        }
                    }

                    BroadcastRecipient::create($row);
                }
            });

        $broadcast->update($counts + [
            'status'       => BroadcastStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $b = Broadcast::find($this->broadcastId);
        if ($b && ! $b->status->isTerminal()) {
            $b->update(['status' => BroadcastStatus::Failed->value]);
        }
    }

    private function phoneOf(object $recipient): ?string
    {
        if ($recipient instanceof Member)   return $recipient->phone ?: null;
        if ($recipient instanceof Employee) return $recipient->phone ?: null;
        if ($recipient instanceof User)     return $recipient->employee?->phone ?: null;
        return null;
    }

    private function emailOf(object $recipient): ?string
    {
        if ($recipient instanceof Member)   return $recipient->email ?: null;
        if ($recipient instanceof Employee) return $recipient->user?->email ?: null;
        if ($recipient instanceof User)     return $recipient->email ?: null;
        return null;
    }
}
