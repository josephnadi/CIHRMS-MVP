<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Services\Finance\VendorInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

it('rejects duplicate vendor_invoice_no for the same vendor with 422 / session error', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();

    // First invoice — succeeds via the service.
    app(VendorInvoiceService::class)->create([
        'vendor_id' => $vendor->id,
        'invoice_date' => '2026-05-23',
        'vendor_invoice_no' => 'INV-001',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
    ], $u);

    // Second with same vendor + same vendor_invoice_no — endpoint must reject with session errors.
    $this->actingAs($u)->post('/finance/ap-invoices', [
        'vendor_id' => $vendor->id,
        'invoice_date' => '2026-05-23',
        'vendor_invoice_no' => 'INV-001',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
    ])->assertSessionHasErrors('vendor_invoice_no');

    expect(VendorInvoice::where('vendor_invoice_no', 'INV-001')->count())->toBe(1);
});

it('allows the same vendor_invoice_no across different vendors', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $v1 = Vendor::create(['code' => 'V1', 'name' => 'V1', 'status' => 'active']);
    $v2 = Vendor::create(['code' => 'V2', 'name' => 'V2', 'status' => 'active']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();

    foreach ([$v1, $v2] as $vendor) {
        app(VendorInvoiceService::class)->create([
            'vendor_id' => $vendor->id,
            'invoice_date' => '2026-05-23',
            'vendor_invoice_no' => 'SHARED-001',
            'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
        ], $u);
    }

    expect(VendorInvoice::where('vendor_invoice_no', 'SHARED-001')->count())->toBe(2);
});

it('allows null vendor_invoice_no even when an invoice with null already exists', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();

    foreach ([1, 2] as $_) {
        app(VendorInvoiceService::class)->create([
            'vendor_id' => $vendor->id,
            'invoice_date' => '2026-05-23',
            'vendor_invoice_no' => null,
            'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
        ], $u);
    }

    expect(VendorInvoice::whereNull('vendor_invoice_no')->count())->toBe(2);
});
