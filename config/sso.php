<?php

/*
|--------------------------------------------------------------------------
| SSO defaults
|--------------------------------------------------------------------------
|
| Per-protocol defaults that the SAML config builder layers underneath each
| IdP row's `config` JSON. The builder lives at
| `App\Services\Sso\SamlConfigBuilder` and translates these defaults plus the
| per-provider row into the array shape `OneLogin\Saml2\Auth` expects.
|
| Strict-mode is enforced — that's what makes the library reject responses
| whose signatures don't validate. Don't disable it.
|
*/

return [

    'saml' => [
        /*
         * Service provider — *us*. The entity_id and ACS URL are derived from
         * app.url at request time so a single config works across dev, stage,
         * and prod without env-specific overrides.
         */
        'sp_signing_algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'sp_digest_algorithm'  => 'http://www.w3.org/2001/04/xmlenc#sha256',
        'sp_nameid_format'     => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',

        /*
         * Strict mode rejects responses missing required envelope fields and
         * rejects signatures that don't validate. Audit-grade requirement.
         */
        'strict' => true,

        'security' => [
            'authnRequestsSigned'      => false, // flip on once we mint an SP cert per provider
            'wantAssertionsSigned'     => true,
            'wantAssertionsEncrypted'  => false,
            'wantNameId'               => true,
            'wantNameIdEncrypted'      => false,
            'wantMessagesSigned'       => false,
            'signMetadata'             => false,
            'requestedAuthnContext'    => false,
            'signatureAlgorithm'       => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            'digestAlgorithm'          => 'http://www.w3.org/2001/04/xmlenc#sha256',
            'rejectUnsolicitedResponsesWithInResponseTo' => true,
        ],
    ],

];
