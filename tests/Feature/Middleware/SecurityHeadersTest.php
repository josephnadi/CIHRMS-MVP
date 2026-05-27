<?php

use App\Models\User;

it('sets default-deny security headers on a normal web response', function () {
    $user = User::factory()->create();
    $resp = $this->actingAs($user)->get(route('dashboard'));

    expect($resp->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($resp->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    expect($resp->headers->get('Permissions-Policy'))->toContain('geolocation=()');
});

it('does not set HSTS on plain HTTP', function () {
    $user = User::factory()->create();
    $resp = $this->actingAs($user)->get(route('dashboard'));
    expect($resp->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('does not overwrite pre-set headers', function () {
    \Illuminate\Support\Facades\Route::get('/_test/preset-frame-options', function () {
        return response('ok')->header('X-Frame-Options', 'SAMEORIGIN');
    })->middleware('web');

    $resp = $this->get('/_test/preset-frame-options');
    expect($resp->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});
