<?php

declare(strict_types=1);

use App\Enums\SsoLoginOutcome;
use App\Models\SsoIdentityProvider;
use App\Services\Sso\SamlConfigBuilder;
use App\Services\Sso\SamlSsoAdapter;

/**
 * Tests for the SAML adapter's contract boundary. We trust onelogin/php-saml's
 * own extensive test suite to verify the XML-DSIG cryptographic work — these
 * tests instead prove our wiring: config validation, error-string mapping,
 * and rejection of obvious garbage before it ever reaches the library.
 *
 * `strict: true` + `wantAssertionsSigned: true` in config/sso.php are what
 * actually enforce signature verification on real assertions; the library
 * rejects anything that doesn't validate before our adapter sees attributes.
 */

beforeEach(function () {
    // Self-signed cert + key generated once for the test run. Identity content
    // doesn't matter — we never validate this cert against an external chain;
    // we only need a parseable PEM block for the builder to accept the config.
    $this->idpCert = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDazCCAlOgAwIBAgIUTestCertForUnitTestsOnly1234567890wDQYJKoZIhvcN
AQELBQAwRTELMAkGA1UEBhMCR0gxDjAMBgNVBAgMBUFjY3JhMRAwDgYDVQQHDAdU
ZW1hUmQxFDASBgNVBAoMC0NJSFJNUyB0ZXN0MB4XDTI2MDUyMDAwMDAwMFoXDTM2
MDUyMDAwMDAwMFowRTELMAkGA1UEBhMCR0gxDjAMBgNVBAgMBUFjY3JhMRAwDgYD
VQQHDAdUZW1hUmQxFDASBgNVBAoMC0NJSFJNUyB0ZXN0MIIBIjANBgkqhkiG9w0B
AQEFAAOCAQ8AMIIBCgKCAQEAxQK8FAKEFIXTURECERTHEREJUSTPAAAAAAAAAAAA
-----END CERTIFICATE-----
PEM;

    $this->makeProvider = function (array $overrides = []) {
        return SsoIdentityProvider::create(array_merge([
            'slug'           => 'test-idp',
            'name'           => 'Test IdP',
            'type'           => 'saml',
            'is_active'      => true,
            'auto_provision' => false,
            'default_role'   => 'employee',
            'config'         => [
                'entity_id'    => 'https://idp.example/entity',
                'sso_url'      => 'https://idp.example/sso',
                'idp_x509cert' => $this->idpCert,
            ],
            'claim_mapping'         => ['email' => 'email', 'name' => 'displayName'],
            'allowed_email_domains' => [],
        ], $overrides));
    };
});

it('reports type "saml"', function () {
    expect((new SamlSsoAdapter())->type())->toBe('saml');
});

it('throws when the provider config is missing required keys', function () {
    $provider = ($this->makeProvider)(['config' => ['entity_id' => 'x']]); // missing sso_url + cert
    expect(fn () => (new SamlConfigBuilder())->for($provider))
        ->toThrow(\DomainException::class, 'sso_url');
});

it('returns ProvidersError when SAMLResponse is missing from the callback', function () {
    $provider = ($this->makeProvider)();
    $result = (new SamlSsoAdapter())->handleCallback($provider, [], ['last_request_id' => 'x']);

    expect($result->outcome)->toBe(SsoLoginOutcome::ProvidersError);
    expect($result->error)->toContain('Missing SAMLResponse');
});

it('returns ProvidersError when SAMLResponse contains garbage', function () {
    $provider = ($this->makeProvider)();
    $result = (new SamlSsoAdapter())->handleCallback(
        $provider,
        ['SAMLResponse' => base64_encode('this is not SAML xml')],
        ['last_request_id' => 'x'],
    );

    // The library throws inside processResponse — adapter catches and returns
    // ProvidersError. Signature can't be verified on non-XML, but the request
    // is rejected before that step regardless.
    expect($result->outcome)->toBe(SsoLoginOutcome::ProvidersError);
});

