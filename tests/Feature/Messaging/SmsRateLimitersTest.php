<?php

use Illuminate\Support\Facades\RateLimiter;

it('registers sms:transactional limiter (unlimited — present for N3 inheritance)', function () {
    $limit = RateLimiter::limiter('sms:transactional');
    expect($limit)->not->toBeNull();

    // Hit it 1000 times, never throttled — transactional bypass.
    for ($i = 0; $i < 1000; $i++) {
        RateLimiter::hit('sms:transactional:+233200000099', 60);
    }
    expect(RateLimiter::tooManyAttempts('sms:transactional:+233200000099', 999999))->toBeFalse();
});

it('registers sms:marketing limiter (5 per hour per phone)', function () {
    $limit = RateLimiter::limiter('sms:marketing');
    expect($limit)->not->toBeNull();

    $phone = '+233200000099';

    // First 5 within an hour should be allowed.
    for ($i = 0; $i < 5; $i++) {
        $ok = RateLimiter::attempt("sms:marketing:{$phone}", 5, fn () => true, 3600);
        expect($ok)->toBeTruthy();
    }

    // 6th attempt blocked.
    expect(RateLimiter::tooManyAttempts("sms:marketing:{$phone}", 5))->toBeTrue();
});
