<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\JournalPostingService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\GlAccountBalanceSeeder;

beforeEach(function () {
    (new ChartOfAccountsSeeder())->run();
    (new GlAccountBalanceSeeder())->run();
    $this->actingAs(User::factory()->create());
});

function postedEntry(): JournalEntry
{
    $cash = GlAccount::where('code', '1010')->firstOrFail();
    $income = GlAccount::where('code', '4100')->firstOrFail();
    $je = JournalEntry::create([
        'reference' => 'JE-IMM-' . uniqid(), 'entry_date' => '2026-06-15', 'narration' => 'imm',
        'status' => JournalEntryStatus::Draft->value, 'source_type' => JournalSourceType::Manual->value,
        'source_id' => null, 'created_by' => auth()->id(),
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $cash->id, 'debit_amount' => 50, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $income->id, 'debit_amount' => 0, 'credit_amount' => 50]);
    return app(JournalPostingService::class)->post($je->fresh('lines.glAccount'));
}

it('blocks updating a line on a posted entry', function () {
    $entry = postedEntry();
    $line = $entry->lines()->first();
    expect(fn () => $line->update(['narration' => 'tampered']))->toThrow(DomainException::class);
});

it('blocks deleting a line on a posted entry', function () {
    $entry = postedEntry();
    $line = $entry->lines()->first();
    expect(fn () => $line->delete())->toThrow(DomainException::class);
});

it('blocks deleting a posted entry', function () {
    $entry = postedEntry();
    expect(fn () => $entry->delete())->toThrow(DomainException::class);
});

it('still allows editing a draft entry and its lines', function () {
    $cash = GlAccount::where('code', '1010')->firstOrFail();
    $je = JournalEntry::create([
        'reference' => 'JE-DRAFT-OK', 'entry_date' => '2026-06-15', 'narration' => 'draft',
        'status' => JournalEntryStatus::Draft->value, 'source_type' => JournalSourceType::Manual->value,
        'source_id' => null, 'created_by' => auth()->id(),
    ]);
    $line = JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $cash->id, 'debit_amount' => 50, 'credit_amount' => 0]);

    $line->update(['debit_amount' => 75]);   // allowed on a draft
    expect((float) $line->fresh()->debit_amount)->toBe(75.0);

    $je->delete();                            // draft delete allowed
    expect(JournalEntry::find($je->id))->toBeNull();
});