it('returns ProvidersError when SAMLResponse XML is syntactically broken', function () {
    $provider = ($this->makeProvider)();
    $result = (new SamlSsoAdapter())->handleCallback(
        $provider,
        ['SAMLResponse' => base64_encode('<not><closed>')],
        ['last_request_id' => 'x'],
    );

    expect($result->outcome)->toBe(SsoLoginOutcome::ProvidersError);
});

it('maps onelogin signature errors to SignatureInvalid via error reason text', function () {
    // Drive the private mapper through reflection to verify the classification
    // contract independently of the library's exact error-code strings, which
    // have varied across onelogin/php-saml versions.
    $adapter = new SamlSsoAdapter();
    $ref     = new \ReflectionClass($adapter);
    $method  = $ref->getMethod('mapErrorsToOutcome');
    $method->setAccessible(true);

    $r1 = $method->invoke($adapter, ['invalid_response'], 'invalid signature on Response');
    expect($r1->outcome)->toBe(SsoLoginOutcome::SignatureInvalid);

    $r2 = $method->invoke($adapter, ['invalid_assertion_signature'], 'Assertion signature did not verify');
    expect($r2->outcome)->toBe(SsoLoginOutcome::SignatureInvalid);
});

it('maps expired NotOnOrAfter errors to AssertionExpired', function () {
    $adapter = new SamlSsoAdapter();
    $ref     = new \ReflectionClass($adapter);
    $method  = $ref->getMethod('mapErrorsToOutcome');
    $method->setAccessible(true);

    $result = $method->invoke($adapter, ['response_too_old'], 'Could not validate timestamp: expired. Check session expired');
    expect($result->outcome)->toBe(SsoLoginOutcome::AssertionExpired);
});

it('maps audience-restriction failures to AudienceMismatch', function () {
    $adapter = new SamlSsoAdapter();
    $ref     = new \ReflectionClass($adapter);
    $method  = $ref->getMethod('mapErrorsToOutcome');
    $method->setAccessible(true);

    $result = $method->invoke($adapter, ['invalid_audience'], 'Audience restriction did not match');
    expect($result->outcome)->toBe(SsoLoginOutcome::AudienceMismatch);
});

it('falls back to ProvidersError for unrecognised onelogin error codes', function () {
    $adapter = new SamlSsoAdapter();
    $ref     = new \ReflectionClass($adapter);
    $method  = $ref->getMethod('mapErrorsToOutcome');
    $method->setAccessible(true);

    $result = $method->invoke($adapter, ['something_weird'], 'Strange transport error');
    expect($result->outcome)->toBe(SsoLoginOutcome::ProvidersError);
    expect($result->error)->toContain('Strange transport error');
});

it('builds a valid onelogin settings array for a properly-configured provider', function () {
    $provider = ($this->makeProvider)();
    $settings = (new SamlConfigBuilder())->for($provider);

    expect($settings['strict'])->toBeTrue();
    expect($settings['security']['wantAssertionsSigned'])->toBeTrue();
    expect($settings['sp']['assertionConsumerService']['url'])
        ->toEndWith('/auth/sso/test-idp/callback');
    expect($settings['idp']['entityId'])->toBe('https://idp.example/entity');
    expect($settings['idp']['singleSignOnService']['url'])->toBe('https://idp.example/sso');
    expect($settings['idp']['x509cert'])->toBe($this->idpCert);
});

it('initiate() returns a redirect_url that contains a SAMLRequest parameter', function () {
    $provider = ($this->makeProvider)();
    $bundle   = (new SamlSsoAdapter())->initiate($provider, '/dashboard');

    expect($bundle['redirect_url'])->toStartWith('https://idp.example/sso');
    expect($bundle['redirect_url'])->toContain('SAMLRequest=');
    expect($bundle['session'])->toHaveKey('last_request_id');
    expect($bundle['session']['intended'])->toBe('/dashboard');
});
