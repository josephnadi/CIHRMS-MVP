<?php

declare(strict_types=1);

use App\Models\GlAccountBalance;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\VendorInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
});

it('renders the hub for finance_officer with F2 aggregate keys', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Finance/Hub')
            ->has('cashPosition')
            ->has('outstandingLoans')
            ->has('pendingApprovals')
            ->has('statutoryCompliance')
            ->has('bankAccounts')
            ->has('nextPayroll')
            ->has('apOutstanding')
            ->has('arOutstanding')
            ->has('agingBuckets.current')
            ->has('agingBuckets.30')
            ->has('agingBuckets.60')
            ->has('agingBuckets.90_plus')
            ->has('pendingApprovals.payroll_runs')
            ->has('pendingApprovals.loans')
            ->has('pendingApprovals.invoices')
            ->has('pendingApprovals.payments')
        );
});

it('forbids employees from accessing the hub', function () {
    $employee = User::factory()->create(['role' => 'employee']);
    $this->actingAs($employee)->get('/finance')->assertForbidden();
});

it('cashPosition reflects live gl_account_balances for bank-linked asset accounts', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $this->actingAs($finance)->get('/finance')->assertInertia(fn ($p) => $p->where('cashPosition', fn ($v) => (float) $v === 0.0));

    $vendor  = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $expense = \App\Models\GlAccount::where('code', '5200')->firstOrFail();
    $inv = app(VendorInvoiceService::class)->create([
        'vendor_id' => $vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 500, 'gl_account_id' => $expense->id]],
    ], $finance);
    app(VendorInvoiceService::class)->submit($inv);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    app(VendorInvoiceService::class)->approve($inv->fresh(), $approver);

    $bank = \App\Models\OrgBankAccount::where('bank_name', 'GCB')->firstOrFail();
    app(\App\Services\Finance\ApPaymentService::class)->record([
        'vendor_id' => $vendor->id, 'payment_date' => '2026-05-22', 'amount' => 500,
        'org_bank_account_id' => $bank->id,
        'allocations' => [['vendor_invoice_id' => $inv->id, 'allocated_amount' => 500]],
    ], $finance);

    \Illuminate\Support\Facades\Cache::flush();

    // Bank GL 1100 should now show -500 (asset, credited 500 → natural Dr - Cr = -500).
    $this->actingAs($finance)->get('/finance')->assertInertia(fn ($p) => $p->where('cashPosition', fn ($v) => (float) $v === -500.0));
});

it('apOutstanding aggregates the outstanding amount from approved + partially_paid invoices', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);
    $vendor  = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $expense = \App\Models\GlAccount::where('code', '5200')->firstOrFail();

    $inv = app(VendorInvoiceService::class)->create([
        'vendor_id' => $vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1000, 'gl_account_id' => $expense->id]],
    ], $finance);
    app(VendorInvoiceService::class)->submit($inv);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    app(VendorInvoiceService::class)->approve($inv->fresh(), $approver);

    \Illuminate\Support\Facades\Cache::flush();

    $this->actingAs($finance)->get('/finance')->assertInertia(fn ($p) => $p->where('apOutstanding', fn ($v) => (float) $v === 1000.0));
});

it('hub returns gatewayHealth key', function () {
    $finance = User::factory()->create(['role' => 'finance_officer']);

    $this->actingAs($finance)
        ->get('/finance')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Finance/Hub')
            ->has('gatewayHealth')
            ->has('gatewayHealth.status')
            ->has('gatewayHealth.message')
        );
});

it('gatewayHealth is missing_bank when no receipts-purpose bank exists', function () {
    config(['services.paystack.receipt_bank_purpose' => 'receipts']);
    \App\Models\OrgBankAccount::query()->forPurpose('receipts')->delete();

    $finance = User::factory()->create(['role' => 'finance_officer']);

    \Illuminate\Support\Facades\Cache::flush();

    $this->actingAs($finance)
        ->get('/finance')
        ->assertInertia(fn ($p) => $p->where('gatewayHealth.status', 'missing_bank'));
});
