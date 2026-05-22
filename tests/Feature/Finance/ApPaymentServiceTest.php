<?php

declare(strict_types=1);

use App\Enums\ApPaymentStatus;
use App\Enums\VendorInvoiceStatus;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ApPaymentService;
use App\Services\Finance\VendorInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new OrgBankAccountSeeder())->run();

    $this->payments = app(ApPaymentService::class);
    $this->invoices = app(VendorInvoiceService::class);

    $this->creator = User::factory()->create();
    $this->vendor  = Vendor::create(['code' => 'VEN-P', 'name' => 'PayTest', 'status' => 'active']);
    $this->bank    = OrgBankAccount::where('bank_name', 'GCB')->firstOrFail();
    $this->bankGl  = GlAccount::where('code', '1100')->firstOrFail();
    $this->ap      = GlAccount::where('code', '2100')->firstOrFail();
    $this->expense = GlAccount::where('code', '5200')->firstOrFail();

    $this->actingAs($this->creator);
});

function makeApprovedInvoice($svc, User $creator, Vendor $vendor, GlAccount $expense, float $total): \App\Models\VendorInvoice
{
    $inv = $svc->create([
        'vendor_id'    => $vendor->id,
        'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'Test', 'quantity' => 1, 'unit_price' => $total, 'gl_account_id' => $expense->id]],
    ], $creator);
    $svc->submit($inv);
    $approver = User::factory()->create();
    $svc->approve($inv->fresh(), $approver);
    return $inv->fresh();
}

it('records a payment, allocates to one invoice, posts the payment JE, flips invoice to Paid', function () {
    $inv = makeApprovedInvoice($this->invoices, $this->creator, $this->vendor, $this->expense, 500);

    $payment = $this->payments->record([
        'vendor_id'           => $this->vendor->id,
        'payment_date'        => '2026-05-22',
        'amount'              => 500,
        'org_bank_account_id' => $this->bank->id,
        'allocations'         => [['vendor_invoice_id' => $inv->id, 'allocated_amount' => 500]],
    ], $this->creator);

    expect($payment->status)->toBe(ApPaymentStatus::Processed);
    expect($payment->journal_entry_id)->not->toBeNull();
    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::Paid);
    expect((float) $inv->fresh()->amount_paid)->toBe(500.0);

    expect((float) GlAccountBalance::find($this->ap->id)->balance)->toBe(0.0);
    expect((float) GlAccountBalance::find($this->bankGl->id)->balance)->toBe(-500.0);
});

it('refuses to allocate more than the invoice outstanding amount', function () {
    $inv = makeApprovedInvoice($this->invoices, $this->creator, $this->vendor, $this->expense, 100);

    expect(fn () => $this->payments->record([
        'vendor_id'           => $this->vendor->id,
        'payment_date'        => '2026-05-22',
        'amount'              => 200,
        'org_bank_account_id' => $this->bank->id,
        'allocations'         => [['vendor_invoice_id' => $inv->id, 'allocated_amount' => 200]],
    ], $this->creator))->toThrow(\DomainException::class, 'outstanding');
});

it('refuses if sum(allocations) !== payment amount', function () {
    $inv = makeApprovedInvoice($this->invoices, $this->creator, $this->vendor, $this->expense, 100);

    expect(fn () => $this->payments->record([
        'vendor_id'           => $this->vendor->id,
        'payment_date'        => '2026-05-22',
        'amount'              => 100,
        'org_bank_account_id' => $this->bank->id,
        'allocations'         => [['vendor_invoice_id' => $inv->id, 'allocated_amount' => 60]],
    ], $this->creator))->toThrow(\DomainException::class, 'allocation');
});

it('void() reverses the JE, restores invoice amount_paid and status', function () {
    $inv = makeApprovedInvoice($this->invoices, $this->creator, $this->vendor, $this->expense, 200);
    $pay = $this->payments->record([
        'vendor_id'           => $this->vendor->id,
        'payment_date'        => '2026-05-22',
        'amount'              => 200,
        'org_bank_account_id' => $this->bank->id,
        'allocations'         => [['vendor_invoice_id' => $inv->id, 'allocated_amount' => 200]],
    ], $this->creator);

    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::Paid);

    $this->payments->void($pay, $this->creator, 'wrong amount');

    expect($pay->fresh()->status)->toBe(ApPaymentStatus::Voided);
    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::Approved);
    expect((float) $inv->fresh()->amount_paid)->toBe(0.0);
});

it('partial allocation flips invoice to PartiallyPaid', function () {
    $inv = makeApprovedInvoice($this->invoices, $this->creator, $this->vendor, $this->expense, 1000);

    $this->payments->record([
        'vendor_id'           => $this->vendor->id,
        'payment_date'        => '2026-05-22',
        'amount'              => 400,
        'org_bank_account_id' => $this->bank->id,
        'allocations'         => [['vendor_invoice_id' => $inv->id, 'allocated_amount' => 400]],
    ], $this->creator);

    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::PartiallyPaid);
    expect((float) $inv->fresh()->amount_paid)->toBe(400.0);
});

it('multi-invoice payment allocates to two invoices in one go', function () {
    $i1 = makeApprovedInvoice($this->invoices, $this->creator, $this->vendor, $this->expense, 100);
    $i2 = makeApprovedInvoice($this->invoices, $this->creator, $this->vendor, $this->expense, 250);

    $pay = $this->payments->record([
        'vendor_id'           => $this->vendor->id,
        'payment_date'        => '2026-05-22',
        'amount'              => 350,
        'org_bank_account_id' => $this->bank->id,
        'allocations'         => [
            ['vendor_invoice_id' => $i1->id, 'allocated_amount' => 100],
            ['vendor_invoice_id' => $i2->id, 'allocated_amount' => 250],
        ],
    ], $this->creator);

    expect($pay->allocations)->toHaveCount(2);
    expect($i1->fresh()->status)->toBe(VendorInvoiceStatus::Paid);
    expect($i2->fresh()->status)->toBe(VendorInvoiceStatus::Paid);
});
