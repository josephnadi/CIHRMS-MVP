<?php

it('reduces password confirmation timeout to 15 minutes by default', function () {
    expect(config('auth.password_timeout'))->toBe(900);
});

it('uses bcrypt as the configured hashing driver (matches TRD after L11 correction)', function () {
    expect(config('hashing.driver'))->toBe('bcrypt');
});
