<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\PaymentIntentStatus;
use App\Models\PaymentIntent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RefundService
{
    public function __construct(
        private readonly PaystackGatewayService $gateway,
        private readonly ArReceiptService $receipts,
    ) {
    }

    public function refund(PaymentIntent $intent, User $user, string $reason): PaymentIntent
    {
        if ($intent->refunded_at !== null) {
            throw new DomainException("Intent {$intent->reference} is already refunded.");
        }
        if ($intent->status !== PaymentIntentStatus::Success) {
            throw new DomainException(
                "Cannot refund intent {$intent->reference}: status is {$intent->status->value}."
            );
        }
        if ($intent->ar_receipt_id === null) {
            throw new DomainException("Intent {$intent->reference} has no linked AR receipt to reverse.");
        }

        return DB::transaction(function () use ($intent, $user, $reason) {
            $response = $this->gateway->refundTransaction(
                $intent->paystack_reference,
                (float) $intent->amount,
                $reason,
            );

            $this->receipts->void(
                $intent->receipt,
                $user,
                "Paystack refund: {$reason}",
            );

            $intent->update([
                'status'              => PaymentIntentStatus::Refunded->value,
                'refunded_at'         => now(),
                'refund_amount'       => $intent->amount,
                'refund_reason'       => $reason,
                'refund_paystack_ref' => (string) ($response['id'] ?? ''),
                'refunded_by'         => $user->id,
            ]);

            return $intent->fresh('receipt');
        });
    }
}
