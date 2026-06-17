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
});

function draftJe(): JournalEntry
{
    $cash = GlAccount::where('code', '1010')->firstOrFail();   // asset
    $income = GlAccount::where('code', '4100')->firstOrFail(); // income

    $je = JournalEntry::create([
        'reference'   => 'JE-ACTOR-' . uniqid(),
        'entry_date'  => '2026-06-17',
        'narration'   => 'actor test',
        'status'      => JournalEntryStatus::Draft->value,
        'source_type' => JournalSourceType::Manual->value,
        'source_id'   => null,
        'created_by'  => User::factory()->create()->id,
    ]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $cash->id,   'debit_amount' => 100, 'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $income->id, 'debit_amount' => 0,   'credit_amount' => 100]);

    return $je->fresh('lines.glAccount');
}

it('stamps posted_by from an explicit actor even with no auth', function () {
    $actor = User::factory()->create();

    $posted = app(JournalPostingService::class)->post(draftJe(), $actor);

    expect($posted->posted_by)->toBe($actor->id);
});

it('stamps posted_by from the authenticated user when no actor is passed (backward compatible)', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    $posted = app(JournalPostingService::class)->post(draftJe());

    expect($posted->posted_by)->toBe($auth->id);
});

it('falls back to the system super_admin instead of null when there is no auth and no actor', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $posted = app(JournalPostingService::class)->post(draftJe());

    expect($posted->posted_by)->toBe($admin->id);
});

it('attributes the reversal entry to the reversing user', function () {
    $poster = User::factory()->create();
    $posted = app(JournalPostingService::class)->post(draftJe(), $poster);

    $by = User::factory()->create();
    $reversal = app(JournalPostingService::class)->reverse($posted, $by, 'correction');

    // Without threading $by into the internal post(), posted_by would fall back
    // to auth()/system (null here) — so this proves the reversal is attributed to $by.
    expect($reversal->posted_by)->toBe($by->id)
        ->and($reversal->created_by)->toBe($by->id);
});
