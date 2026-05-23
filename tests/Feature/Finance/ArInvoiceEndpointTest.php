<?php

declare(strict_types=1);

use App\Enums\ArInvoiceStatus;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

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

    // finance_officer with the permission — should succeed
    $this->actingAs($creator)->post("/finance/ar-invoices/{$inv->id}/write-off", [
        'reason' => 'uncollectible after 180 days',
    ])->assertRedirect();
    expect($inv->fresh()->status)->toBe(ArInvoiceStatus::WrittenOff);
});
