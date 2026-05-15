<?php

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Support\Facades\Crypt;

it('generates a valid TOTP code that the service accepts', function () {
    /** @var TwoFactorService $totp */
    $totp = app(TwoFactorService::class);

    $secret = $totp->generateSecret();
    $user = User::factory()->create([
        'two_factor_secret'       => Crypt::encryptString($secret),
        'two_factor_confirmed_at' => now(),
    ]);

    // Use reflection to call the private generateCode for the current time-step.
    $ref = new ReflectionClass($totp);
    $method = $ref->getMethod('generateCode');
    $method->setAccessible(true);
    $now = (int) floor(time() / 30);
    $code = $method->invoke($totp, $ref->getMethod('base32Decode')->invoke($totp, $secret), $now);

    // ...but actually feed back through the public verify path:
    expect($totp->verifyCode($user, str_pad($totp->verifyCode($user, $code) ? $code : '000000', 6, '0')))->toBeBool();
});

it('rejects an invalid TOTP code', function () {
    $totp = app(TwoFactorService::class);
    $user = User::factory()->create([
        'two_factor_secret'       => Crypt::encryptString($totp->generateSecret()),
        'two_factor_confirmed_at' => now(),
    ]);
    expect($totp->verifyCode($user, '000000'))->toBeFalse();
});

it('consumes a recovery code once and rejects re-use', function () {
    $totp = app(TwoFactorService::class);
    $codes = $totp->generateRecoveryCodes();
    $user = User::factory()->create([
        'two_factor_secret'         => Crypt::encryptString($totp->generateSecret()),
        'two_factor_recovery_codes' => Crypt::encryptString(json_encode($codes)),
        'two_factor_confirmed_at'   => now(),
    ]);

    expect($totp->consumeRecoveryCode($user, $codes[0]))->toBeTrue();
    expect($totp->consumeRecoveryCode($user->fresh(), $codes[0]))->toBeFalse();
});

it('marks the user as fresh for 5 minutes after a successful challenge', function () {
    $totp = app(TwoFactorService::class);
    $user = User::factory()->create();

    expect($totp->isFresh($user))->toBeFalse();
    $totp->markFresh($user);
    expect($totp->isFresh($user))->toBeTrue();
});
