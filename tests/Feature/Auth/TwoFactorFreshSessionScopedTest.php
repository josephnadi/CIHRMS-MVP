<?php

use App\Models\User;
use App\Services\Auth\TwoFactorService;

it('clears the 2FA-fresh marker on logout', function () {
    $user = User::factory()->create();
    $svc  = app(TwoFactorService::class);
    $svc->markFresh($user);
    expect($svc->isFresh($user))->toBeTrue();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');
    expect($svc->isFresh($user))->toBeFalse();
});

it('clears the 2FA-fresh marker on a fresh login (prevents cross-session reuse)', function () {
    $user = User::factory()->create([
        'name'     => 'Test User',
        'staff_id' => 'STAFF-X',
        'password' => bcrypt('Secret123!'),
    ]);
    $svc = app(TwoFactorService::class);

    // Simulate a leftover marker from a previous session
    $svc->markFresh($user);
    expect($svc->isFresh($user))->toBeTrue();

    // New login wipes it
    $this->post('/login', [
        'name'     => 'Test User',
        'staff_id' => 'STAFF-X',
        'password' => 'Secret123!',
    ])->assertRedirect();

    expect($svc->isFresh($user))->toBeFalse();
});

it('exposes a clearFresh() method that idempotently forgets the marker', function () {
    $user = User::factory()->create();
    $svc  = app(TwoFactorService::class);

    $svc->markFresh($user);
    $svc->clearFresh($user);
    $svc->clearFresh($user);     // idempotent

    expect($svc->isFresh($user))->toBeFalse();
});
