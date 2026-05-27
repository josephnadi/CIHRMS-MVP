<?php

use App\Models\User;

it('redirects a password_must_change user away from the dashboard', function () {
    $user = User::factory()->create(['password_must_change' => true]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('profile.edit') . '#security');
});

it('still allows the user to load /profile/edit (the password-change form)', function () {
    $user = User::factory()->create(['password_must_change' => true]);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});

it('still allows POST to /profile/password (submitting the new password)', function () {
    $user = User::factory()->create([
        'password_must_change' => true,
        'password'             => bcrypt('OldPassword!1'),
    ]);

    // The middleware must NOT redirect this route. With empty body the
    // controller returns a 302 with validation errors back to the form;
    // we assert the redirect target is the form itself (intended UX),
    // NOT the must-change interstitial.
    $resp = $this->actingAs($user)->from(route('profile.edit'))->patch(route('profile.password'), []);
    expect($resp->status())->toBe(302);
    expect($resp->headers->get('Location'))->toBe(route('profile.edit'));
});

it('refuses to let the user reach /profile/personal until password is changed (M6 tightening)', function () {
    $user = User::factory()->create(['password_must_change' => true]);

    $this->actingAs($user)
        ->patch(route('profile.personal'), [])
        ->assertRedirect(route('profile.edit') . '#security');
});

it('always permits logout even with password_must_change', function () {
    $user = User::factory()->create(['password_must_change' => true]);

    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
});
