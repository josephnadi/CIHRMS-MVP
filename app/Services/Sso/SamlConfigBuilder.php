<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\SsoIdentityProvider;
use DomainException;

/**
 * Translates a `SsoIdentityProvider` row + `config/sso.php` defaults into the
 * settings array `OneLogin\Saml2\Auth` expects.
 *
 * The provider's `config` JSON (encrypted) must contain at minimum:
 *   - entity_id     : IdP's SAML EntityID
 *   - sso_url       : IdP's SingleSignOnService endpoint (HTTP-Redirect or POST)
 *   - idp_x509cert  : PEM-encoded public certificate of the IdP signing key
 *
 * Optional:
 *   - slo_url       : SingleLogoutService endpoint (defer until we wire SLO)
 *   - sp_private_key, sp_x509cert : SP signing keypair (only needed once we
 *                                   flip `authnRequestsSigned` on)
 *
 * The ACS URL is always computed from the route binding — no per-provider
 * override — so a tenant can never accidentally redirect assertions away
 * from our endpoint.
 */
class SamlConfigBuilder
{
    public function for(SsoIdentityProvider $provider): array
    {
        $cfg = $provider->config ?? [];

        foreach (['entity_id', 'sso_url', 'idp_x509cert'] as $required) {
            if (empty($cfg[$required])) {
                throw new DomainException("SAML provider {$provider->slug} missing required config key: {$required}");
            }
        }

        $defaults = (array) config('sso.saml');

        $spEntityId = rtrim((string) config('app.url'), '/');
        $acsUrl     = url("/auth/sso/{$provider->slug}/callback");

        return [
            'strict'   => (bool) ($defaults['strict'] ?? true),
            'debug'    => false,
            'baseurl'  => $spEntityId,

            'sp' => [
                'entityId'                 => $spEntityId,
                'assertionConsumerService' => [
                    'url'     => $acsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => $defaults['sp_nameid_format']
                    ?? 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                'x509cert'   => (string) ($cfg['sp_x509cert']  ?? ''),
                'privateKey' => (string) ($cfg['sp_private_key'] ?? ''),
            ],

            'idp' => [
                'entityId'            => (string) $cfg['entity_id'],
                'singleSignOnService' => [
                    'url'     => (string) $cfg['sso_url'],
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => ! empty($cfg['slo_url']) ? [
                    'url'     => (string) $cfg['slo_url'],
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ] : null,
                'x509cert' => (string) $cfg['idp_x509cert'],
            ],

            'security' => (array) ($defaults['security'] ?? []),
        ];
    }
}
