<?php

declare(strict_types=1);

use App\Enums\ArReceiptStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\ArReceiptService;
use App\Services\Finance\RefundService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'        => 'https://api.paystack.co',
        'services.paystack.secret_key' => 'sk_test_secret',
    ]);

    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->user     = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->customer = Customer::create([
        'code' => 'CUS-R', 'name' => 'Ref', 'status' => 'active', 'email' => 'r@example.com',
    ]);

    $income = GlAccount::where('code', '4100')->firstOrFail();
    $inv = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 250, 'gl_account_id' => $income->id]],
    ], $this->user);
    app(ArInvoiceService::class)->submit($inv);
    app(ArInvoiceService::class)->approve($inv->fresh(), $this->approver);
    $this->invoice = $inv->fresh();

    $bank = OrgBankAccount::forPurpose('receipts')->first()
        ?? OrgBankAccount::active()->first();

    $this->receipt = app(ArReceiptService::class)->record([
        'customer_id'         => $this->customer->id,
        'receipt_date'        => '2026-05-23',
        'amount'              => 250,
        'currency'            => 'GHS',
        'org_bank_account_id' => $bank->id,
        'external_ref'        => 'pst_refundtest',
        'allocations'         => [['ar_invoice_id' => $this->invoice->id, 'allocated_amount' => 250]],
    ], $this->user);

    $this->intent = PaymentIntent::create([
        'reference'          => 'PI-2026-R00001',
        'customer_id'        => $this->customer->id,
        'ar_invoice_id'      => $this->invoice->id,
        'amount'             => 250,
        'currency'           => 'GHS',
        'status'             => PaymentIntentStatus::Success->value,
        'paystack_reference' => 'pst_refundtest',
        'ar_receipt_id'      => $this->receipt->id,
        'paid_at'            => now(),
        'created_by'         => $this->user->id,
    ]);

    $this->svc = app(RefundService::class);
});

it('refund() calls Paystack, voids the receipt, and stamps the intent', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 555, 'status' => 'pending', 'amount' => 25000],
        ], 200),
    ]);

    $intent = $this->svc->refund($this->intent, $this->user, 'Customer requested cancellation');

    expect($intent->status)->toBe(PaymentIntentStatus::Refunded);
    expect($intent->refunded_at)->not->toBeNull();
    expect($intent->refund_paystack_ref)->toBe('555');
    expect($intent->refund_reason)->toBe('Customer requested cancellation');
    expect((float) $intent->refund_amount)->toBe(250.0);
    expect($intent->refunded_by)->toBe($this->user->id);
    expect($intent->refund_settled_at)->toBeNull();

    expect($this->receipt->fresh()->status)->toBe(ArReceiptStatus::Voided);
    expect((float) $this->invoice->fresh()->amount_received)->toBe(0.0);
});

it('refund() refuses an intent that is not in Success status', function () {
    $this->intent->update(['status' => PaymentIntentStatus::Pending->value]);

    expect(fn () => $this->svc->refund($this->intent->fresh(), $this->user, 'try'))
        ->toThrow(\DomainException::class, 'status');
});

it('refund() refuses an already-refunded intent', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true, 'data' => ['id' => 555, 'status' => 'pending'],
        ], 200),
    ]);

    $this->svc->refund($this->intent, $this->user, 'first');

    expect(fn () => $this->svc->refund($this->intent->fresh(), $this->user, 'second'))
        ->toThrow(\DomainException::class, 'already refunded');
});

it('refund() refuses an intent with no linked receipt', function () {
    $this->intent->update(['ar_receipt_id' => null]);

    expect(fn () => $this->svc->refund($this->intent->fresh(), $this->user, 'try'))
        ->toThrow(\DomainException::class, 'no linked AR receipt');
});

it('refund() Paystack failure leaves the receipt + intent untouched', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status'  => false,
            'message' => 'Transaction not refundable',
        ], 422),
    ]);

    expect(fn () => $this->svc->refund($this->intent, $this->user, 'try'))
        ->toThrow(\App\Exceptions\Finance\PaystackException::class);

    expect($this->intent->fresh()->refunded_at)->toBeNull();
    expect($this->intent->fresh()->status)->toBe(PaymentIntentStatus::Success);
    expect($this->receipt->fresh()->status)->toBe(ArReceiptStatus::Processed);
});
