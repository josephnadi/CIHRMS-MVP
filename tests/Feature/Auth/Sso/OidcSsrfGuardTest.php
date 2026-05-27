<?php

use App\Services\Sso\OidcIdTokenVerifier;

it('rejects http (non-TLS) URLs', function () {
    expect(OidcIdTokenVerifier::isSafeExternalUrl('http://idp.example.com/jwks'))->toBeFalse();
});

it('rejects loopback and link-local literals', function () {
    expect(OidcIdTokenVerifier::isSafeExternalUrl('https://127.0.0.1/jwks'))->toBeFalse();
    expect(OidcIdTokenVerifier::isSafeExternalUrl('https://169.254.169.254/jwks'))->toBeFalse(); // AWS metadata
    expect(OidcIdTokenVerifier::isSafeExternalUrl('https://10.0.0.5/jwks'))->toBeFalse();
    expect(OidcIdTokenVerifier::isSafeExternalUrl('https://192.168.1.10/jwks'))->toBeFalse();
    expect(OidcIdTokenVerifier::isSafeExternalUrl('https://172.16.5.5/jwks'))->toBeFalse();
});

it('rejects garbage URLs', function () {
    expect(OidcIdTokenVerifier::isSafeExternalUrl('not a url'))->toBeFalse();
    expect(OidcIdTokenVerifier::isSafeExternalUrl(''))->toBeFalse();
    expect(OidcIdTokenVerifier::isSafeExternalUrl('https://'))->toBeFalse();
});

it('accepts a public HTTPS hostname (resolves to public IP)', function () {
    // 1.1.1.1 is a public anycast address; gethostbynamel for an IP literal
    // returns the literal back, which then passes the no-priv-range filter.
    expect(OidcIdTokenVerifier::isSafeExternalUrl('https://1.1.1.1/jwks'))->toBeTrue();
});
