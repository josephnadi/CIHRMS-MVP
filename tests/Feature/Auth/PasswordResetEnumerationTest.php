<?php

use App\Models\User;

it('returns identical status message for known and unknown email (no enumeration)', function () {
    User::factory()->create(['email' => 'real@example.com']);

    $a = $this->post('/forgot-password', ['email' => 'real@example.com']);
    $b = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

    expect($a->getSession()->get('status'))->not->toBeNull();
    expect($a->getSession()->get('status'))->toBe($b->getSession()->get('status'));
});

it('never throws ValidationException for an unknown email (would leak existence)', function () {
    $resp = $this->post('/forgot-password', ['email' => 'totally-unknown@example.com']);
    $resp->assertSessionHasNoErrors();
});

it('rate-limits password reset link requests at 3/minute per IP', function () {
    // 3 requests should succeed
    for ($i = 0; $i < 3; $i++) {
        $this->post('/forgot-password', ['email' => "user{$i}@example.com"])
            ->assertSessionHasNoErrors();
    }
    // 4th hits the throttle
    $resp = $this->post('/forgot-password', ['email' => 'user99@example.com']);
    expect($resp->status())->toBe(429);
});
