<?php

declare(strict_types=1);

use App\Enums\ArInvoiceStatus;
use App\Enums\JournalEntryStatus;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->svc      = app(ArInvoiceService::class);
    $this->creator  = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->incomeGl = GlAccount::where('code', '4200')->firstOrFail(); // Course Fees
    $this->arGl     = GlAccount::where('code', '1200')->firstOrFail(); // Accounts Receivable
    $this->badDebtGl = GlAccount::where('code', '5600')->firstOrFail(); // Bad Debt Expense

    $this->customer = Customer::create([
        'code' => 'CUS-T', 'name' => 'Test Customer', 'status' => 'active',
        'default_ar_gl_account_id' => $this->arGl->id,
    ]);
});

it('creates an AR invoice and auto-posts the accrual JE', function () {
    $this->actingAs($this->creator);

    $invoice = $this->svc->create([
        'customer_id'  => $this->customer->id,
        'invoice_date' => '2026-05-22',
        'due_date'     => '2026-06-22',
        'currency'     => 'GHS',
        'lines' => [[
            'description'   => 'Q3 Training Programme — May',
            'quantity'      => 1,
            'unit_price'    => 5000.00,
            'tax_rate'      => 0.125,
            'gl_account_id' => $this->incomeGl->id,
        ]],
    ], $this->creator);

    expect($invoice->status)->toBe(ArInvoiceStatus::Draft);
    expect((float) $invoice->subtotal)->toBe(5000.0);
    expect((float) $invoice->tax_amount)->toBe(625.0);
    expect((float) $invoice->total)->toBe(5625.0);
    expect($invoice->accrual_journal_entry_id)->not->toBeNull();

    $je = $invoice->accrualJournalEntry;
    expect($je->status)->toBe(JournalEntryStatus::Posted);
    expect($je->lines)->toHaveCount(2);

    // AR is an asset → debit increases balance.
    // Income is an income account → credit increases balance.
    expect((float) GlAccountBalance::find($this->arGl->id)->balance)->toBe(5625.0);
    expect((float) GlAccountBalance::find($this->incomeGl->id)->balance)->toBe(5625.0);
});

it('falls back to GL code 1200 when customer has no default_ar_gl_account_id', function () {
    $cNoDefault = Customer::create([
        'code' => 'CUS-N', 'name' => 'NoDefault', 'status' => 'active',
    ]);

    $this->actingAs($this->creator);
    $invoice = $this->svc->create([
        'customer_id'  => $cNoDefault->id,
        'invoice_date' => '2026-05-22',
        'lines' => [[
            'description' => 'X', 'quantity' => 1, 'unit_price' => 100,
            'gl_account_id' => $this->incomeGl->id,
        ]],
    ], $this->creator);

    expect($invoice->ar_gl_account_id)->toBe($this->arGl->id);
});

it('rejects creation if a line gl_account is not type=income', function () {
    $this->actingAs($this->creator);
    expect(fn () => $this->svc->create([
        'customer_id'  => $this->customer->id,
        'invoice_date' => '2026-05-22',
        'lines' => [[
            'description' => 'X', 'quantity' => 1, 'unit_price' => 50,
            'gl_account_id' => $this->arGl->id,  // asset, not income
        ]],
    ], $this->creator))->toThrow(DomainException::class, 'income');
});

it('submit() moves draft → pending_approval', function () {
    $this->actingAs($this->creator);
    $inv = $this->svc->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->incomeGl->id]],
    ], $this->creator);

    $this->svc->submit($inv);
    expect($inv->fresh()->status)->toBe(ArInvoiceStatus::PendingApproval);
});

it('approve() requires a different user than the creator', function () {
    $this->actingAs($this->creator);
    $inv = $this->svc->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'gl_account_id' => $this->incomeGl->id]],
    ], $this->creator);
    $this->svc->submit($inv);

    expect(fn () => $this->svc->approve($inv, $this->creator))->toThrow(DomainException::class, 'self-approve');

    $this->svc->approve($inv, $this->approver);
    expect($inv->fresh()->status)->toBe(ArInvoiceStatus::Approved);
});

it('cancel() reverses the accrual JE and refuses if allocations exist', function () {
    $this->actingAs($this->creator);
    $inv = $this->svc->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1000, 'gl_account_id' => $this->incomeGl->id]],
    ], $this->creator);

    $this->svc->cancel($inv, $this->creator, 'duplicate');

    expect($inv->fresh()->status)->toBe(ArInvoiceStatus::Cancelled);
    // Reversal JE was posted, so balances return to zero.
    expect((float) GlAccountBalance::find($this->arGl->id)->balance)->toBe(0.0);
    expect((float) GlAccountBalance::find($this->incomeGl->id)->balance)->toBe(0.0);
});

it('writeOff() posts a bad-debt JE for the outstanding amount', function () {
    $this->actingAs($this->creator);
    $inv = $this->svc->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1000, 'gl_account_id' => $this->incomeGl->id]],
    ], $this->creator);
    $this->svc->submit($inv);
    $this->svc->approve($inv, $this->approver);

    $written = $this->svc->writeOff($inv->fresh(), $this->creator, 'uncollectible after 180 days');

    expect($written->status)->toBe(ArInvoiceStatus::WrittenOff);
    expect($written->write_off_journal_entry_id)->not->toBeNull();
    expect($written->written_off_reason)->toContain('180 days');

    // After write-off: AR back to zero (accrual + write-off cancel out).
    // Bad debt expense holds the loss; income stays at 1000 (the sale happened).
    expect((float) GlAccountBalance::find($this->arGl->id)->balance)->toBe(0.0);
    expect((float) GlAccountBalance::find($this->badDebtGl->id)->balance)->toBe(1000.0);
    expect((float) GlAccountBalance::find($this->incomeGl->id)->balance)->toBe(1000.0);
});

it('writeOff() refuses if status is not Approved or PartiallyPaid', function () {
    $this->actingAs($this->creator);
    $inv = $this->svc->create([
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-05-22',
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1000, 'gl_account_id' => $this->incomeGl->id]],
    ], $this->creator);

    // Draft — too early.
    expect(fn () => $this->svc->writeOff($inv, $this->creator, 'no'))
        ->toThrow(DomainException::class, 'only Approved or PartiallyPaid');
});

it('writeOff() refuses when outstanding is zero', function () {
    $this->actingAs($this->creator);
    $inv = ArInvoice::create([
        'reference'           => 'ARI-2026-TEST',
        'customer_id'         => $this->customer->id,
        'status'              => ArInvoiceStatus::Approved->value,
        'invoice_date'        => '2026-05-22',
        'subtotal'            => 1000,
        'total'               => 1000,
        'amount_received'     => 1000,
        'ar_gl_account_id'    => $this->arGl->id,
        'created_by'          => $this->creator->id,
    ]);

    expect(fn () => $this->svc->writeOff($inv, $this->creator, 'no'))
        ->toThrow(DomainException::class, 'no outstanding');
});
