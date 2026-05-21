<?php

declare(strict_types=1);

use App\Jobs\WriteAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('backfills chain values onto legacy rows that pre-date the migration', function () {
    $user = User::factory()->create();

    // Simulate legacy data: rows inserted before the chain columns existed —
    // chain_position / previous_hash / row_hash are all null.
    foreach (range(1, 3) as $i) {
        AuditLog::create([
            'user_id'       => $user->id,
            'action'        => "legacy_{$i}",
            'method'        => 'POST',
            'path'          => "/legacy/{$i}",
            'chain_position'=> null,
            'previous_hash' => null,
            'row_hash'      => null,
        ]);
    }

    expect(AuditLog::whereNull('chain_position')->count())->toBe(3);

    $exit = Artisan::call('audit:backfill-chain');
    expect($exit)->toBe(0);

    $rows = AuditLog::orderBy('id')->get();
    expect($rows[0]->chain_position)->toBe(1);
    expect($rows[0]->previous_hash)->toBeNull();
    expect($rows[0]->row_hash)->not->toBeEmpty();
    expect($rows[1]->previous_hash)->toBe($rows[0]->row_hash);
    expect($rows[2]->previous_hash)->toBe($rows[1]->row_hash);

    // Sanity: the verifier accepts the chain we just built.
    expect(Artisan::call('audit:verify-chain'))->toBe(0);
});

it('backfill is idempotent — running on an intact chain does nothing', function () {
    $user = User::factory()->create();
    for ($i = 1; $i <= 2; $i++) {
        (new WriteAuditLog(['user_id' => $user->id, 'action' => "a{$i}", 'method' => 'GET', 'path' => '/x']))->handle();
    }

    $hashesBefore = AuditLog::orderBy('id')->pluck('row_hash')->all();
    Artisan::call('audit:backfill-chain');
    $hashesAfter  = AuditLog::orderBy('id')->pluck('row_hash')->all();

    expect($hashesAfter)->toBe($hashesBefore);
});

it('backfill --dry-run does not write any rows', function () {
    $user = User::factory()->create();
    AuditLog::create([
        'user_id'        => $user->id,
        'action'         => 'legacy_dry',
        'method'         => 'POST',
        'path'           => '/dry',
        'chain_position' => null,
        'previous_hash'  => null,
        'row_hash'       => null,
    ]);

    Artisan::call('audit:backfill-chain', ['--dry-run' => true]);

    $row = AuditLog::first();
    expect($row->chain_position)->toBeNull();
    expect($row->row_hash)->toBeNull();
});

it('backfill extends an existing chain — does not restart from genesis', function () {
    $user = User::factory()->create();

    // 2 properly-chained rows first
    for ($i = 1; $i <= 2; $i++) {
        (new WriteAuditLog(['user_id' => $user->id, 'action' => "ok{$i}", 'method' => 'GET', 'path' => '/x']))->handle();
    }
    $tailHash = AuditLog::orderByDesc('chain_position')->value('row_hash');

    // Then a "legacy" row inserted directly with null chain values
    AuditLog::create([
        'user_id'       => $user->id,
        'action'        => 'late_legacy',
        'method'        => 'POST',
        'path'          => '/late',
        'chain_position'=> null,
        'previous_hash' => null,
        'row_hash'      => null,
    ]);

    Artisan::call('audit:backfill-chain');

    $row = AuditLog::where('action', 'late_legacy')->first();
    expect($row->chain_position)->toBe(3);
    expect($row->previous_hash)->toBe($tailHash);

    expect(Artisan::call('audit:verify-chain'))->toBe(0);
});
