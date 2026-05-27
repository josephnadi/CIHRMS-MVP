<?php

use App\Models\User;

it('redirects a 2fa-required but unconfirmed user to enrolment on any route', function () {
    $user = User::factory()->create([
        'two_factor_required'     => true,
        'two_factor_confirmed_at' => null,
    ]);

    // Pick a non-2FA route that exists in the web group
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('two-factor.enroll'));
});

it('lets a 2fa-required user reach the enrolment page without redirect loop', function () {
    $user = User::factory()->create([
        'two_factor_required'     => true,
        'two_factor_confirmed_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('two-factor.enroll'))
        ->assertOk();
});

it('lets a 2fa-required user log out without being trapped', function () {
    $user = User::factory()->create([
        'two_factor_required'     => true,
        'two_factor_confirmed_at' => null,
    ]);

    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
});

it('does not gate a user without two_factor_required', function () {
    $user = User::factory()->create([
        'two_factor_required'     => false,
        'two_factor_confirmed_at' => null,
    ]);

    $resp = $this->actingAs($user)->get(route('dashboard'));
    expect($resp->status())->not->toBe(302);  // not redirected to enrol
});
