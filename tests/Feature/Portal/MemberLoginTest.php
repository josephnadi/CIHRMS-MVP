<?php

use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Support\Facades\Hash;

it('shows the login page to a guest', function () {
    $this->get('/portal/login')->assertOk();
});

it('signs an active member in and redirects to the portal dashboard', function () {
    $member = Member::factory()->create([
        'email'    => 'happy@member.gh',
        'password' => Hash::make('CorrectHorse123!'),
        'status'   => MemberStatus::Active->value,
    ]);

    $this->post('/portal/login', [
        'email'    => 'happy@member.gh',
        'password' => 'CorrectHorse123!',
    ])->assertRedirect(route('portal.home'));

    $this->assertAuthenticatedAs($member, 'member');
});

it('rejects a wrong password with a generic message', function () {
    Member::factory()->create([
        'email'    => 'real@member.gh',
        'password' => Hash::make('right'),
    ]);

    $this->post('/portal/login', [
        'email'    => 'real@member.gh',
        'password' => 'wrong',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('member');
});

it('rejects an unknown email without distinguishing from a wrong password', function () {
    $this->post('/portal/login', [
        'email'    => 'nobody@nowhere.gh',
        'password' => 'whatever',
    ])->assertSessionHasErrors('email');
});

it('refuses login for a suspended member', function () {
    Member::factory()->create([
        'email'    => 'sus@member.gh',
        'password' => Hash::make('Secret!1'),
        'status'   => MemberStatus::Suspended->value,
    ]);

    $resp = $this->post('/portal/login', [
        'email'    => 'sus@member.gh',
        'password' => 'Secret!1',
    ]);

    $resp->assertSessionHasErrors('email');
    $this->assertGuest('member');
});

it('logs the member out and clears the session', function () {
    $m = Member::factory()->create();
    $this->actingAs($m, 'member')->post('/portal/logout')
        ->assertRedirect(route('portal.login'));
    $this->assertGuest('member');
});
