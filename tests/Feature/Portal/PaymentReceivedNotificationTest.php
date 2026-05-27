<?php

use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentIntentStatus;
use App\Events\ReceiptProcessed;
use App\Models\ArInvoice;
use App\Models\Member;
use App\Models\OrgBankAccount;
use App\Models\PaymentIntent;
use App\Models\PaystackWebhookEvent;
use App\Models\User;
use App\Notifications\PaymentReceived;
use App\Services\Finance\PaystackWebhookProcessor;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    config(['services.paystack.secret_key' => 'sk_test_x']);
});

it('dispatches ReceiptProcessed when a Paystack charge.success webhook is processed', function () {
    Event::fake([ReceiptProcessed::class]);

    $member = Member::factory()->create();
    $member->customer->update(['email' => 'pays@member.gh']);

    $arGl = \App\Models\GlAccount::ofType('receivable')->first()
        ?: \App\Models\GlAccount::ofType('asset')->orderBy('code')->first();
    $invoice = ArInvoice::create([
        'reference'        => 'INV-1',
        'customer_id'      => $member->customer_id,
        'status'           => ArInvoiceStatus::Approved->value,
        'invoice_date'     => now()->toDateString(),
        'subtotal'         => 500.00,
        'tax_amount'       => 0,
        'total'            => 500.00,
        'amount_received'  => 0,
        'currency'         => 'GHS',
        'ar_gl_account_id' => $arGl->id,
        'created_by'       => User::factory()->create()->id,
    ]);

    $bank = OrgBankAccount::first();
    config(['services.paystack.receipt_bank_purpose' => $bank->purpose ?? 'operations']);

    $intent = PaymentIntent::create([
        'reference'           => 'PI-1',
        'customer_id'         => $member->customer_id,
        'ar_invoice_id'       => $invoice->id,
        'amount'              => 500.00,
        'currency'            => 'GHS',
        'status'              => PaymentIntentStatus::Pending->value,
        'paystack_reference'  => 'pstk_ref_1',
        'paystack_access_code' => 'ac_1',
        'authorization_url'   => 'https://checkout.paystack.com/ac_1',
        'created_by'          => User::factory()->create()->id,
    ]);

    Http::fake([
        'api.paystack.co/transaction/verify/pstk_ref_1' => Http::response([
            'status' => true,
            'data'   => [
                'status'   => 'success',
                'amount'   => 500 * 100,  // pesewas
                'currency' => 'GHS',
                'reference'=> 'pstk_ref_1',
            ],
        ], 200),
    ]);

    $event = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_test_1',
        'event_type'         => 'charge.success',
        'paystack_reference' => 'pstk_ref_1',
        'payload'            => ['amount' => 50000],
        'signature'          => str_repeat('a', 64),
    ]);

    app(PaystackWebhookProcessor::class)->process($event);

    Event::assertDispatched(ReceiptProcessed::class, function ($e) use ($invoice) {
        return $e->receipt->customer_id === $invoice->customer_id
            && (float) $e->receipt->amount === 500.0;
    });
});

it('SendPaymentReceiptNotification fires PaymentReceived on the member when ReceiptProcessed dispatches', function () {
    Notification::fake();

    $member = Member::factory()->create(['phone' => '+233200000222']);
    $member->customer->update(['email' => 'notify@member.gh']);

    $arGl = \App\Models\GlAccount::ofType('receivable')->first()
        ?: \App\Models\GlAccount::ofType('asset')->orderBy('code')->first();
    $invoice = ArInvoice::create([
        'reference'        => 'INV-N',
        'customer_id'      => $member->customer_id,
        'status'           => ArInvoiceStatus::Approved->value,
        'invoice_date'     => now()->toDateString(),
        'subtotal'         => 250.00,
        'tax_amount'       => 0,
        'total'            => 250.00,
        'amount_received'  => 0,
        'currency'         => 'GHS',
        'ar_gl_account_id' => $arGl->id,
        'created_by'       => User::factory()->create()->id,
    ]);

    $bank = OrgBankAccount::first();
    config(['services.paystack.receipt_bank_purpose' => $bank->purpose ?? 'operations']);

    $intent = PaymentIntent::create([
        'reference'           => 'PI-N',
        'customer_id'         => $member->customer_id,
        'ar_invoice_id'       => $invoice->id,
        'amount'              => 250.00,
        'currency'            => 'GHS',
        'status'              => PaymentIntentStatus::Pending->value,
        'paystack_reference'  => 'pstk_ref_n',
        'paystack_access_code' => 'ac_n',
        'authorization_url'   => 'https://checkout.paystack.com/ac_n',
        'created_by'          => User::factory()->create()->id,
    ]);

    Http::fake([
        'api.paystack.co/transaction/verify/pstk_ref_n' => Http::response([
            'status' => true,
            'data'   => [
                'status'   => 'success',
                'amount'   => 25000,
                'currency' => 'GHS',
                'reference'=> 'pstk_ref_n',
            ],
        ], 200),
    ]);

    $event = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_test_n',
        'event_type'         => 'charge.success',
        'paystack_reference' => 'pstk_ref_n',
        'payload'            => ['amount' => 25000],
        'signature'          => str_repeat('b', 64),
    ]);

    app(PaystackWebhookProcessor::class)->process($event);

    Notification::assertSentTo($member, PaymentReceived::class, function ($n) use ($invoice) {
        return (float) $n->receipt->amount === 250.0;
    });
});
