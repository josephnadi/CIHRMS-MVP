<?php

declare(strict_types=1);

use App\Enums\JournalEntryStatus;
use App\Enums\JournalSourceType;
use App\Enums\VendorStatus;
use App\Models\GlAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Models\Vendor;

it('creates a vendor and casts status enum', function () {
    $v = Vendor::create([
        'code'   => 'VEN-0001',
        'name'   => 'Test Vendor',
        'status' => VendorStatus::Active->value,
    ]);

    expect($v->status)->toBe(VendorStatus::Active);
    expect($v->code)->toBe('VEN-0001');
});

it('Vendor.active scope filters to active status', function () {
    Vendor::create(['code' => 'V-A', 'name' => 'A', 'status' => 'active']);
    Vendor::create(['code' => 'V-I', 'name' => 'I', 'status' => 'inactive']);

    expect(Vendor::active()->count())->toBe(1);
});

it('JournalEntry casts status + source_type + entry_date', function () {
    $user = User::factory()->create();

    $je = JournalEntry::create([
        'reference'   => 'JE-TEST-001',
        'entry_date'  => '2026-05-22',
        'status'      => JournalEntryStatus::Draft->value,
        'source_type' => JournalSourceType::Manual->value,
        'created_by'  => $user->id,
    ]);

    expect($je->status)->toBe(JournalEntryStatus::Draft);
    expect($je->source_type)->toBe(JournalSourceType::Manual);
    expect($je->entry_date->format('Y-m-d'))->toBe('2026-05-22');
});

it('JournalEntry.isBalanced sums debits and credits', function () {
    $user = User::factory()->create();
    $gl1  = GlAccount::create(['code' => '5100-T', 'name' => 'TestExp', 'type' => 'expense']);
    $gl2  = GlAccount::create(['code' => '2100-T', 'name' => 'TestAP',  'type' => 'liability']);

    $je = JournalEntry::create([
        'reference' => 'JE-BAL', 'entry_date' => '2026-05-22',
        'status' => 'draft', 'source_type' => 'manual',
        'created_by' => $user->id,
    ]);

    JournalLine::create([
        'journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $gl1->id,
        'debit_amount' => 1000.00, 'credit_amount' => 0,
    ]);
    JournalLine::create([
        'journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $gl2->id,
        'debit_amount' => 0, 'credit_amount' => 1000.00,
    ]);

    expect($je->fresh('lines')->isBalanced())->toBeTrue();

    JournalLine::create([
        'journal_entry_id' => $je->id, 'line_no' => 3, 'gl_account_id' => $gl1->id,
        'debit_amount' => 50, 'credit_amount' => 0,
    ]);

    expect($je->fresh('lines')->isBalanced())->toBeFalse();
});

it('JournalLine refuses to save with both debit and credit > 0', function () {
    $user = User::factory()->create();
    $gl   = GlAccount::create(['code' => '5100-T', 'name' => 'Test', 'type' => 'expense']);
    $je = JournalEntry::create([
        'reference' => 'JE-X', 'entry_date' => '2026-05-22',
        'status' => 'draft', 'source_type' => 'manual',
        'created_by' => $user->id,
    ]);

    expect(fn () => JournalLine::create([
        'journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $gl->id,
        'debit_amount' => 100, 'credit_amount' => 100,
    ]))->toThrow(\DomainException::class, 'debit or credit');
});

it('JournalEntry.lines relation returns ordered lines', function () {
    $user = User::factory()->create();
    $gl   = GlAccount::create(['code' => '5100-T', 'name' => 'Test', 'type' => 'expense']);
    $je   = JournalEntry::create([
        'reference' => 'JE-ORD', 'entry_date' => '2026-05-22',
        'status' => 'draft', 'source_type' => 'manual',
        'created_by' => $user->id,
    ]);

    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 2, 'gl_account_id' => $gl->id, 'debit_amount' => 5,  'credit_amount' => 0]);
    JournalLine::create(['journal_entry_id' => $je->id, 'line_no' => 1, 'gl_account_id' => $gl->id, 'debit_amount' => 10, 'credit_amount' => 0]);

    $lineNumbers = $je->fresh()->lines->pluck('line_no')->all();
    expect($lineNumbers)->toBe([1, 2]);
});
