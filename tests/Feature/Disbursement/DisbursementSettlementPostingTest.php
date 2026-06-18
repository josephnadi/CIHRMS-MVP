<?php

declare(strict_types=1);

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\Department;
use App\Models\Disbursement;
use App\Models\Employee;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\OrgBankAccount;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\Contracts\DisbursementProvider;
use App\Services\Disbursement\DisbursementResult;
use App\Services\Finance\PostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    (new OrgBankAccountSeeder())->run(); // creates an active Payroll-purpose account → GL 1110

    // Settlement posts without an explicit actor; give the PostingActorResolver
    // a system-user fallback so created_by is never null in this no-auth context.
    User::factory()->create(['role' => 'super_admin']);

    $this->dept = Department::factory()->create();
    $this->employee = Employee::factory()->create([
        'department_id' => $this->dept->id,
        'disbursement_channel' => DisbursementChannel::MtnMomo->value,
        'mobile_money_number' => '0244000001',
    ]);
    $this->run = PayrollRun::create([
        'reference' => 'PR-2026-05-ORG', 'period_year' => 2026, 'period_month' => 5,
        'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'status' => 'calculated',
    ]);
    PayrollLine::create([
        'payroll_run_id' => $this->run->id, 'employee_id' => $this->employee->id,
        'basic' => 5000, 'allowance_total' => 0, 'gross' => 5000,
        'ssnit_base' => 5000, 'ssnit_tier1_employee' => 275, 'ssnit_tier1_employer' => 650,
        'nhia_split' => 125, 'tier2_employer' => 250, 'tier3_employee' => 0,
        'paye' => 600, 'voluntary_deductions' => 0, 'net' => 4125, 'status' => 'calculated',
    ]);
});

function settlingProvider(): DisbursementProvider
{
    return new class implements DisbursementProvider {
        public function channel(): string { return DisbursementChannel::MtnMomo->value; }
        public function send(Disbursement $d): DisbursementResult { return DisbursementResult::settled('REF-1'); }
        public function refreshStatus(Disbursement $d): DisbursementResult { return DisbursementResult::settled('REF-1'); }
    };
}

it('posts a balanced settlement JE when a disbursement settles via dispatch', function () {
    $svc = new BatchDisbursementService(
        [DisbursementChannel::MtnMomo->value => settlingProvider()],
        app(PostingService::class),
    );
    $svc->materialise($this->run);
    $svc->dispatch($this->run);

    $d = Disbursement::where('payroll_run_id', $this->run->id)->firstOrFail();
    expect($d->status)->toBe(DisbursementStatus::Settled);

    $je = JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_id', $d->id)->where('source_purpose', 'settlement')->firstOrFail();

    expect($je->status)->toBe(JournalEntryStatus::Posted)
        ->and($je->isBalanced())->toBeTrue();

    $netPay = GlAccount::where('code', '2300')->firstOrFail();
    $bank   = GlAccount::where('code', '1110')->firstOrFail();
    $drLine = $je->lines->firstWhere('gl_account_id', $netPay->id);
    $crLine = $je->lines->firstWhere('gl_account_id', $bank->id);

    expect((float) $drLine->debit_amount)->toBe(4125.0)
        ->and((float) $crLine->credit_amount)->toBe(4125.0);
});

it('posts a settlement JE when a disbursement settles via reconcile', function () {
    $stub = new class implements DisbursementProvider {
        public function channel(): string { return DisbursementChannel::MtnMomo->value; }
        public function send(Disbursement $d): DisbursementResult { return DisbursementResult::sent('REF-2', []); }
        public function refreshStatus(Disbursement $d): DisbursementResult { return DisbursementResult::settled('REF-2'); }
    };
    $svc = new BatchDisbursementService([DisbursementChannel::MtnMomo->value => $stub], app(PostingService::class));
    $svc->materialise($this->run);
    $svc->dispatch($this->run); // → Sent

    Disbursement::where('payroll_run_id', $this->run->id)->update(['sent_at' => now()->subMinutes(10)]);
    $svc->reconcile($this->run); // → Settled, posts JE

    $d = Disbursement::where('payroll_run_id', $this->run->id)->firstOrFail();
    expect($d->status)->toBe(DisbursementStatus::Settled);

    $je = JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_id', $d->id)->where('source_purpose', 'settlement')->firstOrFail();
    expect($je->isBalanced())->toBeTrue();
});

it('throws when no active payroll bank account is configured', function () {
    OrgBankAccount::query()->update(['is_active' => false]);

    $svc = new BatchDisbursementService(
        [DisbursementChannel::MtnMomo->value => settlingProvider()],
        app(PostingService::class),
    );
    $svc->materialise($this->run);

    expect(fn () => $svc->dispatch($this->run))->toThrow(DomainException::class);
});
