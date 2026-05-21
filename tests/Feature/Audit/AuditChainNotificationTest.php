<?php

declare(strict_types=1);

use App\Jobs\WriteAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AuditChainBroken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

it('notifies super_admins when --notify is set and the chain is broken', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $regular    = User::factory()->create(['role' => 'employee']);

    for ($i = 1; $i <= 3; $i++) {
        (new WriteAuditLog(['user_id' => $superAdmin->id, 'action' => "a{$i}", 'method' => 'GET', 'path' => '/x']))->handle();
    }

    AuditLog::where('chain_position', 2)->update(['action' => 'tampered']);

    $exit = Artisan::call('audit:verify-chain', ['--notify' => true]);

    expect($exit)->not->toBe(0);
    Notification::assertSentTo($superAdmin, AuditChainBroken::class);
    Notification::assertNotSentTo($regular, AuditChainBroken::class);
});

it('does not notify when --notify flag is absent', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['role' => 'super_admin']);

    for ($i = 1; $i <= 2; $i++) {
        (new WriteAuditLog(['user_id' => $superAdmin->id, 'action' => "a{$i}", 'method' => 'GET', 'path' => '/x']))->handle();
    }

    AuditLog::where('chain_position', 1)->update(['action' => 'tampered']);

    $exit = Artisan::call('audit:verify-chain');

    expect($exit)->not->toBe(0);
    Notification::assertNothingSent();
});

it('emits no notification when the chain is intact', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['role' => 'super_admin']);

    for ($i = 1; $i <= 3; $i++) {
        (new WriteAuditLog(['user_id' => $superAdmin->id, 'action' => "ok{$i}", 'method' => 'GET', 'path' => '/x']))->handle();
    }

    expect(Artisan::call('audit:verify-chain', ['--notify' => true]))->toBe(0);
    Notification::assertNothingSent();
});

it('records the broken position and reason in the notification payload', function () {
    Notification::fake();

    $superAdmin = User::factory()->create(['role' => 'super_admin']);

    for ($i = 1; $i <= 3; $i++) {
        (new WriteAuditLog(['user_id' => $superAdmin->id, 'action' => "a{$i}", 'method' => 'GET', 'path' => '/x']))->handle();
    }

    AuditLog::where('chain_position', 2)->update(['action' => 'tampered_at_2']);

    Artisan::call('audit:verify-chain', ['--notify' => true]);

    Notification::assertSentTo($superAdmin, AuditChainBroken::class, function (AuditChainBroken $n) {
        return $n->brokenPosition === 2
            && str_contains(strtolower($n->reason), 'row_hash');
    });
});
