<?php

namespace App\Services\Sso\Adapters;

use App\Enums\SsoLoginOutcome;
use App\Models\SsoIdentityProvider;
use App\Services\Sso\Contracts\SsoAdapter;
use App\Services\Sso\SsoCallbackResult;
use Illuminate\Http\Request;

/**
 * SAML 2.0 SP adapter — STUB.
 *
 * NITA's IDM is SAML 2.0. A full SAML SP needs metadata parsing, AuthnRequest
 * signing, assertion validation against the IdP's signing cert, and audience
 * restriction checks. For Phase 4 we ship the interface and config schema
 * so the deployment team can wire the real implementation against either:
 *
 *   - composer require simplesamlphp/simplesamlphp + a wrapping service, or
 *   - composer require lightsaml/symfony-bridge for a leaner footprint
 *
 * Provider config (identity_providers.config) expected at integration time:
 *   {
 *     "entity_id":        "https://hrms.example.com",
 *     "acs_url":          "https://hrms.example.com/auth/sso/{slug}/callback",
 *     "idp_metadata_url": "https://idm.nita.gov.gh/metadata",
 *     "idp_sso_url":      "https://idm.nita.gov.gh/sso",
 *     "idp_certificate":  "-----BEGIN CERTIFICATE-----..."
 *   }
 */
class SamlSsoAdapter implements SsoAdapter
{
    public function authorisationUrl(SsoIdentityProvider $provider, string $state): string
    {
        // Real impl: build a SAML AuthnRequest, deflate + base64-encode it,
        // append as SAMLRequest query parameter alongside RelayState=$state.
        // For now redirect to a clear "not configured" page so the path is
        // wired end-to-end and the deployment team can swap in the library.
        $cfg = $provider->config;
        $ssoUrl = (string) ($cfg['idp_sso_url'] ?? '');

        return $ssoUrl !== ''
            ? "{$ssoUrl}?SAMLRequest=NOT_IMPLEMENTED&RelayState=" . urlencode($state)
            : route('login') . '?sso_error=saml_not_configured';
    }

    public function handleCallback(SsoIdentityProvider $provider, Request $request, string $expectedState): SsoCallbackResult
    {
        return SsoCallbackResult::fail(
            SsoLoginOutcome::ProvidersError,
            'SAML adapter is a stub. Install simplesamlphp/simplesamlphp or lightsaml/symfony-bridge and wire its assertion-validation here.',
        );
    }
}
