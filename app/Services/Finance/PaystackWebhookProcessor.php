<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\PaymentIntentStatus;
use App\Models\ArReceipt;
use App\Models\OrgBankAccount;
use App\Models\PaymentIntent;
use App\Models\PaystackWebhookEvent;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaystackWebhookProcessor
{
    public function __construct(
        private readonly PaystackGatewayService $gateway,
        private readonly ArReceiptService $receipts,
    ) {
    }

    public function process(PaystackWebhookEvent $event): ?ArReceipt
    {
        if ($event->processed_at !== null) {
            return $event->receipt;
        }

        try {
            return match ($event->event_type) {
                'charge.success'   => $this->handleChargeSuccess($event),
                'refund.processed' => $this->handleRefundProcessed($event),
                default            => $this->markNoOp($event),
            };
        } catch (Throwable $e) {
            $event->update(['processing_error' => $e->getMessage()]);
            Log::error('Paystack webhook processing failed', [
                'event_id' => $event->id, 'paystack_event_id' => $event->paystack_event_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function handleChargeSuccess(PaystackWebhookEvent $event): ?ArReceipt
    {
        return DB::transaction(function () use ($event) {
            $intent = PaymentIntent::where('paystack_reference', $event->paystack_reference)
                ->lockForUpdate()
                ->first();

            if (! $intent) {
                $event->update([
                    'processed_at'     => now(),
                    'processing_error' => "PaymentIntent for paystack_reference '{$event->paystack_reference}' not found",
                ]);
                return null;
            }

            if ($intent->status === PaymentIntentStatus::Success) {
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'ar_receipt_id'     => $intent->ar_receipt_id,
                ]);
                return $intent->receipt;
            }

            if ($intent->status !== PaymentIntentStatus::Pending) {
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'processing_error'  => "PaymentIntent {$intent->reference} status is {$intent->status->value}; cannot post receipt.",
                ]);
                return null;
            }

            $verified = $this->gateway->verifyTransaction($event->paystack_reference);

            $expectedPesewas = (int) round((float) $intent->amount * 100);
            if (($verified['status'] ?? null) !== 'success') {
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'processing_error'  => "Paystack verify returned status '{$verified['status']}' for {$event->paystack_reference}",
                ]);
                return null;
            }
            if ((int) ($verified['amount'] ?? 0) !== $expectedPesewas) {
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'processing_error'  => sprintf(
                        'amount mismatch on %s: intent expects %d pesewas, Paystack reports %d',
                        $event->paystack_reference,
                        $expectedPesewas,
                        (int) ($verified['amount'] ?? 0),
                    ),
                ]);
                return null;
            }

            $bank = OrgBankAccount::forPurpose(config('services.paystack.receipt_bank_purpose'))
                ->active()
                ->first();

            if (! $bank) {
                throw new DomainException(
                    'No active org_bank_account with purpose='
                    . config('services.paystack.receipt_bank_purpose')
                    . '. Configure one before processing Paystack receipts.'
                );
            }

            $existing = ArReceipt::where('external_ref', $event->paystack_reference)->first();
            if ($existing) {
                $intent->update([
                    'status'        => PaymentIntentStatus::Success->value,
                    'paid_at'       => now(),
                    'ar_receipt_id' => $existing->id,
                ]);
                $event->update([
                    'processed_at'      => now(),
                    'payment_intent_id' => $intent->id,
                    'ar_receipt_id'     => $existing->id,
                ]);
                return $existing;
            }

            $receipt = $this->receipts->record([
                'customer_id'         => $intent->customer_id,
                'receipt_date'        => now()->format('Y-m-d'),
                'amount'              => (float) $intent->amount,
                'currency'            => $intent->currency,
                'org_bank_account_id' => $bank->id,
                'external_ref'        => $event->paystack_reference,
                'narration'           => "Paystack — {$intent->reference}",
                'allocations'         => [[
                    'ar_invoice_id'    => $intent->ar_invoice_id,
                    'allocated_amount' => (float) $intent->amount,
                ]],
            ], $intent->creator);

            $intent->update([
                'status'        => PaymentIntentStatus::Success->value,
                'paid_at'       => now(),
                'ar_receipt_id' => $receipt->id,
            ]);

            $event->update([
                'processed_at'      => now(),
                'payment_intent_id' => $intent->id,
                'ar_receipt_id'     => $receipt->id,
            ]);

            return $receipt;
        });
    }

    private function markNoOp(PaystackWebhookEvent $event): null
    {
        $event->update([
            'processed_at'     => now(),
            'processing_error' => "no-op for event_type {$event->event_type}",
        ]);
        return null;
    }

    private function handleRefundProcessed(PaystackWebhookEvent $event): null
    {
        $refundId = (string) (data_get($event->payload, 'data.id') ?? '');
        if ($refundId === '') {
            $event->update([
                'processed_at'     => now(),
                'processing_error' => 'refund.processed missing data.id',
            ]);
            return null;
        }

        return DB::transaction(function () use ($event, $refundId) {
            $intent = PaymentIntent::where('refund_paystack_ref', $refundId)
                ->lockForUpdate()
                ->first();

            if (! $intent) {
                $event->update([
                    'processed_at'     => now(),
                    'processing_error' => "PaymentIntent for refund_paystack_ref '{$refundId}' not found",
                ]);
                return null;
            }

            if ($intent->refund_settled_at === null) {
                $intent->update(['refund_settled_at' => now()]);
            }

            $event->update([
                'processed_at'      => now(),
                'payment_intent_id' => $intent->id,
            ]);

            return null;
        });
    }
}
