<?php

declare(strict_types=1);

use App\Enums\ArInvoiceStatus;
use App\Enums\ArReceiptStatus;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\ArReceiptService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->invoiceSvc = app(ArInvoiceService::class);
    $this->receiptSvc = app(ArReceiptService::class);
    $this->creator    = User::factory()->create();
    $this->approver   = User::factory()->create();
    $this->incomeGl   = GlAccount::where('code', '4200')->firstOrFail();
    $this->arGl       = GlAccount::where('code', '1200')->firstOrFail();
    $this->bank       = OrgBankAccount::orderBy('id')->firstOrFail();

    $this->customer = Customer::create([
        'code' => 'CUS-T', 'name' => 'Test Customer', 'status' => 'active',
        'default_ar_gl_account_id' => $this->arGl->id,
    ]);

    $this->actingAs($this->creator);
    $this->invoice = $this->invoiceSvc->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 2000, 'gl_account_id' => $this->incomeGl->id]],
    ], $this->creator);
    $this->invoiceSvc->submit($this->invoice);
    $this->invoiceSvc->approve($this->invoice, $this->approver);
    $this->invoice = $this->invoice->fresh();
});

it('records a full receipt, posts the receipt JE, and flips the invoice to paid', function () {
    $receipt = $this->receiptSvc->record([
        'customer_id'         => $this->customer->id,
        'receipt_date'        => '2026-05-23',
        'amount'              => 2000,
        'org_bank_account_id' => $this->bank->id,
        'allocations' => [
            ['ar_invoice_id' => $this->invoice->id, 'allocated_amount' => 2000],
        ],
    ], $this->creator);

    expect($receipt->status)->toBe(ArReceiptStatus::Processed);
    expect($receipt->journal_entry_id)->not->toBeNull();
    expect($this->invoice->fresh()->status)->toBe(ArInvoiceStatus::Paid);
    expect((float) $this->invoice->fresh()->amount_received)->toBe(2000.0);

    // Bank GL up by 2000, AR back to zero.
    expect((float) GlAccountBalance::find($this->bank->gl_account_id)->balance)->toBe(2000.0);
    expect((float) GlAccountBalance::find($this->arGl->id)->balance)->toBe(0.0);
});

it('partial receipt flips invoice to partially_paid', function () {
    $this->receiptSvc->record([
        'customer_id'         => $this->customer->id,
        'receipt_date'        => '2026-05-23',
        'amount'              => 500,
        'org_bank_account_id' => $this->bank->id,
        'allocations' => [
            ['ar_invoice_id' => $this->invoice->id, 'allocated_amount' => 500],
        ],
    ], $this->creator);

    expect($this->invoice->fresh()->status)->toBe(ArInvoiceStatus::PartiallyPaid);
    expect((float) $this->invoice->fresh()->amount_received)->toBe(500.0);
});

it('rejects record() when allocation sum != receipt amount', function () {
    expect(fn () => $this->receiptSvc->record([
        'customer_id'         => $this->customer->id,
        'receipt_date'        => '2026-05-23',
        'amount'              => 1000,
        'org_bank_account_id' => $this->bank->id,
        'allocations' => [
            ['ar_invoice_id' => $this->invoice->id, 'allocated_amount' => 500],
        ],
    ], $this->creator))->toThrow(DomainException::class, 'does not equal');
});

it('rejects record() when allocation exceeds invoice outstanding', function () {
    expect(fn () => $this->receiptSvc->record([
        'customer_id'         => $this->customer->id,
        'receipt_date'        => '2026-05-23',
        'amount'              => 5000,
        'org_bank_account_id' => $this->bank->id,
        'allocations' => [
            ['ar_invoice_id' => $this->invoice->id, 'allocated_amount' => 5000],
        ],
    ], $this->creator))->toThrow(DomainException::class, 'exceeds outstanding');
});

it('void() reverses the JE and restores invoice status + amount_received', function () {
    $receipt = $this->receiptSvc->record([
        'customer_id'         => $this->customer->id,
        'receipt_date'        => '2026-05-23',
        'amount'              => 2000,
        'org_bank_account_id' => $this->bank->id,
        'allocations' => [
            ['ar_invoice_id' => $this->invoice->id, 'allocated_amount' => 2000],
        ],
    ], $this->creator);

    expect($this->invoice->fresh()->status)->toBe(ArInvoiceStatus::Paid);

    $this->receiptSvc->void($receipt, $this->creator, 'recorded against wrong customer');

    expect($receipt->fresh()->status)->toBe(ArReceiptStatus::Voided);
    expect($this->invoice->fresh()->status)->toBe(ArInvoiceStatus::Approved);
    expect((float) $this->invoice->fresh()->amount_received)->toBe(0.0);
    // Bank back to zero, AR back to 2000.
    expect((float) GlAccountBalance::find($this->bank->gl_account_id)->balance)->toBe(0.0);
    expect((float) GlAccountBalance::find($this->arGl->id)->balance)->toBe(2000.0);
});
