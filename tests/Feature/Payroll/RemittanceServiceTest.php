<?php

declare(strict_types=1);

use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use App\Models\User;
use App\Services\Payroll\RemittanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GhanaStatutoryReferenceSeeder;
use Database\Seeders\GlAccountBalanceSeeder;
use Database\Seeders\OrgBankAccountSeeder;
use Database\Seeders\PostingAccountSeeder;

beforeEach(function () {
    $this->seed(GhanaStatutoryReferenceSeeder::class);
    // Marking a return filed now posts a remittance JE, so the GL prerequisites
    // (account map + an active bank account) must exist.
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    (new PostingAccountSeeder())->run();
    (new OrgBankAccountSeeder())->run();
    $this->svc = app(RemittanceService::class);
});

function makeReturn(string $periodEnd, ?string $submittedAt = null): StatutoryReturn
{
    $run = PayrollRun::create([
        'reference' => 'PR-' . uniqid(), 'period_year' => (int) substr($periodEnd, 0, 4),
        'period_month' => (int) substr($periodEnd, 5, 2),
        'period_start' => substr($periodEnd, 0, 8) . '01', 'period_end' => $periodEnd,
        'status' => 'approved', 'created_by' => User::factory()->create()->id,
    ]);

    return StatutoryReturn::create([
        'payroll_run_id' => $run->id, 'kind' => 'paye', 'file_path' => 'returns/x.csv',
        'total_amount' => 1000, 'record_count' => 3, 'generated_at' => now(),
        'submitted_at' => $submittedAt,
    ]);
}

it('computes the due date as period end + 14 days', function () {
    $r = makeReturn('2026-06-30');
    expect($this->svc->deadlineDays(CarbonImmutable::parse('2026-06-30')))->toBe(14)
        ->and($this->svc->dueDate($r)->toDateString())->toBe('2026-07-14');
});

it('flags an unsubmitted return as overdue only past the due date', function () {
    $r = makeReturn('2026-06-30');
    expect($this->svc->isOverdue($r, CarbonImmutable::parse('2026-07-10')))->toBeFalse() // before due
        ->and($this->svc->isOverdue($r, CarbonImmutable::parse('2026-07-20')))->toBeTrue() // after due
        ->and($this->svc->status($r, CarbonImmutable::parse('2026-07-20')))->toBe('overdue');
});

it('marks a return submitted and blocks double submission', function () {
    $r = makeReturn('2026-06-30');
    $by = User::factory()->create();

    $marked = $this->svc->markSubmitted($r, $by, 'GRA-REF-001');
    expect($marked->submitted_at)->not->toBeNull()
        ->and($marked->submitted_by)->toBe($by->id)
        ->and($marked->submission_reference)->toBe('GRA-REF-001')
        ->and($this->svc->status($marked))->toBe('submitted')
        ->and($this->svc->isOverdue($marked, CarbonImmutable::parse('2030-01-01')))->toBeFalse(); // submitted is never overdue

    expect(fn () => $this->svc->markSubmitted($marked->fresh(), $by, 'X'))->toThrow(DomainException::class);
});

it('reports posture counts using the proper deadline', function () {
    makeReturn('2026-06-30');                       // unsubmitted, due 2026-07-14
    makeReturn('2026-06-30', now()->toDateString()); // submitted

    $posture = $this->svc->posture(CarbonImmutable::parse('2026-08-01')); // well past due
    expect($posture['submitted'])->toBe(1)
        ->and($posture['overdue'])->toBe(1)
        ->and($posture['generated'])->toBe(1);
});
