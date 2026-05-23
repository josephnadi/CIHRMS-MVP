<?php

declare(strict_types=1);

use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ApPaymentService;
use App\Services\Finance\VendorInvoiceService;
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

it('void() restores invoice amount_paid to zero atomically', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $approver = User::factory()->create(['role' => 'finance_officer']);
    $vendor = Vendor::create(['code' => 'V', 'name' => 'V', 'status' => 'active']);
    $expense = GlAccount::where('code', '5200')->firstOrFail();
    $bank = OrgBankAccount::active()->first();

    $inv = app(VendorInvoiceService::class)->create([
        'vendor_id' => $vendor->id, 'invoice_date' => '2026-05-23',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $expense->id]],
    ], $u);
    app(VendorInvoiceService::class)->submit($inv);
    app(VendorInvoiceService::class)->approve($inv->fresh(), $approver);

    $payment = app(ApPaymentService::class)->record([
        'vendor_id' => $vendor->id, 'payment_date' => '2026-05-23',
        'amount' => 100, 'org_bank_account_id' => $bank->id,
        'allocations' => [['vendor_invoice_id' => $inv->id, 'allocated_amount' => 100]],
    ], $u);

    expect((float) $inv->fresh()->amount_paid)->toBe(100.0);

    app(ApPaymentService::class)->void($payment, $u, 'test void');

    expect((float) $inv->fresh()->amount_paid)->toBe(0.0);
    expect($payment->fresh()->status->value)->toBe('voided');
});

it('ApPaymentService::void() source uses lockForUpdate before mutating invoices', function () {
    $source = file_get_contents(app_path('Services/Finance/ApPaymentService.php'));

    // Find the void() method body and confirm a lockForUpdate() call exists inside it.
    $voidStart = strpos($source, 'public function void(');
    expect($voidStart)->not->toBeFalse();

    $voidEnd = strpos($source, 'private function nextReference', $voidStart);
    $voidBody = substr($source, $voidStart, ($voidEnd ?: strlen($source)) - $voidStart);

    expect($voidBody)->toContain('lockForUpdate');
});
