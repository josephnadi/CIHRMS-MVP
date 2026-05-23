<?php

declare(strict_types=1);

use App\Enums\PaymentIntentStatus;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\ArReceiptService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.paystack.url'        => 'https://api.paystack.co',
        'services.paystack.secret_key' => 'sk_test_secret',
    ]);

    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->customer = Customer::create([
        'code' => 'CUS-RE', 'name' => 'Refund Endpoint', 'status' => 'active', 'email' => 're@example.com',
    ]);
});

function refund2faFresh(User $user): User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);
    return $user;
}

function makeRefundableIntent(Customer $customer, User $creator, User $approver): PaymentIntent
{
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $inv = app(ArInvoiceService::class)->create([
        'customer_id' => $customer->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $income->id]],
    ], $creator);
    app(ArInvoiceService::class)->submit($inv);
    app(ArInvoiceService::class)->approve($inv->fresh(), $approver);

    $bank = OrgBankAccount::forPurpose('receipts')->first()
        ?? OrgBankAccount::active()->first();

    $receipt = app(ArReceiptService::class)->record([
        'customer_id'         => $customer->id,
        'receipt_date'        => '2026-05-23',
        'amount'              => 100,
        'currency'            => 'GHS',
        'org_bank_account_id' => $bank->id,
        'external_ref'        => 'pst_endpoint_refund_' . uniqid(),
        'allocations'         => [['ar_invoice_id' => $inv->id, 'allocated_amount' => 100]],
    ], $creator);

    return PaymentIntent::create([
        'reference'          => 'PI-2026-RE' . str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
        'customer_id'        => $customer->id,
        'ar_invoice_id'      => $inv->id,
        'amount'             => 100,
        'currency'           => 'GHS',
        'status'             => PaymentIntentStatus::Success->value,
        'paystack_reference' => 'pst_endpoint_refund_' . uniqid(),
        'ar_receipt_id'      => $receipt->id,
        'paid_at'            => now(),
        'created_by'         => $creator->id,
    ]);
}

it('finance_officer with fresh 2FA can refund a payment intent', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true, 'data' => ['id' => 8888, 'status' => 'pending'],
        ], 200),
    ]);

    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $intent = makeRefundableIntent($this->customer, $creator, $approver);

    $this->actingAs(refund2faFresh($creator))
        ->post("/finance/payment-intents/{$intent->id}/refund", [
            'reason' => 'Customer wants money back',
        ])
        ->assertRedirect();

    expect($intent->fresh()->status)->toBe(PaymentIntentStatus::Refunded);
});

it('finance_officer WITHOUT fresh 2FA is bounced before reaching the service', function () {
    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $intent = makeRefundableIntent($this->customer, $creator, $approver);

    $this->actingAs($creator)
        ->post("/finance/payment-intents/{$intent->id}/refund", [
            'reason' => 'Customer wants money back',
        ])
        ->assertStatus(302);

    expect($intent->fresh()->status)->toBe(PaymentIntentStatus::Success);
});

it('auditor gets 403 on refund', function () {
    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $intent = makeRefundableIntent($this->customer, $creator, $approver);

    $auditor = User::factory()->create(['role' => 'auditor']);
    $this->actingAs(refund2faFresh($auditor))
        ->post("/finance/payment-intents/{$intent->id}/refund", [
            'reason' => 'snooping',
        ])
        ->assertForbidden();
});

it('rejects a missing or too-short reason', function () {
    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $intent = makeRefundableIntent($this->customer, $creator, $approver);

    $this->actingAs(refund2faFresh($creator))
        ->post("/finance/payment-intents/{$intent->id}/refund", ['reason' => 'no'])
        ->assertSessionHasErrors('reason');
});
