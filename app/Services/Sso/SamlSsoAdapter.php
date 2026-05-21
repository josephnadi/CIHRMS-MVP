<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\SsoLoginOutcome;
use App\Models\SsoIdentityProvider;
use App\Services\Sso\Contracts\SsoAdapter;
use Illuminate\Support\Str;
use OneLogin\Saml2\Auth as OneLoginAuth;
use OneLogin\Saml2\Utils as OneLoginUtils;
use Throwable;

/**
 * SAML 2.0 SP adapter — library-backed.
 *
 * Drives `onelogin/php-saml` for signature verification, NameID extraction,
 * audience validation, and NotOnOrAfter expiry. The library's strict mode is
 * enabled via `config/sso.php` so any signature, audience, or timing failure
 * causes the assertion to be rejected before this class sees the attributes.
 *
 * Replaces the original stub which extracted the NameID via DOMXPath without
 * verifying the XML-DSIG envelope. The new implementation never accepts an
 * unverified response.
 */
class SamlSsoAdapter implements SsoAdapter
{
    public function __construct(private readonly SamlConfigBuilder $builder = new SamlConfigBuilder()) {}

    public function type(): string
    {
        return 'saml';
    }

    public function initiate(SsoIdentityProvider $provider, string $intendedUrl): array
    {
        $settings = $this->builder->for($provider);
        $auth     = $this->makeAuth($settings);

        // `login(returnTo, ..., stay=true)` returns the redirect URL instead
        // of issuing it — we need the URL so the caller can stash session
        // state alongside it.
        $redirectUrl  = $auth->login($intendedUrl, [], false, false, true);
        $lastRequestId = $auth->getLastRequestID();

        return [
            'redirect_url' => $redirectUrl,
            'session'      => [
                'last_request_id' => $lastRequestId,
                'intended'        => $intendedUrl,
                // Kept for backward-compat with the prior stub's session shape.
                'relay_state'     => Str::random(40),
            ],
        ];
    }

    public function handleCallback(SsoIdentityProvider $provider, array $callback, array $session): SsoAuthResult
    {
        $samlResponse = (string) ($callback['SAMLResponse'] ?? '');
        if ($samlResponse === '') {
            return SsoAuthResult::failure(SsoLoginOutcome::ProvidersError, 'Missing SAMLResponse');
        }

        try {
            $settings = $this->builder->for($provider);
            $auth     = $this->makeAuth($settings);

            // Inject the response into the library's request context. The
            // library normally reads it from $_POST; we have it in $callback
            // so we feed it through the same env var it inspects.
            $_POST['SAMLResponse'] = $samlResponse;
            if (isset($callback['RelayState'])) {
                $_POST['RelayState'] = $callback['RelayState'];
            }

            $auth->processResponse($session['last_request_id'] ?? null);

            $errors = $auth->getErrors();
            if (! empty($errors)) {
                return $this->mapErrorsToOutcome($errors, $auth->getLastErrorReason());
            }

            if (! $auth->isAuthenticated()) {
                return SsoAuthResult::failure(SsoLoginOutcome::ProvidersError, $auth->getLastErrorReason() ?: 'Not authenticated');
            }

            $nameId = (string) $auth->getNameId();
            if ($nameId === '') {
                return SsoAuthResult::failure(SsoLoginOutcome::ClaimMissing, 'NameID absent');
            }

            $claims = $this->flattenAttributes((array) $auth->getAttributes());

            // SAML attribute names vary by IdP — accept the common short forms
            // plus the WS-Federation full URIs Microsoft Entra emits, plus the
            // LDAP-style OIDs older SAMLv2 IdPs use.
            $email = $this->pickClaim($claims, [
                'email', 'emailaddress', 'mail',
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                'urn:oid:0.9.2342.19200300.100.1.3',
            ]);
            $name  = $this->pickClaim($claims, [
                'displayName', 'name', 'givenName', 'cn',
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
                'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
                'urn:oid:2.16.840.1.113730.3.1.241',
                'urn:oid:2.5.4.42',
            ]);

            return SsoAuthResult::ok(
                subject: $nameId,
                email:   is_string($email) ? $email : null,
                name:    is_string($name)  ? $name  : null,
                claims:  $claims,
            );
        } catch (Throwable $e) {
            // Any uncaught library exception means we treat the assertion as
            // untrustworthy — don't leak internals to the user.
            return SsoAuthResult::failure(SsoLoginOutcome::ProvidersError, 'SAML processing failed: '.$e->getMessage());
        } finally {
            unset($_POST['SAMLResponse'], $_POST['RelayState']);
        }
    }

    /**
     * Library factory — overridable in tests via the builder hook. Also sets
     * the Host header to the canonical app URL so the ACS-URL match check
     * passes when running behind a reverse proxy.
     */
    protected function makeAuth(array $settings): OneLoginAuth
    {
        OneLoginUtils::setSelfHost(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        OneLoginUtils::setSelfProtocol(str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http');

        return new OneLoginAuth($settings);
    }

    /**
     * Map onelogin's error codes to our SsoLoginOutcome enum. The library
     * surfaces the cause through `getErrors()` (high-level codes) plus
     * `getLastErrorReason()` (free-text). We branch on substrings of either
     * because the codes were not stable across library versions.
     */
    private function mapErrorsToOutcome(array $errors, ?string $reason): SsoAuthResult
    {
        $needle = strtolower(implode(' ', $errors).' '.($reason ?? ''));

        if (str_contains($needle, 'signature') || str_contains($needle, 'invalid_response_signature') || str_contains($needle, 'invalid_assertion_signature')) {
            return SsoAuthResult::failure(SsoLoginOutcome::SignatureInvalid, $reason ?: 'Signature invalid');
        }
        if (str_contains($needle, 'notonorafter') || str_contains($needle, 'expired') || str_contains($needle, 'session_expired')) {
            return SsoAuthResult::failure(SsoLoginOutcome::AssertionExpired, $reason ?: 'Assertion expired');
        }
        if (str_contains($needle, 'audience') || str_contains($needle, 'audience_restriction')) {
            return SsoAuthResult::failure(SsoLoginOutcome::AudienceMismatch, $reason ?: 'Audience mismatch');
        }

        return SsoAuthResult::failure(SsoLoginOutcome::ProvidersError, $reason ?: implode('; ', $errors));
    }

    /**
     * onelogin returns each attribute as an array (even single-valued ones).
     * Collapse those into scalars so downstream code can read them naturally
     * via `$claims['email']` rather than `$claims['email'][0]`.
     */
    private function flattenAttributes(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $key => $values) {
            $out[$key] = is_array($values) && count($values) === 1 ? $values[0] : $values;
        }
        return $out;
    }

    private function pickClaim(array $claims, array $candidateKeys): mixed
    {
        foreach ($candidateKeys as $k) {
            if ($k !== '' && isset($claims[$k])) return $claims[$k];
        }
        return null;
    }
}
