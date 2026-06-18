<?php

declare(strict_types=1);

use App\Enums\AmortizationMethod;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\LoanProductType;
use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\JournalEntry;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Loans\LoanService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    (new OrgBankAccountSeeder())->run(); // active Operating-purpose account → GL 1100

    $this->product = LoanProduct::create([
        'code' => 'TST-001', 'name' => 'Test Personal', 'type' => LoanProductType::Personal->value,
        'min_amount' => 1_000, 'max_amount' => 50_000, 'min_term_months' => 3, 'max_term_months' => 36,
        'annual_interest_rate' => 0.12, 'amortization_method' => AmortizationMethod::ReducingBalance->value,
        'is_active' => true, 'effective_from' => '2026-01-01', 'approvals_required' => 2,
    ]);
    $this->applicant = User::factory()->create(['role' => 'employee']);
    $this->approver  = User::factory()->create(['role' => 'finance_officer']);
    $this->employee  = Employee::factory()->create(['user_id' => $this->applicant->id]);
    $this->svc = app(LoanService::class);
});

it('posts a balanced disbursement JE: Dr Loan Receivable / Cr operating bank', function () {
    $loan = $this->svc->apply($this->employee, $this->product, 6_000, 6, null, $this->applicant);
    $loan = $this->svc->approve($loan, $this->approver);
    $loan = $this->svc->disburse($loan, $this->approver, CarbonImmutable::create(2026, 7, 1));

    $je = JournalEntry::where('source_type', JournalSourceType::LoanDisbursement->value)
        ->where('source_id', $loan->id)->where('source_purpose', 'disbursement')->firstOrFail();

    expect($je->status)->toBe(JournalEntryStatus::Posted)
        ->and($je->posted_by)->toBe($this->approver->id)
        ->and($je->isBalanced())->toBeTrue();

    $recv = GlAccount::where('code', '1300')->firstOrFail();
    $bank = GlAccount::where('code', '1100')->firstOrFail();
    $drLine = $je->lines->firstWhere('gl_account_id', $recv->id);
    $crLine = $je->lines->firstWhere('gl_account_id', $bank->id);

    expect((float) $drLine->debit_amount)->toBe(6000.0)
        ->and((float) $crLine->credit_amount)->toBe(6000.0);

    expect((float) GlAccountBalance::where('gl_account_id', $recv->id)->value('balance'))->toBe(6000.0)
        ->and((float) GlAccountBalance::where('gl_account_id', $bank->id)->value('balance'))->toBe(-6000.0);
});

it('throws when no active operating bank account is configured', function () {
    \App\Models\OrgBankAccount::query()->update(['is_active' => false]);

    $loan = $this->svc->apply($this->employee, $this->product, 6_000, 6, null, $this->applicant);
    $loan = $this->svc->approve($loan, $this->approver);

    expect(fn () => $this->svc->disburse($loan, $this->approver, CarbonImmutable::create(2026, 7, 1)))
        ->toThrow(DomainException::class);
});
