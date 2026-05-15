<?php

use App\Jobs\WriteAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

it('writes a tamper-evident hash chain over consecutive audit rows', function () {
    $user = User::factory()->create();

    for ($i = 1; $i <= 3; $i++) {
        (new WriteAuditLog([
            'user_id' => $user->id,
            'action'  => "action_{$i}",
            'method'  => 'POST',
            'path'    => "/resource/{$i}",
        ]))->handle();
    }

    $rows = AuditLog::orderBy('chain_position')->get();

    expect($rows)->toHaveCount(3);
    expect($rows[0]->chain_position)->toBe(1);
    expect($rows[0]->previous_hash)->toBeNull();
    expect($rows[0]->row_hash)->not->toBeEmpty();
    expect($rows[1]->previous_hash)->toBe($rows[0]->row_hash);
    expect($rows[2]->previous_hash)->toBe($rows[1]->row_hash);
});

it('verify-chain command passes on an intact chain', function () {
    $user = User::factory()->create();
    for ($i = 1; $i <= 3; $i++) {
        (new WriteAuditLog(['user_id' => $user->id, 'action' => "a{$i}", 'method' => 'GET', 'path' => '/x']))->handle();
    }

    $exit = Artisan::call('audit:verify-chain');
    expect($exit)->toBe(0);
});

it('verify-chain command detects a tampered row', function () {
    $user = User::factory()->create();
    for ($i = 1; $i <= 3; $i++) {
        (new WriteAuditLog(['user_id' => $user->id, 'action' => "a{$i}", 'method' => 'GET', 'path' => '/x']))->handle();
    }

    // Tamper with the middle row's action
    AuditLog::where('chain_position', 2)->update(['action' => 'tampered']);

    $exit = Artisan::call('audit:verify-chain');
    expect($exit)->not->toBe(0);
});
