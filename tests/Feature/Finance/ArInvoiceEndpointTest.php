<?php

declare(strict_types=1);

use App\Enums\ArInvoiceStatus;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

/**
 * Helper — F3 receive + write-off endpoints are gated by `2fa:fresh`.
 * Tests that POST to those routes must (a) ensure the user has 2FA enrolled
 * (`two_factor_confirmed_at` set) and (b) hold a fresh challenge assertion.
 */
function ar2faFresh(\App\Models\User $user): \App\Models\User
{
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(TwoFactorService::class)->markFresh($user);
    return $user;
}

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->income   = GlAccount::where('code', '4200')->firstOrFail();
    $this->customer = Customer::create(['code' => 'CUS-T', 'name' => 'T', 'status' => 'active']);
});

it('finance_officer can list AR invoices', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/ar-invoices')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/ArInvoices/Index'));
});

it('employee gets 403', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/ar-invoices')->assertForbidden();
});

it('auditor can view AR invoices but not create', function () {
    $u = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($u)->get('/finance/ar-invoices')->assertOk();

    $this->actingAs($u)->post('/finance/ar-invoices', [
        'customer_id' => $this->customer->id,
        'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->income->id]],
    ])->assertForbidden();
});

it('creates an AR invoice via POST', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->post('/finance/ar-invoices', [
        'customer_id' => $this->customer->id,
        'customer_invoice_no' => 'PO-001',
        'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'Training', 'quantity' => 1, 'unit_price' => 1000, 'gl_account_id' => $this->income->id]],
    ])->assertRedirect();

    expect(ArInvoice::where('customer_invoice_no', 'PO-001')->exists())->toBeTrue();
});

it('rejects duplicate customer_invoice_no for the same customer', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u);
    $this->post('/finance/ar-invoices', [
        'customer_id' => $this->customer->id,
        'customer_invoice_no' => 'PO-DUP',
        'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->income->id]],
    ])->assertRedirect();

    $this->post('/finance/ar-invoices', [
        'customer_id' => $this->customer->id,
        'customer_invoice_no' => 'PO-DUP',
        'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->income->id]],
    ])->assertSessionHasErrors(['customer_invoice_no']);
});

it('submit + approve flow with dual control', function () {
    $creator = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($creator);
    $this->post('/finance/ar-invoices', [
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->income->id]],
    ]);
    $inv = ArInvoice::latest()->first();

    $this->post("/finance/ar-invoices/{$inv->id}/submit")->assertRedirect();
    expect($inv->fresh()->status)->toBe(ArInvoiceStatus::PendingApproval);

    // Creator cannot self-approve.
    $this->post("/finance/ar-invoices/{$inv->id}/approve")->assertSessionHasErrors(['status']);

    $approver = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($approver)->post("/finance/ar-invoices/{$inv->id}/approve")->assertRedirect();
    expect($inv->fresh()->status)->toBe(ArInvoiceStatus::Approved);
});

it('write_off requires the ar_invoices.write_off permission', function () {
    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($creator);
    $this->post('/finance/ar-invoices', [
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1000, 'gl_account_id' => $this->income->id]],
    ]);
    $inv = ArInvoice::latest()->first();
    $this->post("/finance/ar-invoices/{$inv->id}/submit");
    $this->actingAs($approver)->post("/finance/ar-invoices/{$inv->id}/approve");

    // Employee — no permission — should 403
    $employee = User::factory()->create(['role' => 'employee']);
    $this->actingAs($employee)->post("/finance/ar-invoices/{$inv->id}/write-off", [
        'reason' => 'uncollectible',
    ])->assertForbidden();

    // finance_officer with the permission AND a fresh 2FA challenge — should succeed
    $this->actingAs(ar2faFresh($creator))->post("/finance/ar-invoices/{$inv->id}/write-off", [
        'reason' => 'uncollectible after 180 days',
    ])->assertRedirect();
    expect($inv->fresh()->status)->toBe(ArInvoiceStatus::WrittenOff);
});

it('write_off is blocked when the user lacks a fresh 2FA assertion', function () {
    $creator  = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($creator);
    $this->post('/finance/ar-invoices', [
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1000, 'gl_account_id' => $this->income->id]],
    ]);
    $inv = ArInvoice::latest()->first();
    $this->post("/finance/ar-invoices/{$inv->id}/submit");
    $this->actingAs($approver)->post("/finance/ar-invoices/{$inv->id}/approve");

    // Creator has permission but has NOT challenged 2FA — middleware
    // redirects to the challenge page rather than processing the write-off.
    $this->actingAs($creator)->post("/finance/ar-invoices/{$inv->id}/write-off", [
        'reason' => 'no 2fa fresh',
    ])->assertRedirect(); // redirects to /two-factor/enroll or /two-factor/challenge
    expect($inv->fresh()->status)->toBe(ArInvoiceStatus::Approved);
});
