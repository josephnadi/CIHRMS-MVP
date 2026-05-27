<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ArReceipt;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by `PaystackWebhookProcessor` after `ArReceiptService::record()`
 * has posted a successful payment. The listener `SendPaymentReceiptNotification`
 * resolves the Member behind the receipt (via Customer→Member) and
 * dispatches a `PaymentReceived` notification (SMS + mail).
 *
 * Decoupled from the webhook processor so the same event can later be
 * dispatched from non-Paystack code paths (M3 USSD top-ups, manual
 * receipts entered by finance, etc.) without re-implementing the
 * notification logic.
 */
class ReceiptProcessed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ArReceipt $receipt,
        public readonly ?PaymentIntent $intent = null,
    ) {}
}
