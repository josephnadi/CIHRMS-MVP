<?php

use App\Enums\SsoProviderType;
use App\Models\SsoIdentityProvider;
use App\Services\Sso\OidcIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

$makeProvider = function (): SsoIdentityProvider {
    return SsoIdentityProvider::create([
        'slug'           => 'oidc-test',
        'name'           => 'OIDC Test',
        'type'           => SsoProviderType::Oidc->value,
        'is_active'      => true,
        'auto_provision' => false,
        'default_role'   => 'employee',
        'config'         => [
            'client_id' => 'aud-x',
            'issuer'    => 'https://idp.example.com',
            'jwks_uri'  => 'https://idp.example.com/jwks',
        ],
    ]);
};

$loadKeypair = function (string $name): array {
    $priv = file_get_contents(__DIR__ . "/../../../Fixtures/{$name}.pem");
    $pkey = openssl_pkey_get_private($priv);
    $details = openssl_pkey_get_details($pkey);
    $b64url = function (string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    };
    $jwk = [
        'kty' => 'RSA',
        'use' => 'sig',
        'kid' => 'k1',
        'alg' => 'RS256',
        'n'   => $b64url($details['rsa']['n']),
        'e'   => $b64url($details['rsa']['e']),
    ];
    return ['private' => $priv, 'jwk' => $jwk];
};

$b64url = function (string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
};

beforeEach(function () {
    Cache::flush();
});

it('rejects an unsigned id token (alg=none bypass attempt)', function () use ($makeProvider, $b64url) {
    $provider = $makeProvider();
    Http::fake(['https://idp.example.com/jwks' => Http::response(['keys' => []])]);

    $unsigned = $b64url('{"alg":"none","typ":"JWT"}')
              . '.' . $b64url(json_encode([
                  'sub'   => 'attacker',
                  'email' => 'attacker@evil.com',
                  'iss'   => 'https://idp.example.com',
                  'aud'   => 'aud-x',
                  'exp'   => time() + 3600,
              ])) . '.';

    $claims = (new OidcIdTokenVerifier())->verify($unsigned, $provider);
    expect($claims)->toBe([]);
});

it('rejects an id token signed with the wrong key', function () use ($makeProvider, $loadKeypair) {
    $provider = $makeProvider();
    $real     = $loadKeypair('oidc-test-key');
    $attacker = $loadKeypair('oidc-attacker-key');

    Http::fake(['https://idp.example.com/jwks' => Http::response(['keys' => [$real['jwk']]])]);

    $forged = JWT::encode([
        'sub' => 'attacker',
        'iss' => 'https://idp.example.com',
        'aud' => 'aud-x',
        'exp' => time() + 3600,
    ], $attacker['private'], 'RS256', 'k1');

    $claims = (new OidcIdTokenVerifier())->verify($forged, $provider);
    expect($claims)->toBe([]);
});

it('rejects a token whose iss does not match the provider issuer', function () use ($makeProvider, $loadKeypair) {
    $provider = $makeProvider();
    $kp       = $loadKeypair('oidc-test-key');
    Http::fake(['https://idp.example.com/jwks' => Http::response(['keys' => [$kp['jwk']]])]);

    $token = JWT::encode([
        'sub' => 'u1',
        'iss' => 'https://wrong-issuer.example.com',
        'aud' => 'aud-x',
        'exp' => time() + 3600,
    ], $kp['private'], 'RS256', 'k1');

    expect((new OidcIdTokenVerifier())->verify($token, $provider))->toBe([]);
});

it('rejects a token whose aud does not include the client_id', function () use ($makeProvider, $loadKeypair) {
    $provider = $makeProvider();
    $kp       = $loadKeypair('oidc-test-key');
    Http::fake(['https://idp.example.com/jwks' => Http::response(['keys' => [$kp['jwk']]])]);

    $token = JWT::encode([
        'sub' => 'u1',
        'iss' => 'https://idp.example.com',
        'aud' => 'someone-else',
        'exp' => time() + 3600,
    ], $kp['private'], 'RS256', 'k1');

    expect((new OidcIdTokenVerifier())->verify($token, $provider))->toBe([]);
});

it('accepts a properly signed id token whose iss + aud match', function () use ($makeProvider, $loadKeypair) {
    $provider = $makeProvider();
    $kp       = $loadKeypair('oidc-test-key');
    Http::fake(['https://idp.example.com/jwks' => Http::response(['keys' => [$kp['jwk']]])]);

    $token = JWT::encode([
        'sub'   => 'real-user',
        'email' => 'real@example.com',
        'iss'   => 'https://idp.example.com',
        'aud'   => 'aud-x',
        'exp'   => time() + 3600,
    ], $kp['private'], 'RS256', 'k1');

    $claims = (new OidcIdTokenVerifier())->verify($token, $provider);
    expect($claims['sub'] ?? null)->toBe('real-user');
    expect($claims['email'] ?? null)->toBe('real@example.com');
});

it('returns [] when the JWKS endpoint is unreachable', function () use ($makeProvider, $b64url) {
    $provider = $makeProvider();
    Http::fake(['https://idp.example.com/jwks' => Http::response('', 502)]);

    $unsigned = $b64url('{"alg":"none","typ":"JWT"}') . '.' . $b64url('{"sub":"x"}') . '.';

    expect((new OidcIdTokenVerifier())->verify($unsigned, $provider))->toBe([]);
});
