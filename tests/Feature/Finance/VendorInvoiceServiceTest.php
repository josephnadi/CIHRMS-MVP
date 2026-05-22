<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Enums\VendorInvoiceStatus;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Services\Finance\VendorInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->svc       = app(VendorInvoiceService::class);
    $this->creator   = User::factory()->create();
    $this->expenseGl = GlAccount::where('code', '5200')->firstOrFail();
    $this->apGl      = GlAccount::where('code', '2100')->firstOrFail();

    $this->vendor = Vendor::create([
        'code' => 'VEN-T', 'name' => 'Test Vendor', 'status' => 'active',
        'default_ap_gl_account_id' => $this->apGl->id,
    ]);
});

it('creates an invoice and auto-posts the accrual JE', function () {
    $this->actingAs($this->creator);

    $invoice = $this->svc->create([
        'vendor_id'        => $this->vendor->id,
        'vendor_invoice_no'=> 'INV-001',
        'invoice_date'     => '2026-05-22',
        'due_date'         => '2026-06-22',
        'currency'         => 'GHS',
        'lines' => [[
            'description'   => 'Office supplies — May',
            'quantity'      => 1,
            'unit_price'    => 800.00,
            'tax_rate'      => 0.125,
            'gl_account_id' => $this->expenseGl->id,
        ]],
    ], $this->creator);

    expect($invoice->status)->toBe(VendorInvoiceStatus::Draft);
    expect((float) $invoice->subtotal)->toBe(800.0);
    expect((float) $invoice->tax_amount)->toBe(100.0);
    expect((float) $invoice->total)->toBe(900.0);
    expect($invoice->accrual_journal_entry_id)->not->toBeNull();

    $je = $invoice->accrualJournalEntry;
    expect($je->status)->toBe(JournalEntryStatus::Posted);
    expect($je->lines)->toHaveCount(2);

    expect((float) GlAccountBalance::find($this->expenseGl->id)->balance)->toBe(900.0);
    expect((float) GlAccountBalance::find($this->apGl->id)->balance)->toBe(900.0);
});

it('uses fallback AP code 2100 when vendor has no default_ap_gl_account_id', function () {
    $vendorNoDefault = Vendor::create([
        'code' => 'VEN-N', 'name' => 'NoDefault', 'status' => 'active',
    ]);

    $this->actingAs($this->creator);
    $invoice = $this->svc->create([
        'vendor_id'    => $vendorNoDefault->id,
        'invoice_date' => '2026-05-22',
        'lines' => [[
            'description' => 'Stuff', 'quantity' => 1, 'unit_price' => 100,
            'gl_account_id' => $this->expenseGl->id,
        ]],
    ], $this->creator);

    expect($invoice->ap_gl_account_id)->toBe($this->apGl->id);
});

it('rejects creation if a line gl_account is not type=expense', function () {
    $this->actingAs($this->creator);
    expect(fn () => $this->svc->create([
        'vendor_id'    => $this->vendor->id,
        'invoice_date' => '2026-05-22',
        'lines' => [[
            'description' => 'X', 'quantity' => 1, 'unit_price' => 50,
            'gl_account_id' => $this->apGl->id,
        ]],
    ], $this->creator))->toThrow(\DomainException::class, 'expense');
});

it('submit() moves draft → pending_approval', function () {
    $this->actingAs($this->creator);
    $inv = $this->svc->create([
        'vendor_id' => $this->vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->expenseGl->id]],
    ], $this->creator);

    $this->svc->submit($inv);
    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::PendingApproval);
});

it('approve() requires approver !== creator', function () {
    $this->actingAs($this->creator);
    $inv = $this->svc->create([
        'vendor_id' => $this->vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->expenseGl->id]],
    ], $this->creator);
    $this->svc->submit($inv);

    expect(fn () => $this->svc->approve($inv->fresh(), $this->creator))
        ->toThrow(\DomainException::class, 'creator');

    $approver = User::factory()->create();
    $this->svc->approve($inv->fresh(), $approver);
    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::Approved);
});

it('cancel() reverses the accrual JE and zero-outs balances', function () {
    $this->actingAs($this->creator);
    $inv = $this->svc->create([
        'vendor_id' => $this->vendor->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->expenseGl->id]],
    ], $this->creator);

    expect((float) GlAccountBalance::find($this->expenseGl->id)->balance)->toBe(100.0);

    $this->svc->cancel($inv->fresh(), $this->creator, 'duplicate');

    expect($inv->fresh()->status)->toBe(VendorInvoiceStatus::Cancelled);
    expect((float) GlAccountBalance::find($this->expenseGl->id)->balance)->toBe(0.0);
    expect((float) GlAccountBalance::find($this->apGl->id)->balance)->toBe(0.0);
});
