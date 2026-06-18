<?php

declare(strict_types=1);

use App\Enums\FiscalPeriodStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Exceptions\Finance\ClosedPeriodException;
use App\Models\FiscalPeriod;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\JournalPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $this->actingAs(User::factory()->create());
});

function draftEntryDated(string $date): JournalEntry
{
    $cash = GlAccount::where('code', '1010')->firstOrFail();
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $je = JournalEntry::create([
        'reference' => 'JE-GUARD-' . uniqid(), 'entry_date' => $date, 'narration' => 'guard',
        'status' => JournalEntryStatus::Draft->value, 'source_type' => JournalSourceType::Manual->value,
        'source_id' => null, 'created_by' => auth()->id(),
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $cash->id, 'debit_amount' => 50, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $income->id, 'debit_amount' => 0, 'credit_amount' => 50]);
    return $je->fresh('lines.glAccount');
}

it('posts and stamps fiscal_period_id when the period is open', function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $jun = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 6)->firstOrFail();

    $posted = app(JournalPostingService::class)->post(draftEntryDated('2026-06-15'));

    expect($posted->status)->toBe(JournalEntryStatus::Posted)
        ->and($posted->fiscal_period_id)->toBe($jun->id);
});

it('blocks posting into a closed period', function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 6)
        ->update(['status' => FiscalPeriodStatus::Closed->value]);

    expect(fn () => app(JournalPostingService::class)->post(draftEntryDated('2026-06-15')))
        ->toThrow(ClosedPeriodException::class);
});

it('blocks posting into a locked period', function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 6)
        ->update(['status' => FiscalPeriodStatus::Locked->value]);

    expect(fn () => app(JournalPostingService::class)->post(draftEntryDated('2026-06-15')))
        ->toThrow(ClosedPeriodException::class);
});

it('allows posting when no fiscal period is defined for the date (no stamp)', function () {
    // No fiscal year seeded for 2099.
    $posted = app(JournalPostingService::class)->post(draftEntryDated('2099-03-03'));

    expect($posted->status)->toBe(JournalEntryStatus::Posted)
        ->and($posted->fiscal_period_id)->toBeNull();
});
