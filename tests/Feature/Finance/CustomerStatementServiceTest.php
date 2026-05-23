<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\ArInvoiceService;
use App\Services\Finance\ArReceiptService;
use App\Services\Finance\CustomerStatementService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    (new GlAccountBalanceSeeder())->run();

    $this->invoiceSvc   = app(ArInvoiceService::class);
    $this->receiptSvc   = app(ArReceiptService::class);
    $this->statementSvc = app(CustomerStatementService::class);
    $this->creator      = User::factory()->create();
    $this->approver     = User::factory()->create();
    $this->incomeGl     = GlAccount::where('code', '4200')->firstOrFail();
    $this->arGl         = GlAccount::where('code', '1200')->firstOrFail();
    $this->bank         = OrgBankAccount::orderBy('id')->firstOrFail();

    $this->customer = Customer::create([
        'code' => 'CUS-S', 'name' => 'Statement Test', 'status' => 'active',
        'default_ar_gl_account_id' => $this->arGl->id,
    ]);

    $this->actingAs($this->creator);
});

/** Helper — create + approve an invoice on a given date. */
function approvedInvoice($t, string $invDate, float $amount, ?string $dueDate = null) {
    $inv = $t->invoiceSvc->create([
        'customer_id'  => $t->customer->id,
        'invoice_date' => $invDate,
        'due_date'     => $dueDate,
        'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => $amount, 'gl_account_id' => $t->incomeGl->id]],
    ], $t->creator);
    $t->invoiceSvc->submit($inv);
    $t->invoiceSvc->approve($inv, $t->approver);
    return $inv->fresh();
}

it('generates a statement with running balance', function () {
    approvedInvoice($this, '2026-05-05', 1000);
    approvedInvoice($this, '2026-05-12', 2000);

    $stmt = $this->statementSvc->generate(
        $this->customer,
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
    );

    expect($stmt['opening_balance'])->toBe(0.0);
    expect($stmt['lines'])->toHaveCount(2);
    expect($stmt['lines'][0]['running_balance'])->toBe(1000.0);
    expect($stmt['lines'][1]['running_balance'])->toBe(3000.0);
    expect($stmt['closing_balance'])->toBe(3000.0);
});

it('opening balance reflects invoices dated before the from-date', function () {
    approvedInvoice($this, '2026-04-15', 1500);   // before the window — should land in opening
    approvedInvoice($this, '2026-05-10', 500);    // inside the window

    $stmt = $this->statementSvc->generate(
        $this->customer,
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
    );

    expect($stmt['opening_balance'])->toBe(1500.0);
    expect($stmt['lines'])->toHaveCount(1);
    expect($stmt['closing_balance'])->toBe(2000.0);
});

it('receipts inside the window reduce the running balance', function () {
    $inv = approvedInvoice($this, '2026-05-05', 1000);
    $this->receiptSvc->record([
        'customer_id'         => $this->customer->id,
        'receipt_date'        => '2026-05-20',
        'amount'              => 400,
        'org_bank_account_id' => $this->bank->id,
        'allocations' => [['ar_invoice_id' => $inv->id, 'allocated_amount' => 400]],
    ], $this->creator);

    $stmt = $this->statementSvc->generate(
        $this->customer,
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
    );

    expect($stmt['closing_balance'])->toBe(600.0);
});

it('aging buckets correctly classify overdue invoices', function () {
    Illuminate\Support\Facades\Cache::flush();

    \Carbon\Carbon::setTestNow('2026-05-23');

    approvedInvoice($this, '2026-05-01', 100, '2026-05-30');  // current
    approvedInvoice($this, '2026-04-01', 200, '2026-05-01');  // 22 days overdue → 30 bucket
    approvedInvoice($this, '2026-03-01', 300, '2026-03-20');  // ~64 days overdue → 90+ bucket

    Illuminate\Support\Facades\Cache::flush();
    $aging = $this->statementSvc->aging($this->customer);

    expect($aging['current'])->toBe(100.0);
    expect($aging['30'])->toBe(200.0);
    expect($aging['90_plus'])->toBe(300.0);

    \Carbon\Carbon::setTestNow();
});
