<?php

declare(strict_types=1);

use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\PaymentIntent;
use App\Models\PaystackWebhookEvent;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\PaystackGatewayService;
use App\Services\Finance\PaystackWebhookProcessor;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'                  => 'https://api.paystack.co',
        'services.paystack.secret_key'           => 'sk_test_secret',
        'services.paystack.receipt_bank_purpose' => 'receipts',
    ]);

    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $receiptsBank = OrgBankAccount::forPurpose('receipts')->first();
    if (! $receiptsBank) {
        $gl = GlAccount::where('code', '1110')->first() ?: GlAccount::create([
            'code' => '1110', 'name' => 'Bank - Receipts', 'type' => 'asset',
        ]);
        \App\Models\GlAccountBalance::firstOrCreate(['gl_account_id' => $gl->id], ['balance' => 0]);
        OrgBankAccount::create([
            'gl_account_id' => $gl->id, 'bank_name' => 'GTBank', 'account_name' => 'CIHRM Receipts',
            'account_number' => '7777777777', 'purpose' => 'receipts',
        ]);
    }

    $this->user     = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->customer = Customer::create([
        'code' => 'CUS-W', 'name' => 'Web', 'status' => 'active', 'email' => 'web@example.com',
    ]);

    $income = GlAccount::where('code', '4100')->firstOrFail();
    $inv = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 300, 'gl_account_id' => $income->id]],
    ], $this->user);
    app(ArInvoiceService::class)->submit($inv);
    app(ArInvoiceService::class)->approve($inv->fresh(), $this->approver);
    $this->invoice = $inv->fresh();

    $this->intent = PaymentIntent::create([
        'reference'          => 'PI-2026-000001',
        'customer_id'        => $this->customer->id,
        'ar_invoice_id'      => $this->invoice->id,
        'amount'             => 300,
        'currency'           => 'GHS',
        'status'             => 'pending',
        'paystack_reference' => 'pst_webhook_001',
        'expires_at'         => now()->addHours(24),
        'created_by'         => $this->user->id,
    ]);

    $this->processor = app(PaystackWebhookProcessor::class);
});

function makeChargeSuccessEvent(string $eventId, string $paystackRef): PaystackWebhookEvent
{
    return PaystackWebhookEvent::create([
        'paystack_event_id'  => $eventId,
        'event_type'         => 'charge.success',
        'paystack_reference' => $paystackRef,
        'payload'            => [
            'event' => 'charge.success',
            'data'  => ['id' => 1, 'reference' => $paystackRef, 'status' => 'success', 'amount' => 30000],
        ],
        'signature'          => 'sig',
    ]);
}

it('charge.success with matching intent posts an ArReceipt and links everything', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_webhook_001' => Http::response([
            'status' => true,
            'data'   => ['status' => 'success', 'reference' => 'pst_webhook_001', 'amount' => 30000],
        ], 200),
    ]);

    $event = makeChargeSuccessEvent('evt_001', 'pst_webhook_001');
    $receipt = $this->processor->process($event);

    expect($receipt)->toBeInstanceOf(ArReceipt::class);
    expect($receipt->external_ref)->toBe('pst_webhook_001');
    expect((float) $receipt->amount)->toBe(300.0);
    expect($this->intent->fresh()->status)->toBe(PaymentIntentStatus::Success);
    expect($this->intent->fresh()->ar_receipt_id)->toBe($receipt->id);
    expect($event->fresh()->processed_at)->not->toBeNull();
    expect($event->fresh()->ar_receipt_id)->toBe($receipt->id);
    expect($this->invoice->fresh()->status)->toBe(ArInvoiceStatus::Paid);
});

it('re-processing the same event short-circuits (idempotent)', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_webhook_001' => Http::response([
            'status' => true,
            'data'   => ['status' => 'success', 'reference' => 'pst_webhook_001', 'amount' => 30000],
        ], 200),
    ]);

    $event = makeChargeSuccessEvent('evt_002', 'pst_webhook_001');
    $receipt1 = $this->processor->process($event);
    $receipt2 = $this->processor->process($event->fresh());

    expect($receipt2->id)->toBe($receipt1->id);
    expect(ArReceipt::count())->toBe(1);
});

it('charge.success with unknown paystack_reference records error and creates no receipt', function () {
    $event = makeChargeSuccessEvent('evt_003', 'pst_unknown_ref');
    $receipt = $this->processor->process($event);

    expect($receipt)->toBeNull();
    expect($event->fresh()->processing_error)->toContain('not found');
    expect(ArReceipt::count())->toBe(0);
});

