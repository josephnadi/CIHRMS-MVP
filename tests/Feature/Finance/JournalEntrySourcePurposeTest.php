<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\JournalEntry;
use App\Models\User;

it('persists source_purpose and defaults it to empty string', function () {
    $user = User::factory()->create();

    $entry = JournalEntry::create([
        'reference'   => 'JE-TEST-1',
        'entry_date'  => '2026-06-16',
        'narration'   => 'test',
        'status'      => 'draft',
        'source_type' => JournalSourceType::Payroll->value,
        'source_id'   => 42,
        'created_by'  => $user->id,
    ]);

    expect($entry->fresh()->source_purpose)->toBe('');

    $entry2 = JournalEntry::create([
        'reference'      => 'JE-TEST-2',
        'entry_date'     => '2026-06-16',
        'narration'      => 'test',
        'status'         => 'draft',
        'source_type'    => JournalSourceType::Payroll->value,
        'source_id'      => 42,
        'source_purpose' => 'settlement',
        'created_by'     => $user->id,
    ]);

    expect($entry2->fresh()->source_purpose)->toBe('settlement');
});

it('rejects a duplicate (source_type, source_id, source_purpose)', function () {
    $user = User::factory()->create();

    $attrs = [
        'reference'      => 'JE-DUP-1',
        'entry_date'     => '2026-06-16',
        'narration'      => 'test',
        'status'         => 'draft',
        'source_type'    => JournalSourceType::Payroll->value,
        'source_id'      => 99,
        'source_purpose' => 'accrual',
        'created_by'     => $user->id,
    ];
    JournalEntry::create($attrs);

    expect(fn () => JournalEntry::create([...$attrs, 'reference' => 'JE-DUP-2']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
