<?php

declare(strict_types=1);

use App\Enums\VendorInvoiceStatus;
use App\Models\GlAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->expense = GlAccount::where('code', '5200')->firstOrFail();
    $this->vendor  = Vendor::create(['code' => 'VEN-T', 'name' => 'T', 'status' => 'active']);
});

it('finance_officer can list invoices', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/ap-invoices')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/ApInvoices/Index'));
});

it('employee gets 403', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/ap-invoices')->assertForbidden();
});

it('creates an invoice via POST', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->post('/finance/ap-invoices', [
        'vendor_id' => $this->vendor->id,
        'vendor_invoice_no' => 'INV-A',
        'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'Stuff', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->expense->id]],
    ])->assertRedirect();

    expect(VendorInvoice::where('vendor_invoice_no', 'INV-A')->exists())->toBeTrue();
});

it('rejects creation with empty lines', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->post('/finance/ap-invoices', [
        'vendor_id' => $this->vendor->id,
        'invoice_date' => '2026-05-22',
        'lines' => [],
    ])->assertSessionHasErrors(['lines']);
});

it('submit + approve flow', function () {
    $creator = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($creator);
    $this->post('/finance/ap-invoices', [
        'vendor_id' => $this->vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 50, 'gl_account_id' => $this->expense->id]],
    ]);
    $inv = VendorInvoice::latest()->first();

    $this->post("/finance/ap-invoices/{$inv->id}/submit")->assertRedirect();
    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::PendingApproval);

    $approver = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($approver)->post("/finance/ap-invoices/{$inv->id}/approve")->assertRedirect();
    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::Approved);
});

it('approve refuses creator', function () {
    $creator = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($creator);
    $this->post('/finance/ap-invoices', [
        'vendor_id' => $this->vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 50, 'gl_account_id' => $this->expense->id]],
    ]);
    $inv = VendorInvoice::latest()->first();
    $this->post("/finance/ap-invoices/{$inv->id}/submit");

    $response = $this->post("/finance/ap-invoices/{$inv->id}/approve");
    expect(in_array($response->status(), [302, 422], true))->toBeTrue();
    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::PendingApproval);
});

it('show page returns the invoice with lines', function () {
    $creator = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($creator);
    $this->post('/finance/ap-invoices', [
        'vendor_id' => $this->vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 50, 'gl_account_id' => $this->expense->id]],
    ]);
    $inv = VendorInvoice::latest()->first();

    $this->get("/finance/ap-invoices/{$inv->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/ApInvoices/Show')
            ->has('invoice')
            ->where('invoice.id', $inv->id));
});