it('charge.success with amount mismatch records error and creates no receipt', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_webhook_001' => Http::response([
            'status' => true,
            'data'   => ['status' => 'success', 'reference' => 'pst_webhook_001', 'amount' => 99999],
        ], 200),
    ]);

    $event = makeChargeSuccessEvent('evt_004', 'pst_webhook_001');
    $receipt = $this->processor->process($event);

    expect($receipt)->toBeNull();
    expect($event->fresh()->processing_error)->toContain('amount');
    expect($this->intent->fresh()->status)->toBe(PaymentIntentStatus::Pending);
    expect(ArReceipt::count())->toBe(0);
});

it('non-charge-success events are recorded as no-op', function () {
    $event = PaystackWebhookEvent::create([
        'paystack_event_id' => 'evt_005', 'event_type' => 'charge.failed',
        'paystack_reference' => 'pst_webhook_001',
        'payload' => ['event' => 'charge.failed', 'data' => []],
        'signature' => 'sig',
    ]);

    $result = $this->processor->process($event);

    expect($result)->toBeNull();
    expect($event->fresh()->processed_at)->not->toBeNull();
    expect($event->fresh()->processing_error)->toContain('no-op');
});

it('refund.processed event stamps refund_settled_at on the matching intent', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_webhook_001' => Http::response([
            'status' => true, 'data' => ['status' => 'success', 'reference' => 'pst_webhook_001', 'amount' => 30000],
        ], 200),
        'api.paystack.co/refund' => Http::response([
            'status' => true, 'data' => ['id' => 7777, 'status' => 'pending'],
        ], 200),
    ]);

    // First process a charge.success so the intent flips to success with a linked receipt — then refund is possible.
    $charge = makeChargeSuccessEvent('evt_rp_pre1', 'pst_webhook_001');
    $this->processor->process($charge);

    app(\App\Services\Finance\RefundService::class)
        ->refund($this->intent->fresh(), $this->user, 'test reason refund webhook');

    $event = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_rp_001',
        'event_type'         => 'refund.processed',
        'paystack_reference' => 'pst_webhook_001',
        'payload'            => ['event' => 'refund.processed', 'data' => ['id' => 7777]],
        'signature'          => 'sig',
    ]);

    $result = $this->processor->process($event);

    expect($result)->toBeNull();
    expect($event->fresh()->processed_at)->not->toBeNull();
    expect($this->intent->fresh()->refund_settled_at)->not->toBeNull();
    expect($event->fresh()->payment_intent_id)->toBe($this->intent->id);
});

it('refund.processed event with unknown refund_paystack_ref records error', function () {
    $event = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_rp_002',
        'event_type'         => 'refund.processed',
        'paystack_reference' => 'pst_webhook_001',
        'payload'            => ['event' => 'refund.processed', 'data' => ['id' => 99999]],
        'signature'          => 'sig',
    ]);

    $result = $this->processor->process($event);

    expect($result)->toBeNull();
    expect($event->fresh()->processing_error)->toContain('not found');
});

it('refund.processed event is idempotent (re-processing does not move refund_settled_at)', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/pst_webhook_001' => Http::response([
            'status' => true, 'data' => ['status' => 'success', 'reference' => 'pst_webhook_001', 'amount' => 30000],
        ], 200),
        'api.paystack.co/refund' => Http::response([
            'status' => true, 'data' => ['id' => 4444, 'status' => 'pending'],
        ], 200),
    ]);

    $charge = makeChargeSuccessEvent('evt_rp_pre2', 'pst_webhook_001');
    $this->processor->process($charge);

    app(\App\Services\Finance\RefundService::class)
        ->refund($this->intent->fresh(), $this->user, 'idempotency test');

    $event1 = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_rp_003a',
        'event_type'         => 'refund.processed',
        'paystack_reference' => 'pst_webhook_001',
        'payload'            => ['event' => 'refund.processed', 'data' => ['id' => 4444]],
        'signature'          => 'sig',
    ]);
    $this->processor->process($event1);
    $firstSettled = $this->intent->fresh()->refund_settled_at->toDateTimeString();

    \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(5));

    $event2 = PaystackWebhookEvent::create([
        'paystack_event_id'  => 'evt_rp_003b',
        'event_type'         => 'refund.processed',
        'paystack_reference' => 'pst_webhook_001',
        'payload'            => ['event' => 'refund.processed', 'data' => ['id' => 4444]],
        'signature'          => 'sig',
    ]);
    $this->processor->process($event2);

    expect($this->intent->fresh()->refund_settled_at->toDateTimeString())->toBe($firstSettled);
});
