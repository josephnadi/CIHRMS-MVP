<?php

declare(strict_types=1);

use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();
});

it('finance_officer lists ap-payments', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($u)->get('/finance/ap-payments')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Finance/ApPayments/Index'));
});

it('employee gets 403', function () {
    $u = User::factory()->create(['role' => 'employee']);
    $this->actingAs($u)->get('/finance/ap-payments')->assertForbidden();
});

it('records a payment via POST', function () {
    $u       = User::factory()->create(['role' => 'finance_officer']);
    $vendor  = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $expense = \App\Models\GlAccount::where('code', '5200')->firstOrFail();
    $bank    = OrgBankAccount::where('bank_name', 'GCB')->firstOrFail();

    $this->actingAs($u);
    $inv = app(\App\Services\Finance\VendorInvoiceService::class)->create([
        'vendor_id' => $vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
    ], $u);
    app(\App\Services\Finance\VendorInvoiceService::class)->submit($inv);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    app(\App\Services\Finance\VendorInvoiceService::class)->approve($inv->fresh(), $approver);

    $this->actingAs($u)->post('/finance/ap-payments', [
        'vendor_id'           => $vendor->id,
        'payment_date'        => '2026-05-22',
        'amount'              => 100,
        'org_bank_account_id' => $bank->id,
        'allocations' => [['vendor_invoice_id' => $inv->id, 'allocated_amount' => 100]],
    ])->assertRedirect();

    expect(\App\Models\ApPayment::count())->toBe(1);
});
