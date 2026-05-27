<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReceiptProcessed;
use App\Models\Member;
use App\Notifications\PaymentReceived;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Bridges `ReceiptProcessed` → `PaymentReceived` notification +
 * Hubtel SMS (M2 audit-trail mode). The mail channel is sent via
 * Laravel's notification routing; the SMS leg is a separate dispatcher
 * call because the SMS infrastructure pre-dates the Channel interface
 * in this codebase.
 *
 * Queued so the webhook handler returns 200 immediately to Paystack —
 * the SMS provider call can take a couple of seconds.
 */
class SendPaymentReceiptNotification implements ShouldQueue
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function handle(ReceiptProcessed $event): void
    {
        // Eager-load the relations we touch. `Model::preventLazyLoading` is on
        // outside production, so a bare $receipt->allocations would throw.
        $receipt = $event->receipt->loadMissing([
            'customer.member',
            'allocations.invoice',
        ]);

        $customer = $receipt->customer;
        if ($customer === null) {
            return;
        }

        /** @var Member|null $member */
        $member = $customer->member;
        if ($member === null) {
            // Receipt against a commercial Customer with no linked Member;
            // nothing to notify on the M2 surface. Future iteration may
            // route to a Customer-side contact list.
            return;
        }

        $invoices = $receipt->allocations->map(fn ($a) => $a->invoice)->filter()->values()->all();
        $notification = new PaymentReceived($receipt, $invoices);

        // Mail leg (Laravel notification routing).
        try {
            $member->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('[billing] payment-receipt mail notify failed', [
                'receipt'  => $receipt->reference,
                'member'   => $member->member_no,
                'error'    => $e->getMessage(),
            ]);
        }

        // SMS leg — separate from the notification routing because the SMS
        // layer here logs to `sms_messages` and tracks delivery state.
        if (!empty($member->phone)) {
            try {
                $this->sms->send(
                    toPhone:     $member->phone,
                    body:        $notification->toSmsBody(),
                    contextType: 'ar_receipt',
                    contextId:   $receipt->id,
                );
            } catch (\Throwable $e) {
                Log::warning('[billing] payment-receipt SMS failed', [
                    'receipt' => $receipt->reference,
                    'member'  => $member->member_no,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
