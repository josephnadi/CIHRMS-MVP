<?php

namespace App\Services\Sso\Contracts;

use App\Models\SsoIdentityProvider;
use App\Services\Sso\SsoAuthResult;

/**
 * Per-protocol SSO adapter.
 *
 *   - `OidcSsoAdapter`: implements the OIDC Authorization Code + PKCE flow.
 *     Works with Microsoft Entra, Azure AD B2C, Auth0, Keycloak, Okta, and
 *     ghana.gov when published as OIDC.
 *
 *   - `SamlSsoAdapter`: SAML 2.0 SP flow — NITA IDM and traditional
 *     government IdPs. Currently a clearly-marked stub; production drop-in
 *     would wrap simplesamlphp/simplesamlphp or onelogin/php-saml.
 */
interface SsoAdapter
{
    /** Returns 'oidc' or 'saml' — matches SsoProviderType. */
    public function type(): string;

    /**
     * Build the URL (and state, where applicable) the user should be sent to
     * for authentication. The session shape is opaque to the caller — store it
     * and re-supply it on callback.
     *
     * @return array{redirect_url:string, session:array}
     */
    public function initiate(SsoIdentityProvider $provider, string $intendedUrl): array;

    /**
     * Validate the IdP's callback (OIDC code+state, or SAML POST assertion)
     * and return either authenticated claims or a structured failure.
     *
     * @param  array<string, mixed> $callback   request input from the IdP
     * @param  array<string, mixed> $session    the session stash returned by initiate()
     */
    public function handleCallback(SsoIdentityProvider $provider, array $callback, array $session): SsoAuthResult;
}
