<?php

namespace App\Enums;

enum SsoLoginOutcome: string
{
    case Success          = 'success';
    case ProvidersError   = 'provider_error';    // upstream IdP rejected / returned bad assertion
    case InvalidState     = 'invalid_state';     // CSRF / state mismatch
    case ClaimMissing     = 'claim_missing';     // required claim absent
    case UserDisabled     = 'user_disabled';     // matched user is deleted / disabled
    case ProvisionFailed  = 'provision_failed';  // JIT provisioning blocked

    // SAML signature-verification failures — only emitted by SamlSsoAdapter
    // once the onelogin/php-saml library is wired in. Kept distinct from
    // ProvidersError so the audit log shows *why* an assertion was rejected.
    case SignatureInvalid  = 'signature_invalid';
    case AssertionExpired  = 'assertion_expired';
    case AudienceMismatch  = 'audience_mismatch';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
