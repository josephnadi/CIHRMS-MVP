<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\PaymentIntent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PaymentIntentService
{
    public function __construct(
        private readonly PaystackGatewayService $gateway,
        private readonly SequenceService $sequences,
    ) {
    }

    public function createForInvoice(
        ArInvoice $invoice,
        float $amount,
        User $creator,
        ?string $callbackUrl = null,
    ): PaymentIntent {
        if (! in_array($invoice->status, [ArInvoiceStatus::Approved, ArInvoiceStatus::PartiallyPaid], true)) {
            throw new DomainException(
                "Cannot create payment intent for invoice {$invoice->reference}: status is {$invoice->status->value}."
            );
        }
        if ($amount > $invoice->outstandingAmount() + 0.005) {
            throw new DomainException(sprintf(
                'Amount %.2f exceeds outstanding %.2f on invoice %s.',
                $amount, $invoice->outstandingAmount(), $invoice->reference,
            ));
        }
        $customer = $invoice->customer;
        if (empty($customer->email)) {
            throw new DomainException(
                "Customer {$customer->code} has no email address — required for Paystack."
            );
        }

        return DB::transaction(function () use ($invoice, $amount, $creator, $callbackUrl, $customer) {
            $intent = PaymentIntent::create([
                'reference'     => $this->nextReference(),
                'customer_id'   => $customer->id,
                'ar_invoice_id' => $invoice->id,
                'amount'        => $amount,
                'currency'      => 'GHS',
                'status'        => PaymentIntentStatus::Created->value,
                'callback_url'  => $callbackUrl ?? config('services.paystack.callback_default_url'),
                'created_by'    => $creator->id,
            ]);

            $paystackData = $this->gateway->initializeTransaction([
                'email'        => $customer->email,
                'amount'       => $amount,
                'reference'    => $intent->reference,
                'callback_url' => $intent->callback_url,
                'metadata'     => [
                    'cihrms_intent_id'    => $intent->id,
                    'cihrms_invoice_ref'  => $invoice->reference,
                    'cihrms_customer_code'=> $customer->code,
                ],
            ]);

            $intent->update([
                'status'                 => PaymentIntentStatus::Pending->value,
                'paystack_reference'     => $paystackData['reference'],
                'paystack_access_code'   => $paystackData['access_code'],
                'authorization_url'      => $paystackData['authorization_url'],
                'expires_at'             => now()->addHours(24),
                'last_paystack_response' => $paystackData,
            ]);

            return $intent->fresh();
        });
    }

    public function expireStale(): int
    {
        return PaymentIntent::stale()
            ->update(['status' => PaymentIntentStatus::Expired->value]);
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        return sprintf('PI-%s-%06d', $year, $this->sequences->next("payment_intent:{$year}"));
    }
}
