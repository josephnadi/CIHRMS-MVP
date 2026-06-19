<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalYear;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\BudgetStatusService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

function approvedBudget(int $year, array $codeToAnnual): void
{
    $fy = FiscalYear::firstOrCreate(['year' => $year],
        ['status' => 'open', 'starts_on' => "$year-01-01", 'ends_on' => "$year-12-31"]);
    $budget = Budget::create(['fiscal_year_id' => $fy->id, 'status' => 'approved']);
    foreach ($codeToAnnual as $code => $annual) {
        BudgetLine::create(['budget_id' => $budget->id,
            'gl_account_id' => GlAccount::where('code', $code)->value('id'), 'annual_amount' => $annual]);
    }
}

function spend(string $code, float $amount, string $date): void
{
    $acc  = GlAccount::where('code', $code)->firstOrFail();
    $cash = GlAccount::where('code', '1100')->firstOrFail();
    $debitNatural = in_array($acc->type->value, ['asset', 'expense'], true);

    $je = JournalEntry::create([
        'reference' => 'JE-BSS-' . $code . '-' . $date, 'entry_date' => $date, 'narration' => 'bss',
        'status' => 'posted', 'source_type' => 'manual', 'source_id' => null,
        'created_by' => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $acc->id,
        'debit_amount' => $debitNatural ? $amount : 0, 'credit_amount' => $debitNatural ? 0 : $amount]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $cash->id,
        'debit_amount' => $debitNatural ? 0 : $amount, 'credit_amount' => $debitNatural ? $amount : 0]);
}

it('reports remaining budget as approved annual minus actuals to date', function () {
    approvedBudget(2026, ['5100' => 120000]);
    spend('5100', 30000, '2026-04-10');

    $svc = app(BudgetStatusService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    expect($svc->remaining($acc, CarbonImmutable::create(2026, 6, 30)))->toBe(90000.0); // 120000 - 30000
});

it('returns a negative remaining when over budget (advisory, still computed)', function () {
    approvedBudget(2026, ['5100' => 100000]);
    spend('5100', 130000, '2026-05-01');

    $svc = app(BudgetStatusService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    expect($svc->remaining($acc, CarbonImmutable::create(2026, 12, 31)))->toBe(-30000.0);
});

it('treats a draft or absent budget as zero annual budget', function () {
    // No approved budget for 2026 at all.
    spend('5100', 5000, '2026-03-01');

    $svc = app(BudgetStatusService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();

    expect($svc->remaining($acc, CarbonImmutable::create(2026, 6, 30)))->toBe(-5000.0); // 0 - 5000
});

it('never blocks: an over-budget account can still be posted to', function () {
    approvedBudget(2026, ['5100' => 1000]);
    spend('5100', 5000, '2026-02-01'); // already way over

    $svc = app(BudgetStatusService::class);
    $acc = GlAccount::where('code', '5100')->firstOrFail();
    expect($svc->remaining($acc, CarbonImmutable::create(2026, 6, 30)))->toBeLessThan(0.0);

    // A further posting succeeds without any budget guard throwing.
    spend('5100', 2000, '2026-06-01');
    expect($svc->remaining($acc, CarbonImmutable::create(2026, 6, 30)))->toBe(-6000.0); // 1000 - 7000
});

it('lists over-budget accounts worst-first, excluding favourable and neutral rows', function () {
    approvedBudget(2026, ['5100' => 100000, '5110' => 50000, '4100' => 60000]);
    spend('5100', 130000, '2026-06-01'); // expense over by 30k (worst)
    spend('5110', 60000, '2026-06-01');  // expense over by 10k
    spend('4100', 70000, '2026-06-01');  // income over target → favourable, NOT an alert

    $alerts = app(BudgetStatusService::class)->overBudgetAlerts(2026, 12);

    expect($alerts)->toHaveCount(2)
        ->and($alerts[0]['code'])->toBe('5100')        // most negative variance first
        ->and($alerts[0]['variance'])->toBe(-30000.0)
        ->and($alerts[1]['code'])->toBe('5110')
        ->and(collect($alerts)->pluck('code')->all())->not->toContain('4100');
});
