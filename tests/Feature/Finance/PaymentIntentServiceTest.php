<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\PaymentIntentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'        => 'https://api.paystack.co',
        'services.paystack.secret_key' => 'sk_test_secret',
    ]);

    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->svc      = app(PaymentIntentService::class);
    $this->user     = User::factory()->create();
    $this->customer = Customer::create([
        'code' => 'CUS-P', 'name' => 'Pay', 'status' => 'active', 'email' => 'pay@example.com',
    ]);

    $income = GlAccount::where('code', '4100')->firstOrFail();
    $inv    = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 500, 'gl_account_id' => $income->id]],
    ], $this->user);
    app(ArInvoiceService::class)->submit($inv);
    $approver = User::factory()->create();
    app(ArInvoiceService::class)->approve($inv->fresh(), $approver);
    $this->invoice = $inv->fresh();
});

it('createForInvoice posts to Paystack and stores authorization_url', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc',
                'access_code'       => 'ac_abc',
                'reference'         => 'pst_001',
            ],
        ], 200),
    ]);

    $intent = $this->svc->createForInvoice($this->invoice, 500.0, $this->user);

    expect($intent->status)->toBe(PaymentIntentStatus::Pending);
    expect($intent->paystack_reference)->toBe('pst_001');
    expect($intent->authorization_url)->toBe('https://checkout.paystack.com/abc');
    expect((float) $intent->amount)->toBe(500.0);
    expect($intent->customer_id)->toBe($this->customer->id);
    expect($intent->ar_invoice_id)->toBe($this->invoice->id);
    expect($intent->expires_at)->not->toBeNull();
});

it('createForInvoice refuses if invoice status is not Approved or PartiallyPaid', function () {
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $draft  = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $income->id]],
    ], $this->user);

    expect(fn () => $this->svc->createForInvoice($draft->fresh(), 100.0, $this->user))
        ->toThrow(\DomainException::class, 'status');
});

it('createForInvoice refuses if amount exceeds outstanding', function () {
    Http::fake();  // Should not hit Paystack
    expect(fn () => $this->svc->createForInvoice($this->invoice, 1000.0, $this->user))
        ->toThrow(\DomainException::class, 'outstanding');
});

it('createForInvoice refuses if customer has no email', function () {
    $this->customer->update(['email' => null]);
    expect(fn () => $this->svc->createForInvoice($this->invoice->fresh(), 500.0, $this->user))
        ->toThrow(\DomainException::class, 'email');
});

it('expireStale flips pending+old intents to expired', function () {
    PaymentIntent::create([
        'reference' => 'OLD', 'customer_id' => $this->customer->id,
        'amount' => 100, 'status' => 'pending',
        'expires_at' => now()->subHours(2), 'created_by' => $this->user->id,
    ]);
    PaymentIntent::create([
        'reference' => 'FRESH', 'customer_id' => $this->customer->id,
        'amount' => 100, 'status' => 'pending',
        'expires_at' => now()->addHours(2), 'created_by' => $this->user->id,
    ]);

    $count = $this->svc->expireStale();

    expect($count)->toBe(1);
    expect(PaymentIntent::where('reference', 'OLD')->first()->status)->toBe(PaymentIntentStatus::Expired);
    expect(PaymentIntent::where('reference', 'FRESH')->first()->status)->toBe(PaymentIntentStatus::Pending);
});
