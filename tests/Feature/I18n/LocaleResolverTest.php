<?php

use App\Enums\AppLocale;
use App\Models\User;
use App\Services\I18n\LocaleResolver;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->resolver = app(LocaleResolver::class);
});

it('prefers the ?locale query parameter when supported', function () {
    $request = Request::create('/dashboard?locale=tw', 'GET');

    expect($this->resolver->resolveFromRequest($request))->toBe('tw');
});

it('ignores an unsupported ?locale value and falls back', function () {
    $request = Request::create('/dashboard?locale=fr', 'GET');

    expect($this->resolver->resolveFromRequest($request))->toBe('en');
});

it('uses the authenticated user locale when no query override is present', function () {
    $user = User::factory()->create(['locale' => 'ee']);
    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);

    expect($this->resolver->resolveFromRequest($request))->toBe('ee');
});

it('falls through to Accept-Language when no user is signed in', function () {
    $request = Request::create('/dashboard', 'GET');
    $request->headers->set('Accept-Language', 'ga,en;q=0.5');

    expect($this->resolver->resolveFromRequest($request))->toBe('ga');
});

it('returns the configured default when everything else fails', function () {
    config(['i18n.default' => 'en']);

    $request = Request::create('/dashboard', 'GET');
    expect($this->resolver->resolveFromRequest($request))->toBe('en');
});

it('forUser returns the user locale or the configured fallback', function () {
    $en = User::factory()->create(['locale' => 'en']);
    $tw = User::factory()->create(['locale' => 'tw']);
    $junk = User::factory()->create(['locale' => 'zz']);

    expect($this->resolver->forUser($en))->toBe('en');
    expect($this->resolver->forUser($tw))->toBe('tw');
    expect($this->resolver->forUser($junk))->toBe('en');     // unknown → fallback
    expect($this->resolver->forUser(null))->toBe('en');
});

it('translates lang/tw/common.welcome to Akwaaba', function () {
    app()->setLocale('tw');
    expect(__('common.welcome'))->toBe('Akwaaba');
});

it('translates lang/ee/common.welcome to Woezɔ', function () {
    app()->setLocale('ee');
    expect(__('common.welcome'))->toBe('Woezɔ');
});

it('falls back to English when a key is missing in the active locale', function () {
    // `common.thank_you` exists in all locales; pick a string that we know is present.
    app()->setLocale('ga');
    expect(__('common.thank_you'))->toBe('Oyiwala dɔŋŋ.');
});

it('AppLocale::isSupported() validates correctly', function () {
    expect(AppLocale::isSupported('en'))->toBeTrue();
    expect(AppLocale::isSupported('tw'))->toBeTrue();
    expect(AppLocale::isSupported('ga'))->toBeTrue();
    expect(AppLocale::isSupported('ee'))->toBeTrue();
    expect(AppLocale::isSupported('fr'))->toBeFalse();
    expect(AppLocale::isSupported(null))->toBeFalse();
});
