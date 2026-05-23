<?php

declare(strict_types=1);

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;

function pi2faFresh(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);
    return $user;
}

beforeEach(function () {
    config([
        'services.paystack.url'        => 'https://api.paystack.co',
        'services.paystack.secret_key' => 'sk_test_secret',
    ]);

    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->customer = Customer::create([
        'code' => 'CUS-PI', 'name' => 'PI Test', 'status' => 'active', 'email' => 'pi@example.com',
    ]);
});

it('finance_officer can list payment intents', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/payment-intents')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/PaymentIntents/Index'));
});

it('auditor can list (gateway.view) but not create (gateway.create)', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($u)->get('/finance/payment-intents')->assertOk();
    $this->actingAs($u)->post('/finance/payment-intents', [
        'ar_invoice_id' => 1, 'amount' => 100,
    ])->assertForbidden();
});

it('employee gets 403 on listing', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/payment-intents')->assertForbidden();
});

it('finance_officer creates a payment intent (Paystack mocked)', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/xyz',
                'access_code'       => 'ac_xyz',
                'reference'         => 'pst_endpoint_create',
            ],
        ], 200),
    ]);

    $u = User::factory()->create(['role' => 'finance_officer']);
    $income = GlAccount::where('code', '4100')->firstOrFail();

    $inv = app(ArInvoiceService::class)->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 250, 'gl_account_id' => $income->id]],
    ], $u);
    app(ArInvoiceService::class)->submit($inv);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    app(ArInvoiceService::class)->approve($inv->fresh(), $approver);

    $this->actingAs(pi2faFresh($u))->post('/finance/payment-intents', [
        'ar_invoice_id' => $inv->id,
        'amount'        => 250,
    ])->assertRedirect();

    expect(PaymentIntent::count())->toBe(1);
    expect(PaymentIntent::first()->ar_invoice_id)->toBe($inv->id);
});
