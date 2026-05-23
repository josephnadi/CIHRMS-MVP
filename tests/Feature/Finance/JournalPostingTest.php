<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Models\GlAccount;
use App\Models\GlAccountBalance;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Finance\JournalPostingService;

beforeEach(function () {
    $this->svc  = app(JournalPostingService::class);
    $this->user = User::factory()->create();

    $this->expense = GlAccount::create(['code' => '5100', 'name' => 'Salary Exp', 'type' => 'expense']);
    $this->ap      = GlAccount::create(['code' => '2100', 'name' => 'AP',         'type' => 'liability']);
    GlAccountBalance::create(['gl_account_id' => $this->expense->id, 'balance' => 0]);
    GlAccountBalance::create(['gl_account_id' => $this->ap->id,      'balance' => 0]);
});

function makeBalancedJe(User $user, GlAccount $debit, GlAccount $credit, float $amount): JournalEntry
{
    $je = JournalEntry::create([
        'reference'   => 'JE-' . uniqid(),
        'entry_date'  => now()->format('Y-m-d'),
        'status'      => JournalEntryStatus::Draft->value,
        'source_type' => 'manual',
        'created_by'  => $user->id,
    ]);
    JournalLine::create([
        'journal_entry_id' => $je->id, 'line_no' => 1,
        'gl_account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => 0,
    ]);
    JournalLine::create([
        'journal_entry_id' => $je->id, 'line_no' => 2,
        'gl_account_id' => $credit->id, 'debit_amount' => 0, 'credit_amount' => $amount,
    ]);
    return $je->fresh('lines');
}

it('posts a balanced JE and updates balances using natural-balance deltas', function () {
    $je = makeBalancedJe($this->user, $this->expense, $this->ap, 1000);

    $this->actingAs($this->user);
    $this->svc->post($je);

    $expBal = GlAccountBalance::find($this->expense->id)->balance;
    $apBal  = GlAccountBalance::find($this->ap->id)->balance;

    // expense natural = Dr - Cr = 1000 - 0 = +1000
    expect((float) $expBal)->toBe(1000.0);
    // liability natural = Cr - Dr = 1000 - 0 = +1000
    expect((float) $apBal)->toBe(1000.0);

    expect($je->fresh()->status)->toBe(JournalEntryStatus::Posted);
    expect($je->fresh()->posted_at)->not->toBeNull();
});

it('rejects an unbalanced JE', function () {
    $je = JournalEntry::create([
        'reference' => 'JE-UNBAL', 'entry_date' => '2026-05-22',
        'status' => 'draft', 'source_type' => 'manual', 'created_by' => $this->user->id,
    ]);
    JournalLine::create([
        'journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $this->expense->id,
        'debit_amount' => 100, 'credit_amount' => 0,
    ]);
    JournalLine::create([
        'journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $this->ap->id,
        'debit_amount' => 0, 'credit_amount' => 200,
    ]);

    expect(fn () => $this->svc->post($je->fresh('lines')))
        ->toThrow(\DomainException::class, 'balanced');
});

it('refuses to post a JE that is not in draft status', function () {
    $je = makeBalancedJe($this->user, $this->expense, $this->ap, 100);
    $this->actingAs($this->user);
    $this->svc->post($je);

    expect(fn () => $this->svc->post($je->fresh('lines')))
        ->toThrow(\DomainException::class, 'draft');
});

it('reverses a posted JE — creates inverted JE and rolls balances back', function () {
    $je = makeBalancedJe($this->user, $this->expense, $this->ap, 500);
    $this->actingAs($this->user);
    $this->svc->post($je);

    expect((float) GlAccountBalance::find($this->expense->id)->balance)->toBe(500.0);

    $reversal = $this->svc->reverse($je->fresh('lines'), $this->user, 'test reversal');

    expect($reversal->status)->toBe(JournalEntryStatus::Posted);
    expect($reversal->reversal_of_id)->toBe($je->id);
    expect($reversal->lines)->toHaveCount(2);
    expect($je->fresh()->status)->toBe(JournalEntryStatus::Reversed);
    expect($reversal->fresh()->status)->toBe(JournalEntryStatus::Posted);

    expect((float) GlAccountBalance::find($this->expense->id)->balance)->toBe(0.0);
    expect((float) GlAccountBalance::find($this->ap->id)->balance)->toBe(0.0);
});

it('balance invariant: balance equals natural sum of lines from posted + reversed entries', function () {
    $this->actingAs($this->user);

    $je1 = makeBalancedJe($this->user, $this->expense, $this->ap, 500);
    $this->svc->post($je1);

    $je2 = makeBalancedJe($this->user, $this->expense, $this->ap, 300);
    $this->svc->post($je2);

    $this->svc->reverse($je1->fresh('lines'), $this->user, 'rollback first');

    $expSum = JournalLine::whereHas('entry', fn ($q) =>
        $q->whereIn('status', [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value])
    )
    ->where('gl_account_id', $this->expense->id)
    ->get()
    ->sum(fn ($l) => (float) $l->debit_amount - (float) $l->credit_amount);

    expect((float) GlAccountBalance::find($this->expense->id)->balance)->toBe(300.0);
    expect($expSum)->toBe(300.0);
});

it('dispatches JournalEntryPosted event on successful post', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\JournalEntryPosted::class]);

    $je = makeBalancedJe($this->user, $this->expense, $this->ap, 250);
    $this->actingAs($this->user);
    $this->svc->post($je);

    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\JournalEntryPosted::class);
});
