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
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Disbursement\BatchDisbursementService;
use App\Services\Disbursement\Providers\GhIpssAchProvider;
use App\Services\Finance\PostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    User::factory()->create(['role' => 'super_admin']); // posting actor fallback

    $this->dept = Department::factory()->create();
    $this->employee = Employee::factory()->create([
        'department_id'        => $this->dept->id,
        'disbursement_channel' => DisbursementChannel::GhipssAch->value,
        'bank_account'         => '1234567890',
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

    $ghipss = new GhIpssAchProvider('05010', 'CIHRM');
    $this->svc = new BatchDisbursementService(
        [DisbursementChannel::GhipssAch->value => $ghipss],
        app(PostingService::class),
    );
});

it('GhIPSS rows stay Sent after dispatch + reconcile — no auto-settlement', function () {
    $this->svc->materialise($this->run);
    $this->svc->dispatch($this->run);                 // → Sent (staged for batch)
    Disbursement::where('payroll_run_id', $this->run->id)->update(['sent_at' => now()->subMinutes(10)]);
    $this->svc->reconcile($this->run);                // GhIPSS has no status API → no change

    $d = Disbursement::where('payroll_run_id', $this->run->id)->firstOrFail();
    expect($d->status)->toBe(DisbursementStatus::Sent);

    // No settlement JE yet → net-pay payable is still outstanding.
    expect(JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_purpose', 'settlement')->exists())->toBeFalse();
});

it('confirmGhipssSettlement settles Sent GhIPSS rows and posts the clearing JE', function () {
    $this->svc->materialise($this->run);
    $this->svc->dispatch($this->run); // → Sent

    $count = $this->svc->confirmGhipssSettlement($this->run);
    expect($count)->toBe(1);

    $d = Disbursement::where('payroll_run_id', $this->run->id)->firstOrFail();
    expect($d->status)->toBe(DisbursementStatus::Settled)
        ->and($d->settled_at)->not->toBeNull();

    $je = JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_id', $d->id)->where('source_purpose', 'settlement')->firstOrFail();

    expect($je->status)->toBe(JournalEntryStatus::Posted)
        ->and($je->isBalanced())->toBeTrue();

    $netPay = GlAccount::where('code', '2300')->firstOrFail();
    $bank   = GlAccount::where('code', '1110')->firstOrFail();
    expect((float) $je->lines->firstWhere('gl_account_id', $netPay->id)->debit_amount)->toBe(4125.0)
        ->and((float) $je->lines->firstWhere('gl_account_id', $bank->id)->credit_amount)->toBe(4125.0);
});

it('confirmGhipssSettlement is idempotent and ignores non-GhIPSS Sent rows', function () {
    $this->svc->materialise($this->run);
    $this->svc->dispatch($this->run);

    $this->svc->confirmGhipssSettlement($this->run);
    $second = $this->svc->confirmGhipssSettlement($this->run); // nothing left in Sent

    expect($second)->toBe(0);
    expect(JournalEntry::where('source_type', JournalSourceType::Disbursement->value)
        ->where('source_purpose', 'settlement')->count())->toBe(1);
});

it('the confirm-ghipss endpoint requires payroll.disburse and settles the batch', function () {
    $this->svc->materialise($this->run);
    $this->svc->dispatch($this->run); // → Sent

    // Without the permission: forbidden.
    $noPerm = User::factory()->create(['role' => 'employee', 'permissions' => []]);
    $this->actingAs($noPerm)
        ->post(route('disbursements.confirm-ghipss', $this->run))
        ->assertForbidden();

    // With it: settles and redirects back with a flash.
    $officer = User::factory()->create(['role' => 'finance_officer', 'permissions' => ['payroll.disburse']]);
    $this->actingAs($officer)
        ->post(route('disbursements.confirm-ghipss', $this->run))
        ->assertRedirect();

    expect(Disbursement::where('payroll_run_id', $this->run->id)->firstOrFail()->status)
        ->toBe(DisbursementStatus::Settled);
});
