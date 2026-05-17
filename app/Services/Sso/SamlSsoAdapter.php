<?php

namespace App\Services\Sso;

use App\Enums\SsoLoginOutcome;
use App\Models\SsoIdentityProvider;
use App\Services\Sso\Contracts\SsoAdapter;
use Illuminate\Support\Str;

/**
 * SAML 2.0 SP adapter (production-grade scaffolding — wire to a SAML library
 * in deployment).
 *
 * Why a stub: a correct SAML implementation needs XML-DSIG signature
 * verification, NameID format negotiation, AssertionConsumerService binding,
 * encrypted-assertion decryption and IdP metadata discovery — all of which
 * are best done by a mature library (`simplesamlphp/simplesamlphp` or
 * `onelogin/php-saml`) rather than re-implemented here.
 *
 * This adapter:
 *   - Generates an AuthnRequest URL (HTTP-Redirect binding) with relay state.
 *   - Accepts a POSTed SAML response and pulls the NameID and Attributes.
 *   - Does NOT verify the XML signature in this stub — TODO before prod.
 *
 * Replace the body of `handleCallback()` with `OneLogin\Saml2\Auth::processResponse()`
 * (or equivalent) when wiring the chosen library.
 */
class SamlSsoAdapter implements SsoAdapter
{
    public function type(): string
    {
        return 'saml';
    }

    public function initiate(SsoIdentityProvider $provider, string $intendedUrl): array
    {
        $cfg = $provider->config ?? [];
        $relayState = Str::random(40);

        // Minimal AuthnRequest URL — real SAML libs build & deflate-base64 the
        // XML and append it to the IdP SSO URL.
        $authnRequest = $this->buildAuthnRequest($cfg['entity_id'] ?? config('app.url'), $this->acsUrl($provider));
        $samlRequest  = base64_encode(gzdeflate($authnRequest));

        $params = http_build_query([
            'SAMLRequest' => $samlRequest,
            'RelayState'  => $relayState,
        ]);

        return [
            'redirect_url' => ($cfg['sso_url'] ?? '') . '?' . $params,
            'session'      => [
                'relay_state' => $relayState,
                'intended'    => $intendedUrl,
            ],
        ];
    }

    public function handleCallback(SsoIdentityProvider $provider, array $callback, array $session): SsoAuthResult
    {
        // TODO production: replace with onelogin/php-saml or simplesamlphp parse + signature verify.
        $samlResponseB64 = (string) ($callback['SAMLResponse'] ?? '');
        $relayState      = (string) ($callback['RelayState']  ?? '');

        if ($samlResponseB64 === '') {
            return SsoAuthResult::failure(SsoLoginOutcome::ProvidersError, 'Missing SAMLResponse');
        }
        if (($session['relay_state'] ?? null) !== $relayState) {
            return SsoAuthResult::failure(SsoLoginOutcome::InvalidState, 'RelayState mismatch');
        }

        $xml = base64_decode($samlResponseB64);
        if ($xml === false) {
            return SsoAuthResult::failure(SsoLoginOutcome::ProvidersError, 'Cannot decode SAMLResponse');
        }

        // ⚠️ Stub-only parsing: extracts NameID + Attribute statements WITHOUT
        // signature verification. Replace before any production use.
        $doc = new \DOMDocument();
        if (! @$doc->loadXML($xml)) {
            return SsoAuthResult::failure(SsoLoginOutcome::ProvidersError, 'Malformed SAML XML');
        }

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

        $nameId = $xp->evaluate('string(//saml:Subject/saml:NameID)');
        if ($nameId === '') {
            return SsoAuthResult::failure(SsoLoginOutcome::ClaimMissing, 'NameID absent');
        }

        $claims = [];
        foreach ($xp->query('//saml:Attribute') ?: [] as $attr) {
            $name = $attr->getAttribute('Name');
            $vals = [];
            foreach ($xp->query('./saml:AttributeValue', $attr) ?: [] as $v) {
                $vals[] = trim($v->textContent);
            }
            $claims[$name] = count($vals) === 1 ? $vals[0] : $vals;
        }

        $email = $claims['email'] ?? $claims['emailaddress'] ?? null;
        $name  = $claims['displayName'] ?? $claims['name'] ?? $claims['givenName'] ?? null;

        return SsoAuthResult::ok(
            subject: $nameId,
            email:   is_string($email) ? $email : null,
            name:    is_string($name)  ? $name  : null,
            claims:  $claims,
        );
    }

    private function buildAuthnRequest(string $entityId, string $acsUrl): string
    {
        $id     = '_' . bin2hex(random_bytes(16));
        $issued = gmdate('Y-m-d\TH:i:s\Z');
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<samlp:AuthnRequest
    xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="{$id}" Version="2.0" IssueInstant="{$issued}"
    ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
    AssertionConsumerServiceURL="{$acsUrl}">
    <saml:Issuer>{$entityId}</saml:Issuer>
</samlp:AuthnRequest>
XML;
    }

    private function acsUrl(SsoIdentityProvider $provider): string
    {
        return url('/auth/sso/' . $provider->slug . '/callback');
    }
}
