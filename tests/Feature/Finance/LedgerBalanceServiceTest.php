<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Finance\LedgerBalanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => (new ChartOfAccountsSeeder())->run());

/** Create a posted (or draft) balanced 2-line entry: DR $drCode / CR $crCode for $amount. */
function ledgerEntry(string $drCode, string $crCode, float $amount, string $date, string $status = 'posted'): JournalEntry
{
    $dr = GlAccount::where('code', $drCode)->firstOrFail();
    $cr = GlAccount::where('code', $crCode)->firstOrFail();
    $je = JournalEntry::create([
        'reference'   => 'JE-LB-' . uniqid(),
        'entry_date'  => $date,
        'narration'   => 'ledger test',
        'status'      => $status,
        'source_type' => JournalSourceType::Manual->value,
        'source_id'   => null,
        'created_by'  => \App\Models\User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $dr->id, 'debit_amount' => $amount, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $cr->id, 'debit_amount' => 0, 'credit_amount' => $amount]);
    return $je;
}

it('aggregates posted lines as-of a date with natural balances', function () {
    ledgerEntry('5100', '2300', 1000.0, '2026-06-15'); // DR Salaries Expense / CR Salaries Payable

    $rows = app(LedgerBalanceService::class)->asOf(CarbonImmutable::create(2026, 6, 30))->keyBy('code');

    expect((float) $rows['5100']->debit_total)->toBe(1000.0)
        ->and((float) $rows['5100']->natural_balance)->toBe(1000.0)   // expense: debit - credit
        ->and((float) $rows['2300']->credit_total)->toBe(1000.0)
        ->and((float) $rows['2300']->natural_balance)->toBe(1000.0);  // liability: credit - debit
});

it('excludes draft and is date-bounded', function () {
    ledgerEntry('5100', '2300', 1000.0, '2026-06-15', status: 'posted');
    ledgerEntry('5100', '2300', 500.0, '2026-06-15', status: 'draft'); // excluded

    $svc = app(LedgerBalanceService::class);

    // as-of after the entry: only the posted 1000 counts
    expect((float) $svc->asOf(CarbonImmutable::create(2026, 6, 30))->firstWhere('code', '5100')->natural_balance)->toBe(1000.0);

    // as-of before the entry: nothing
    expect($svc->asOf(CarbonImmutable::create(2026, 5, 31))->firstWhere('code', '5100'))->toBeNull();

    // period activity in July: nothing (entry is in June)
    expect($svc->activity(CarbonImmutable::create(2026, 7, 1), CarbonImmutable::create(2026, 7, 31))->firstWhere('code', '5100'))->toBeNull();

    // period activity in June: the 1000
    expect((float) $svc->activity(CarbonImmutable::create(2026, 6, 1), CarbonImmutable::create(2026, 6, 30))->firstWhere('code', '5100')->natural_balance)->toBe(1000.0);
});
