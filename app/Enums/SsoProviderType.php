<?php

namespace App\Enums;

enum SsoProviderType: string
{
    case Oidc = 'oidc';   // OpenID Connect — Microsoft Entra, Azure AD, Auth0, Keycloak, ghana.gov (when OIDC)
    case Saml = 'saml';   // SAML 2.0 — NITA IDM, traditional government IdPs

    public function label(): string
    {
        return match ($this) {
            self::Oidc => 'OpenID Connect',
            self::Saml => 'SAML 2.0',
        };
    }
}
