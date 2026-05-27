<?php

use App\Http\Controllers\Auth\SsoController;

beforeEach(function () {
    config(['app.url' => 'https://cihrms.test']);
});

it('drops an external intended url', function () {
    $safe = SsoController::safeIntended('https://attacker.com/steal');
    expect($safe)->not->toContain('attacker.com');
});

it('keeps a same-host absolute intended url', function () {
    $safe = SsoController::safeIntended('https://cihrms.test/finance');
    expect($safe)->toBe('https://cihrms.test/finance');
});

it('keeps a relative intended path', function () {
    $safe = SsoController::safeIntended('/finance/invoices');
    expect($safe)->toBe('/finance/invoices');
});

it('falls back to dashboard when intended is null or empty', function () {
    expect(SsoController::safeIntended(null))->toBe(route('dashboard'));
    expect(SsoController::safeIntended(''))->toBe(route('dashboard'));
});

it('falls back to dashboard when intended is unparseable', function () {
    expect(SsoController::safeIntended('http://:::::'))->toBe(route('dashboard'));
});
